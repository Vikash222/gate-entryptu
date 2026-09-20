<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'active', 'role:ADMIN'])->get('/api/v1/test/admin-only', function () {
            return response()->json(['success' => true, 'message' => 'Admin authorized']);
        });

        Route::middleware(['auth:sanctum', 'active', 'role:SECURITY'])->get('/api/v1/test/security-only', function () {
            return response()->json(['success' => true, 'message' => 'Security authorized']);
        });

        Route::middleware(['auth:sanctum', 'active', 'role:STUDENT'])->get('/api/v1/test/student-only', function () {
            return response()->json(['success' => true, 'message' => 'Student authorized']);
        });
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/test/admin-only');
        $response->assertStatus(401);
    }

    public function test_student_cannot_access_security_or_admin(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE]);
        $token = $student->createToken('auth')->plainTextToken;

        $responseSecurity = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/test/security-only');
        $responseSecurity->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Access denied. Insufficient role permissions.',
            ]);

        $responseAdmin = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/test/admin-only');
        $responseAdmin->assertStatus(403);
    }

    public function test_security_cannot_access_admin(): void
    {
        $security = User::factory()->create(['role' => User::ROLE_SECURITY, 'status' => User::STATUS_ACTIVE]);
        $token = $security->createToken('auth')->plainTextToken;

        $responseAdmin = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/test/admin-only');
        $responseAdmin->assertStatus(403);
    }

    public function test_admin_can_access_admin_route(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/test/admin-only');
        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Admin authorized']);
    }
}
