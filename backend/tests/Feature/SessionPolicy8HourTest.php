<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SessionPolicy8HourTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected User $studentUser;
    protected Student $student;
    protected Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = Gate::create([
            'name' => 'Main Gate',
            'code' => 'GATE-1',
            'location' => 'Main Entrance',
            'status' => 'ACTIVE',
        ]);

        $this->adminUser = User::create([
            'name' => 'Campus Admin',
            'email' => 'admin@ptu.ac.in',
            'password' => bcrypt('Password123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->guardUser = User::create([
            'name' => 'Security Guard 1',
            'email' => 'guard@ptu.ac.in',
            'password' => bcrypt('Password123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->studentUser = User::create([
            'name' => 'Test Student',
            'email' => 'student@ptu.ac.in',
            'password' => bcrypt('Password123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'student_id' => 'STU-88001',
            'roll_number' => 'ROLL-88001',
            'name' => 'Test Student',
            'email' => $this->studentUser->email,
            'phone_number' => '9876543210',
            'program' => 'B.Tech',
            'department' => 'CSE',
            'batch' => '2023-2027',
            'year' => 2,
            'semester' => 4,
            'category' => Student::CATEGORY_HOSTELLER,
            'status' => Student::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 1. Student login sets 8-hour token expiration.
     */
    public function test_1_student_login_sets_8_hour_token_expiration()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertNotNull($pat->expires_at);

        $expectedExpiry = $loginTime->copy()->addHours(8);
        $this->assertEquals($expectedExpiry->timestamp, $pat->expires_at->timestamp);
    }

    /**
     * 2. Security login sets 8-hour token expiration.
     */
    public function test_2_security_login_sets_8_hour_token_expiration()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $token = $response->json('data.token');
        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertNotNull($pat->expires_at);

        $expectedExpiry = $loginTime->copy()->addHours(8);
        $this->assertEquals($expectedExpiry->timestamp, $pat->expires_at->timestamp);
    }

    /**
     * 3. Admin login does not set 8-hour token expiration (expires_at is null).
     */
    public function test_3_admin_login_does_not_set_8_hour_token_expiration()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ptu.ac.in',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.session_expires_at'));
        $this->assertNull($response->json('data.session_duration_seconds'));

        $token = $response->json('data.token');
        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertNull($pat->expires_at);
    }

    /**
     * 4. Student token is valid at 7 hours 59 minutes.
     */
    public function test_4_student_token_is_valid_at_7_hours_59_minutes()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Fast-forward to 7 hours 59 minutes later
        Carbon::setTestNow($loginTime->copy()->addHours(7)->addMinutes(59));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.email', 'student@ptu.ac.in');
    }

    /**
     * 5. Security token is valid at 7 hours 59 minutes.
     */
    public function test_5_security_token_is_valid_at_7_hours_59_minutes()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Fast-forward to 7 hours 59 minutes later
        Carbon::setTestNow($loginTime->copy()->addHours(7)->addMinutes(59));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.email', 'guard@ptu.ac.in');
    }

    /**
     * 6. Student token is rejected at 8 hours 1 minute (HTTP 401).
     */
    public function test_6_student_token_is_rejected_at_8_hours_1_minute()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Fast-forward to 8 hours 1 minute later
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinute());

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /**
     * 7. Security token is rejected at 8 hours 1 minute (HTTP 401).
     */
    public function test_7_security_token_is_rejected_at_8_hours_1_minute()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Fast-forward to 8 hours 1 minute later
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinute());

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /**
     * 8. Security duty session is automatically ended when guard token expires.
     */
    public function test_8_security_duty_session_is_ended_when_guard_token_expires()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Guard starts active duty
        $dutySession = SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'started_at' => $loginTime,
        ]);
        $this->assertEquals(SecurityDutySession::STATUS_ACTIVE, $dutySession->status);

        // Fast-forward past 8 hours
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinutes(5));

        // Guard attempts any request
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(401);

        // Check that duty session was ended
        $dutySession->refresh();
        $this->assertEquals(SecurityDutySession::STATUS_ENDED, $dutySession->status);
        $this->assertNotNull($dutySession->ended_at);
    }

    /**
     * 9. Guard with ended duty session but valid auth can still access auth endpoints (/auth/me).
     */
    public function test_9_guard_with_ended_duty_session_can_access_auth_endpoints()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Create an ended duty session
        SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'status' => SecurityDutySession::STATUS_ENDED,
            'started_at' => $loginTime,
            'ended_at' => $loginTime->copy()->addHour(),
        ]);

        // At 2 hours, auth is still valid (6 hours left)
        Carbon::setTestNow($loginTime->copy()->addHours(2));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.email', 'guard@ptu.ac.in');
    }

    /**
     * 10. Guard with ended duty session cannot access operational endpoints (duty.active -> 403).
     */
    public function test_10_guard_with_ended_duty_session_cannot_access_operational_endpoints()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // At 2 hours, without an active duty session, operational endpoints return 403
        Carbon::setTestNow($loginTime->copy()->addHours(2));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/security/today');

        $response->assertStatus(403);
        $this->assertEquals('NO_ACTIVE_DUTY_SESSION', $response->json('errors.code'));
    }

    /**
     * 11. Refreshing token before 8 hours preserves original expires_at and does not extend beyond 8 hours.
     */
    public function test_11_refreshing_token_preserves_original_expires_at()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');
        $expectedOriginalExpiry = $loginTime->copy()->addHours(8);

        // Advance 4 hours
        Carbon::setTestNow($loginTime->copy()->addHours(4));

        $refreshRes = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/refresh');

        $refreshRes->assertStatus(200);
        $newToken = $refreshRes->json('data.token');
        $this->assertNotEquals($token, $newToken);

        $newPat = PersonalAccessToken::findToken($newToken);
        $this->assertNotNull($newPat);
        // Expiration MUST still equal original login time + 8 hours
        $this->assertEquals($expectedOriginalExpiry->timestamp, $newPat->expires_at->timestamp);

        // Advance to 8 hours 1 minute after original login
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinute());

        // New token must be rejected because original 8 hours expired
        $expiredRes = $this->withHeader('Authorization', "Bearer $newToken")
            ->getJson('/api/v1/auth/me');

        $expiredRes->assertStatus(401);
    }

    /**
     * 12. Refreshing token after 8 hours is rejected with 401.
     */
    public function test_12_refreshing_token_after_8_hours_is_rejected()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Advance to 8 hours 1 minute
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinute());

        $refreshRes = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/refresh');

        $refreshRes->assertStatus(401);
        $refreshRes->assertJsonPath('message', 'Your 8-hour session has expired. Please sign in again.');
    }

    /**
     * 13. Admin token remains valid after 9 hours (no 8-hour restriction).
     */
    public function test_13_admin_token_remains_valid_after_9_hours()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Advance 9 hours
        Carbon::setTestNow($loginTime->copy()->addHours(9));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.email', 'admin@ptu.ac.in');
    }

    /**
     * 14. Explicit logout revokes token and ends duty session immediately.
     */
    public function test_14_explicit_logout_revokes_token_and_ends_duty_session()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        $dutySession = SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'started_at' => $loginTime,
        ]);

        $logoutRes = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/logout');

        $logoutRes->assertStatus(200);

        // Token should be deleted
        $this->assertNull(PersonalAccessToken::findToken($token));

        // Duty session should be ended
        $dutySession->refresh();
        $this->assertEquals(SecurityDutySession::STATUS_ENDED, $dutySession->status);
    }

    /**
     * 15. Student login response structure contains session metadata.
     */
    public function test_15_student_login_response_structure()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user',
                    'session_started_at',
                    'session_expires_at',
                    'session_duration_seconds',
                ],
            ]);

        $this->assertEquals(28800, $response->json('data.session_duration_seconds'));
        $this->assertEquals($loginTime->toIso8601String(), $response->json('data.session_started_at'));
        $this->assertEquals($loginTime->copy()->addHours(8)->toIso8601String(), $response->json('data.session_expires_at'));
    }

    /**
     * 16. Security login response structure contains session metadata.
     */
    public function test_16_security_login_response_structure()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user',
                    'session_started_at',
                    'session_expires_at',
                    'session_duration_seconds',
                ],
            ]);

        $this->assertEquals(28800, $response->json('data.session_duration_seconds'));
    }

    /**
     * 17. Student 2FA verification sets 8-hour token expiration.
     */
    public function test_17_student_2fa_verification_sets_8_hour_token_expiration()
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->studentUser->update([
            'totp_secret' => $secret,
            'totp_enabled' => true,
        ]);

        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        // 1. Initial login triggers 2FA
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $loginRes->assertStatus(200);
        $this->assertTrue($loginRes->json('data.requires_2fa'));
        $challengeToken = $loginRes->json('data.challenge_token');

        // 2. Verify 2FA
        $code = $google2fa->getCurrentOtp($secret);
        $verifyRes = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ]);

        $verifyRes->assertStatus(200);
        $token = $verifyRes->json('data.token');
        $this->assertNotEmpty($token);

        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertEquals($loginTime->copy()->addHours(8)->timestamp, $pat->expires_at->timestamp);
    }

    /**
     * 18. Security 2FA verification sets 8-hour token expiration.
     */
    public function test_18_security_2fa_verification_sets_8_hour_token_expiration()
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->guardUser->update([
            'totp_secret' => $secret,
            'totp_enabled' => true,
        ]);

        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $challengeToken = $loginRes->json('data.challenge_token');

        $code = $google2fa->getCurrentOtp($secret);
        $verifyRes = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ]);

        $verifyRes->assertStatus(200);
        $token = $verifyRes->json('data.token');
        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertEquals($loginTime->copy()->addHours(8)->timestamp, $pat->expires_at->timestamp);
    }

    /**
     * 19. Student endpoint access with expired token fails with 401.
     */
    public function test_19_student_endpoint_access_with_expired_token_fails_with_401()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Advance 8 hours 10 minutes
        Carbon::setTestNow($loginTime->copy()->addHours(8)->addMinutes(10));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/student/profile');

        $response->assertStatus(401);
    }

    /**
     * 20. Inactive student/guard is rejected even if within 8-hour window.
     */
    public function test_20_inactive_student_is_rejected_even_within_8_hour_window()
    {
        $loginTime = Carbon::parse('2026-09-21 08:00:00', 'Asia/Kolkata');
        Carbon::setTestNow($loginTime);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@ptu.ac.in',
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        // Advance 2 hours, but suspend student account
        Carbon::setTestNow($loginTime->copy()->addHours(2));
        $this->studentUser->update(['status' => User::STATUS_SUSPENDED]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/student/profile');

        $response->assertStatus(403);
    }
}
