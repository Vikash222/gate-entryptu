<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    protected User $studentUser;
    protected Student $student;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $gate = Gate::create([
            'name' => 'Gate 1',
            'code' => 'GATE-1',
            'status' => 'ACTIVE',
        ]);

        $this->studentUser = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'student_id' => 'STU-99001',
            'roll_number' => 'ROLL-99001',
            'name' => 'Verified Student',
            'year' => 3,
            'email' => $this->studentUser->email,
            'phone_number' => '555-0199',
            'program' => 'B.Tech',
            'department' => 'Computer Engineering',
            'semester' => 5,
            'batch' => '2023-2027',
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_INSIDE,
            'last_gate_id' => $gate->id,
            'last_movement_at' => now('Asia/Kolkata'),
        ]);

        $this->token = $this->studentUser->createToken('test_auth')->plainTextToken;
    }

    public function test_student_can_fetch_profile(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/student/profile');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'student_id' => 'STU-99001',
                    'roll_number' => 'ROLL-99001',
                    'name' => 'Verified Student',
                    'current_status' => 'INSIDE',
                    'department' => 'Computer Engineering',
                ],
            ]);
    }

    public function test_student_can_fetch_current_status(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/student/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'current_status' => 'INSIDE',
                    'is_inside' => true,
                    'is_outside' => false,
                ],
            ])
            ->assertJsonStructure(['data' => ['server_time']]);
    }

    public function test_student_cannot_access_movement_history(): void
    {
        // Per strict requirement: Student movement history endpoint is removed completely
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/student/history');

        $response->assertStatus(404);
    }
}
