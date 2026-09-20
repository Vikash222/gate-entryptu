<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\GateSession;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use App\Services\QrTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class GateMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $studentUser;
    protected Student $student;
    protected User $securityUser;
    protected Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = Gate::create([
            'name' => 'Gate 1 (Main Entrance)',
            'code' => 'GATE-1',
            'location' => 'North Perimeter',
            'is_active' => true,
        ]);

        $this->studentUser = User::create([
            'name' => 'Test Student',
            'email' => 'student.test@ptu.ac.in',
            'password' => bcrypt('StudentPass123!'),
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);

        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'student_id' => 'STU-1001',
            'name' => 'Test Student',
            'roll_number' => '2026/CS/001',
            'email' => 'student.test@ptu.ac.in',
            'phone_number' => '+919876543210',
            'department' => 'Computer Science',
            'current_status' => 'INSIDE',
            'is_active' => true,
        ]);

        $this->securityUser = User::create([
            'name' => 'Test Guard',
            'email' => 'guard.test@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => 'SECURITY',
            'status' => 'ACTIVE',
        ]);

        SecurityDutySession::create([
            'user_id' => $this->securityUser->id,
            'gate_id' => $this->gate->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);
    }

    public function test_qr_generation_and_verification_within_30_seconds(): void
    {
        $qrService = app(QrTokenService::class);
        $tokenData = $qrService->generateToken($this->gate);

        $payload = $qrService->verifyToken($tokenData['qr_payload']);

        $this->assertIsArray($payload);
        $this->assertEquals($this->gate->id, $payload['gate']->id);
    }

    public function test_tampered_qr_signature_is_rejected(): void
    {
        $qrService = app(QrTokenService::class);
        $tokenData = $qrService->generateToken($this->gate);

        $decoded = json_decode($tokenData['qr_payload'], true);
        $decoded['signature'] = 'tampered_signature';

        $this->expectException(InvalidArgumentException::class);
        $qrService->verifyToken(json_encode($decoded));
    }

    public function test_expired_qr_token_is_rejected(): void
    {
        $qrService = app(QrTokenService::class);
        $tokenData = $qrService->generateToken($this->gate);

        // Travel 40 seconds into the future
        Carbon::setTestNow(Carbon::now('Asia/Kolkata')->addSeconds(40));

        $this->expectException(InvalidArgumentException::class);
        try {
            $qrService->verifyToken($tokenData['qr_payload']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_max_4_concurrent_security_duty_sessions_enforced(): void
    {
        SecurityDutySession::query()->delete();
        $dutyService = app(DutySessionService::class);

        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin.otp@ptu.ac.in',
            'password' => bcrypt('password'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $user = User::create([
                'name' => "Guard {$i}",
                'email' => "guard{$i}@ptu.ac.in",
                'password' => bcrypt('password'),
                'role' => 'SECURITY',
                'status' => 'ACTIVE',
            ]);
            $otpData = $dutyService->generateAdminOtp($admin, $this->gate->id);
            $dutyService->activateDuty($user, $this->gate->id, $otpData['otp']);
        }

        $this->assertEquals(4, SecurityDutySession::where('status', 'ACTIVE')->count());

        // 5th attempt must throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $user5 = User::create([
            'name' => "Guard 5",
            'email' => "guard5@ptu.ac.in",
            'password' => bcrypt('password'),
            'role' => 'SECURITY',
            'status' => 'ACTIVE',
        ]);
        $otpData5 = $dutyService->generateAdminOtp($admin, $this->gate->id);
        $dutyService->activateDuty($user5, $this->gate->id, $otpData5['otp']);
    }

    public function test_student_cannot_record_out_movement_when_already_outside(): void
    {
        $this->student->update(['current_status' => 'OUTSIDE']);

        // Create gate session
        $gateSession = GateSession::create([
            'session_token' => 'sess_' . bin2hex(random_bytes(16)),
            'user_id' => $this->studentUser->id,
            'student_id' => $this->student->id,
            'gate_id' => $this->gate->id,
            'status' => 'PENDING',
            'expires_at' => Carbon::now('Asia/Kolkata')->addMinutes(3),
        ]);

        $response = $this->actingAs($this->studentUser)
            ->postJson('/api/v1/gate-entry/out', [
                'gate_session_token' => $gateSession->session_token,
                'client_request_id' => 'req-12345',
                'destination' => 'Market',
                'purpose' => 'Personal',
                'vehicle_present' => false,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_student_can_record_out_movement_when_inside(): void
    {
        $gateSession = GateSession::create([
            'session_token' => 'sess_' . bin2hex(random_bytes(16)),
            'user_id' => $this->studentUser->id,
            'student_id' => $this->student->id,
            'gate_id' => $this->gate->id,
            'status' => 'PENDING',
            'expires_at' => Carbon::now('Asia/Kolkata')->addMinutes(3),
        ]);

        $response = $this->actingAs($this->studentUser)
            ->postJson('/api/v1/gate-entry/out', [
                'gate_session_token' => $gateSession->session_token,
                'client_request_id' => 'req-out-1',
                'destination' => 'Library',
                'purpose' => 'Study',
                'vehicle_present' => false,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('students', [
            'id' => $this->student->id,
            'current_status' => 'OUTSIDE',
        ]);

        $this->assertDatabaseHas('movements', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Library',
        ]);
    }

    public function test_student_history_route_is_completely_inaccessible(): void
    {
        $response = $this->actingAs($this->studentUser)
            ->getJson('/api/v1/student/history');

        // Route does not exist (404)
        $response->assertStatus(404);
    }
}
