<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\GateSession;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityManualMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser1;
    protected User $guardUser2;
    protected User $offDutyGuard;
    protected Gate $gate1;
    protected Gate $gate2;
    protected SecurityDutySession $dutySession1;
    protected SecurityDutySession $dutySession2;
    protected User $studentUser1;
    protected Student $student1;
    protected User $studentUser2;
    protected Student $student2;
    protected MovementService $movementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movementService = app(MovementService::class);

        // 1. Admin
        $this->adminUser = User::create([
            'name' => 'Campus Admin',
            'email' => 'admin@ptu.ac.in',
            'password' => bcrypt('AdminPass123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        // 2. Gates
        $this->gate1 = Gate::create([
            'name' => 'Gate 1 (Main Entrance)',
            'code' => 'GATE-1',
            'status' => 'ACTIVE',
        ]);

        $this->gate2 = Gate::create([
            'name' => 'Gate 2 (Hostel Exit)',
            'code' => 'GATE-2',
            'status' => 'ACTIVE',
        ]);

        // 3. Guard 1 at Gate 1
        $this->guardUser1 = User::create([
            'name' => 'Officer Ram',
            'email' => 'ram@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->dutySession1 = SecurityDutySession::create([
            'user_id' => $this->guardUser1->id,
            'gate_id' => $this->gate1->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);

        // 4. Guard 2 at Gate 2
        $this->guardUser2 = User::create([
            'name' => 'Officer Shyam',
            'email' => 'shyam@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->dutySession2 = SecurityDutySession::create([
            'user_id' => $this->guardUser2->id,
            'gate_id' => $this->gate2->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);

        // 5. Off Duty Guard
        $this->offDutyGuard = User::create([
            'name' => 'Officer OffDuty',
            'email' => 'offduty@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        // 6. Active Student 1 (Inside campus)
        $this->studentUser1 = User::create([
            'name' => 'Rahul Kumar',
            'email' => 'rahul@ptu.ac.in',
            'password' => bcrypt('StudentPass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->student1 = Student::create([
            'user_id' => $this->studentUser1->id,
            'student_id' => 'STU-001',
            'roll_number' => '23CSE101',
            'name' => 'Rahul Kumar',
            'year' => 2,
            'program' => 'B.Tech CSE',
            'email' => 'rahul@ptu.ac.in',
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_INSIDE,
        ]);

        // 7. Active Student 2 (Outside campus)
        $this->studentUser2 = User::create([
            'name' => 'Priya Sharma',
            'email' => 'priya@ptu.ac.in',
            'password' => bcrypt('StudentPass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->student2 = Student::create([
            'user_id' => $this->studentUser2->id,
            'student_id' => 'STU-002',
            'roll_number' => '23CSE102',
            'name' => 'Priya Sharma',
            'year' => 3,
            'program' => 'B.Tech IT',
            'email' => 'priya@ptu.ac.in',
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_OUTSIDE,
        ]);
    }

    protected function guardToken(User $guard): string
    {
        return $guard->createToken('guard_token')->plainTextToken;
    }

    /**
     * TEST 1: Search active student by Roll Number.
     */
    public function test_01_security_can_search_active_student_by_roll_number(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/students/search?roll_number=23CSE101');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('23CSE101', $data[0]['roll_number']);
        $this->assertEquals('Rahul Kumar', $data[0]['name']);
        $this->assertEquals(Student::STATE_INSIDE, $data[0]['current_status']);
    }

    /**
     * TEST 2: Exact Roll Number match is prioritized first in results.
     */
    public function test_02_search_exact_roll_number_is_prioritized(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/students/search?query=23CSE101');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('23CSE101', $data[0]['roll_number']);
    }

    /**
     * TEST 3: Search by Roll Number prefix works.
     */
    public function test_03_search_roll_number_prefix_works(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/students/search?query=23CSE');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    /**
     * TEST 4: Successful manual OUT for an INSIDE student.
     */
    public function test_04_successful_manual_out(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Jalandhar',
                'purpose' => 'Personal work',
                'vehicle_present' => true,
                'vehicle_number' => 'PB08AB1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.movement_type', 'OUT')
            ->assertJsonPath('data.movement_source', Movement::SOURCE_SECURITY_MANUAL)
            ->assertJsonPath('data.destination', 'Jalandhar')
            ->assertJsonPath('data.purpose', 'Personal work')
            ->assertJsonPath('data.vehicle_present', true)
            ->assertJsonPath('data.vehicle_number', 'PB08AB1234')
            ->assertJsonPath('data.current_status', 'OUTSIDE');

        $this->assertEquals(Student::STATE_OUTSIDE, $this->student1->fresh()->current_status);
        $this->assertDatabaseHas('movements', [
            'student_id' => $this->student1->id,
            'type' => 'OUT',
            'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
            'security_user_id' => $this->guardUser1->id,
            'gate_id' => $this->gate1->id,
        ]);
    }

    /**
     * TEST 5: Successful manual IN for an OUTSIDE student (No destination/purpose needed).
     */
    public function test_05_successful_manual_in(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student2->id,
                'type' => 'IN',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.movement_type', 'IN')
            ->assertJsonPath('data.movement_source', Movement::SOURCE_SECURITY_MANUAL)
            ->assertJsonPath('data.current_status', 'INSIDE');

        $this->assertEquals(Student::STATE_INSIDE, $this->student2->fresh()->current_status);
        $this->assertDatabaseHas('movements', [
            'student_id' => $this->student2->id,
            'type' => 'IN',
            'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
        ]);
    }

    /**
     * TEST 6: Prevent IN when student is already INSIDE.
     */
    public function test_06_prevent_in_when_already_inside(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'IN',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * TEST 7: Prevent OUT when student is already OUTSIDE.
     */
    public function test_07_prevent_out_when_already_outside(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student2->id,
                'type' => 'OUT',
                'destination' => 'Market',
                'purpose' => 'Shopping',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * TEST 8: PENDING student cannot be manually moved.
     */
    public function test_08_pending_student_blocked(): void
    {
        $this->student1->update(['status' => Student::STATUS_PENDING]);
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 9: REJECTED student cannot be manually moved.
     */
    public function test_09_rejected_student_blocked(): void
    {
        $this->student1->update(['status' => Student::STATUS_REJECTED]);
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 10: SUSPENDED student cannot be manually moved.
     */
    public function test_10_suspended_student_blocked(): void
    {
        $this->student1->update(['status' => Student::STATUS_SUSPENDED]);
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 11: Guard without active duty cannot manually move a student.
     */
    public function test_11_guard_without_active_duty_blocked(): void
    {
        $token = $this->guardToken($this->offDutyGuard);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 12: Guard without assigned gate blocked.
     */
    public function test_12_guard_without_assigned_gate_blocked(): void
    {
        // Deactivate gate 1
        $this->gate1->update(['status' => 'INACTIVE']);
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 13: Submitting another gate_id is strictly rejected.
     */
    public function test_13_another_gate_id_rejected(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'gate_id' => $this->gate2->id, // Guard 1 is at Gate 1, trying to submit Gate 2
                'destination' => 'Home',
                'purpose' => 'Leave',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 14: Vehicle YES requires vehicle number.
     */
    public function test_14_vehicle_yes_requires_vehicle_number(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'City',
                'purpose' => 'Trip',
                'vehicle_present' => true,
                'vehicle_number' => '', // Empty vehicle number
            ]);

        $response->assertStatus(422);
    }

    /**
     * TEST 15: Vehicle NO does not require vehicle number.
     */
    public function test_15_vehicle_no_does_not_require_vehicle_number(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'City',
                'purpose' => 'Trip',
                'vehicle_present' => false,
            ]);

        $response->assertStatus(200);
    }

    /**
     * TEST 16: OUT requires destination.
     */
    public function test_16_out_requires_destination(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => '',
                'purpose' => 'Study',
            ]);

        $response->assertStatus(422);
    }

    /**
     * TEST 17: OUT requires purpose.
     */
    public function test_17_out_requires_purpose(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Library',
                'purpose' => '',
            ]);

        $response->assertStatus(422);
    }

    /**
     * TEST 18: Authoritative server timestamp is used.
     */
    public function test_18_authoritative_server_timestamp(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $before = now('Asia/Kolkata')->subSecond();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Market',
                'purpose' => 'Shopping',
            ]);

        $response->assertStatus(200);

        $after = now('Asia/Kolkata')->addSecond();
        $serverTimestamp = Carbon::parse($response->json('data.server_timestamp'));

        $this->assertTrue($serverTimestamp->between($before, $after));
    }

    /**
     * TEST 19: Manual source is recorded as SECURITY_MANUAL.
     */
    public function test_19_manual_source_recorded(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Market',
                'purpose' => 'Shopping',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.movement_source', Movement::SOURCE_SECURITY_MANUAL);

        $this->assertDatabaseHas('movements', [
            'student_id' => $this->student1->id,
            'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
        ]);
    }

    /**
     * TEST 20: Audit log is created with action SECURITY_MANUAL_OUT.
     */
    public function test_20_audit_log_created(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Visit',
            ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SECURITY_MANUAL_OUT',
            'module' => 'MOVEMENT',
            'user_id' => $this->guardUser1->id,
        ]);
    }

    /**
     * TEST 21: Idempotency with client_request_id protects against duplicate creation.
     */
    public function test_21_idempotency_with_client_request_id(): void
    {
        $token = $this->guardToken($this->guardUser1);
        $clientId = 'manual-client-req-' . Str::random(12);

        // First request
        $resp1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Visit',
                'client_request_id' => $clientId,
            ]);

        $resp1->assertStatus(200);
        $movementCount1 = Movement::where('student_id', $this->student1->id)->count();
        $this->assertEquals(1, $movementCount1);

        // Replay with identical client_request_id
        $resp2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Home',
                'purpose' => 'Visit',
                'client_request_id' => $clientId,
            ]);

        $resp2->assertStatus(200);
        $movementCount2 = Movement::where('student_id', $this->student1->id)->count();
        $this->assertEquals(1, $movementCount2, 'Idempotent request must not duplicate movement');
    }

    /**
     * TEST 22 & 23: Concurrent duplicate and conflicting movements are protected.
     */
    public function test_22_and_23_concurrent_state_transitions_protected(): void
    {
        $token1 = $this->guardToken($this->guardUser1);

        // Guard 1 records OUT
        $resp1 = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'City',
                'purpose' => 'Shopping',
            ]);
        $resp1->assertStatus(200);

        // A second immediate OUT request for the same student fails because student is now OUTSIDE
        $token2 = $this->guardToken($this->guardUser2);
        $resp2 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'City',
                'purpose' => 'Shopping',
            ]);
        $resp2->assertStatus(422);
    }

    /**
     * TEST 24: Student cannot call the security manual-movement API.
     */
    public function test_24_student_cannot_call_manual_movement_api(): void
    {
        $studentToken = $this->studentUser1->createToken('student_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'City',
                'purpose' => 'Shopping',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 25: Existing QR movement flow continues to work 100% unaffected.
     */
    public function test_25_existing_qr_movement_flow_still_works(): void
    {
        // Student 1 uses standard QR flow
        $session = GateSession::create([
            'session_token' => Str::uuid()->toString(),
            'student_id' => $this->student1->id,
            'gate_id' => $this->gate1->id,
            'status' => GateSession::STATUS_PENDING,
            'expires_at' => now('Asia/Kolkata')->addMinutes(3),
        ]);

        $movement = $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'QR Dest', 'purpose' => 'QR Purpose']
        );

        $this->assertNotNull($movement);
        $this->assertEquals(Movement::SOURCE_QR, $movement->movement_source);
        $this->assertEquals(Student::STATE_OUTSIDE, $this->student1->fresh()->current_status);
    }

    /**
     * TEST 26: Real-time notification is generated with Manual Entry label.
     */
    public function test_26_notification_generated_with_manual_entry_label(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Kapurthala',
                'purpose' => 'Home Visit',
            ])->assertStatus(200);

        $notification = $this->guardUser1->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Manual Entry', $notification->data['title']);
        $this->assertEquals(Movement::SOURCE_SECURITY_MANUAL, $notification->data['movement_source']);
    }

    /**
     * TEST 27: Unauthorized security at another gate does NOT receive foreign gate notification.
     */
    public function test_27_guard_at_another_gate_does_not_receive_foreign_gate_notification(): void
    {
        $token = $this->guardToken($this->guardUser1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/security/manual-movement', [
                'student_id' => $this->student1->id,
                'type' => 'OUT',
                'destination' => 'Kapurthala',
                'purpose' => 'Home Visit',
            ])->assertStatus(200);

        // Guard 1 (at Gate 1) receives it
        $this->assertEquals(1, $this->guardUser1->notifications()->count());

        // Guard 2 (at Gate 2) receives 0 notifications
        $this->assertEquals(0, $this->guardUser2->notifications()->count());
    }

    /**
     * TEST 28: Historical access rules remain unchanged (Guard limited to 15 days).
     */
    public function test_28_history_restrictions_remain_unchanged(): void
    {
        // Old movement (20 days ago)
        $oldMovement = Movement::create([
            'movement_uuid' => Str::uuid()->toString(),
            'verification_code' => 'SG-OLD001',
            'student_id' => $this->student1->id,
            'gate_id' => $this->gate1->id,
            'security_user_id' => $this->guardUser1->id,
            'type' => 'IN',
            'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
            'server_timestamp' => now('Asia/Kolkata')->subDays(20),
        ]);

        $token = $this->guardToken($this->guardUser1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/security/students/{$this->student1->id}/history");

        $response->assertStatus(200);
        $movements = $response->json('data.movements');

        // The 20-day old movement must NOT appear in the security 15-day history
        $movementIds = collect($movements)->pluck('id');
        $this->assertFalse($movementIds->contains($oldMovement->id));
    }
}
