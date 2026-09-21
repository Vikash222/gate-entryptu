<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_refresh_issues_new_token_and_revokes_old()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $token = $user->createToken('smartgate_auth')->plainTextToken;

        $response = $this->postJson('/api/v1/auth/refresh', [], [
            'Authorization' => "Bearer $token"
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data' => ['token', 'user']]);

        $newToken = $response->json('data.token');
        $this->assertNotEquals($token, $newToken);

        // Old token should be deleted
        $this->assertNull(PersonalAccessToken::findToken($token));
        
        // New token should exist
        $this->assertNotNull(PersonalAccessToken::findToken($newToken));
    }

    public function test_token_refresh_fails_with_invalid_token()
    {
        $response = $this->postJson('/api/v1/auth/refresh', [], [
            'Authorization' => "Bearer invalid-token"
        ]);

        $response->assertStatus(401);
    }
}
