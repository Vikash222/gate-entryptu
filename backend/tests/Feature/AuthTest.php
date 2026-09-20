<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected TotpService $totpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totpService = app(TotpService::class);
    }

    public function test_login_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_invalid_credentials_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@test.local',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid email address or password.',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'FAILED_LOGIN',
            'status' => 'FAILED',
        ]);
    }

    public function test_valid_login_returns_token_and_user_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'officer@test.local',
            'password' => Hash::make('ValidPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
            'totp_enabled' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'officer@test.local',
            'password' => 'ValidPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'requires_2fa' => false,
                    'user' => [
                        'id' => $user->id,
                        'email' => 'officer@test.local',
                        'role' => User::ROLE_SECURITY,
                    ],
                ],
            ])
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'LOGIN',
            'status' => 'SUCCESS',
        ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@test.local',
            'password' => Hash::make('ValidPass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_INACTIVE,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@test.local',
            'password' => 'ValidPass123!',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_2fa_setup_and_enable_flow(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'totp_enabled' => false,
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        // Step 1: Request 2FA setup
        $setupResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/2fa/setup');

        $setupResponse->assertStatus(200)
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url']]);

        $secret = $setupResponse->json('data.secret');
        $this->assertNotEmpty($secret);

        // Step 2: Generate valid code using the secret
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        // Step 3: Enable 2FA with the valid code
        $enableResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/2fa/enable', [
                'code' => $validCode,
            ]);

        $enableResponse->assertStatus(200)
            ->assertJsonStructure(['data' => ['recovery_codes']]);

        $freshUser = $user->fresh();
        $this->assertTrue($freshUser->totp_enabled);
        $this->assertCount(8, $freshUser->totp_recovery_codes);
    }

    public function test_login_with_2fa_challenge_and_verification(): void
    {
        $secret = $this->totpService->generateSecret();
        $recoveryCodes = $this->totpService->generateRecoveryCodes(8);

        $user = User::factory()->create([
            'email' => 'admin2fa@test.local',
            'password' => Hash::make('AdminPass123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_recovery_codes' => $recoveryCodes,
        ]);

        // Step 1: Login should yield a 2FA challenge
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin2fa@test.local',
            'password' => 'AdminPass123!',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'requires_2fa' => true,
                ],
            ])
            ->assertJsonStructure(['data' => ['challenge_token']]);

        $challengeToken = $loginResponse->json('data.challenge_token');

        // Step 2: Verify with TOTP code
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        $verifyResponse = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ]);

        $verifyResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'requires_2fa' => false,
                ],
            ])
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_recovery_code_burns_code(): void
    {
        $secret = $this->totpService->generateSecret();
        $recoveryCodes = ['RECOVER-01', 'RECOVER-02', 'RECOVER-03'];

        $user = User::factory()->create([
            'email' => 'recovery@test.local',
            'password' => Hash::make('AdminPass123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_recovery_codes' => $recoveryCodes,
        ]);

        // Login challenge
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'recovery@test.local',
            'password' => 'AdminPass123!',
        ]);

        $challengeToken = $loginResponse->json('data.challenge_token');

        // Verify with recovery code
        $verifyResponse = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_token' => $challengeToken,
            'recovery_code' => 'RECOVER-01',
        ]);

        $verifyResponse->assertStatus(200);

        // Code RECOVER-01 should now be burned
        $freshUser = $user->fresh();
        $this->assertNotContains('RECOVER-01', $freshUser->totp_recovery_codes);
        $this->assertCount(2, $freshUser->totp_recovery_codes);
    }
}
