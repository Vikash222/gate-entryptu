<?php

namespace Tests\Feature;

use App\Events\StudentMovementRecorded;
use App\Models\Gate;
use App\Models\GateSession;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser1;
    protected User $guardUser2;
    protected Gate $gate1;
    protected Gate $gate2;
    protected User $studentUser1;
    protected Student $student1;
    protected User $studentUser2;
    protected Student $student2;
    protected MovementService $movementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movementService = app(MovementService::class);

        // Admin
        $this->adminUser = User::create([
            'name' => 'Campus Admin',
            'email' => 'admin@ptu.ac.in',
            'password' => bcrypt('AdminPass123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        // Guard 1 at Gate 1
        $this->guardUser1 = User::create([
            'name' => 'Officer Ram',
            'email' => 'ram@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        // Guard 2 at Gate 2
        $this->guardUser2 = User::create([
            'name' => 'Officer Shyam',
            'email' => 'shyam@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        // Gates
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

        // Active duty for Guard 1 at Gate 1
        SecurityDutySession::create([
            'user_id' => $this->guardUser1->id,
            'gate_id' => $this->gate1->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);

        // Active duty for Guard 2 at Gate 2
        SecurityDutySession::create([
            'user_id' => $this->guardUser2->id,
            'gate_id' => $this->gate2->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);

        // Student 1
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

        // Student 2
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
            'current_status' => Student::STATE_INSIDE,
        ]);
    }

    protected function createGateSession(Student $student, Gate $gate): GateSession
    {
        return GateSession::create([
            'session_token' => Str::uuid()->toString(),
            'student_id' => $student->id,
            'gate_id' => $gate->id,
            'status' => GateSession::STATUS_PENDING,
            'expires_at' => now('Asia/Kolkata')->addMinutes(3),
        ]);
    }

    /**
     * TEST 1: Student IN creates notification for Student, active Gate Guard, and Admin.
     */
    public function test_01_student_in_creates_notification_for_student_guard_and_admin(): void
    {
        // Set student outside first so they can record IN
        $this->student1->update(['current_status' => Student::STATE_OUTSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $movement = $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_IN
        );

        $this->assertNotNull($movement);

        // Student receives confirmation
        $studentNotifications = $this->studentUser1->notifications;
        $this->assertCount(1, $studentNotifications);
        $this->assertEquals('STUDENT_MOVEMENT', $studentNotifications->first()->data['type']);
        $this->assertEquals('IN', $studentNotifications->first()->data['movement_type']);
        $this->assertEquals('Rahul Kumar', $studentNotifications->first()->data['student_name']);

        // Guard at Gate 1 receives notification
        $guardNotifications = $this->guardUser1->notifications;
        $this->assertCount(1, $guardNotifications);
        $this->assertEquals('IN', $guardNotifications->first()->data['movement_type']);

        // Admin receives notification
        $adminNotifications = $this->adminUser->notifications;
        $this->assertCount(1, $adminNotifications);
        $this->assertEquals('IN', $adminNotifications->first()->data['movement_type']);
    }

    /**
     * TEST 2: Student OUT creates notification with destination, purpose, and vehicle details.
     */
    public function test_02_student_out_creates_notification_with_destination_and_purpose(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $movement = $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: [
                'destination' => 'Jalandhar City',
                'purpose' => 'Personal work',
                'vehicle_present' => true,
                'vehicle_number' => 'PB08AB1234',
            ]
        );

        $this->assertNotNull($movement);

        $guardNotification = $this->guardUser1->notifications()->first();
        $this->assertNotNull($guardNotification);

        $data = $guardNotification->data;
        $this->assertEquals('OUT', $data['movement_type']);
        $this->assertEquals('Jalandhar City', $data['destination']);
        $this->assertEquals('Personal work', $data['purpose']);
        $this->assertTrue($data['vehicle_present']);
        $this->assertEquals('PB08AB1234', $data['vehicle_number']);
        $this->assertNotEmpty($data['server_timestamp']);
    }

    /**
     * TEST 3: Notification is visible to authorized active guard at assigned gate.
     */
    public function test_03_notification_is_visible_to_authorized_active_guard(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: [
                'destination' => 'Market',
                'purpose' => 'Shopping',
            ]
        );

        // Guard 1 at Gate 1 receives it
        $this->assertEquals(1, $this->guardUser1->notifications()->count());
    }

    /**
     * TEST 4: Notification is NOT visible to unauthorized guard at another gate.
     */
    public function test_04_notification_is_not_visible_to_unauthorized_guard(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: [
                'destination' => 'Market',
                'purpose' => 'Shopping',
            ]
        );

        // Guard 2 at Gate 2 does NOT receive it
        $this->assertEquals(0, $this->guardUser2->notifications()->count());
    }

    /**
     * TEST 5: Student receives only their own movement notification.
     */
    public function test_05_student_receives_only_their_own_movement_notification(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: [
                'destination' => 'Station',
                'purpose' => 'Home visit',
            ]
        );

        // Student 1 gets their notification
        $this->assertEquals(1, $this->studentUser1->notifications()->count());

        // Student 2 gets ZERO notifications
        $this->assertEquals(0, $this->studentUser2->notifications()->count());
    }

    /**
     * TEST 6: Admin receives notification when a new student self-registers.
     */
    public function test_06_admin_receives_student_registration_notification(): void
    {
        $response = $this->postJson('/api/v1/auth/register-student', [
            'name' => 'Aman Verma',
            'roll_number' => '23CSE199',
            'student_type' => 'HOSTELLER',
            'year' => 1,
            'program' => 'B.Tech ME',
            'email' => 'aman@ptu.ac.in',
            'phone_number' => '9876543210',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201);

        $adminNotifications = $this->adminUser->notifications;
        $this->assertCount(1, $adminNotifications);

        $data = $adminNotifications->first()->data;
        $this->assertEquals('STUDENT_REGISTRATION', $data['type']);
        $this->assertEquals('STUDENT_REGISTERED', $data['event']);
        $this->assertEquals('Aman Verma', $data['student_name']);
        $this->assertEquals('23CSE199', $data['roll_number']);
    }

    /**
     * TEST 7: Admin and Student receive notification on student status change (e.g. approve).
     */
    public function test_07_admin_and_student_receive_notification_on_status_change(): void
    {
        $pendingUser = User::create([
            'name' => 'Pending Student',
            'email' => 'pending@ptu.ac.in',
            'password' => bcrypt('Pass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_PENDING,
        ]);

        $pendingStudent = Student::create([
            'user_id' => $pendingUser->id,
            'student_id' => 'STU-999',
            'roll_number' => '23CSE999',
            'name' => 'Pending Student',
            'email' => 'pending@ptu.ac.in',
            'status' => Student::STATUS_PENDING,
            'current_status' => Student::STATE_INSIDE,
        ]);

        $token = $this->adminUser->createToken('admin_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/students/{$pendingStudent->id}/approve");

        $response->assertStatus(200);

        // Student receives approval notification
        $studentNotification = $pendingUser->notifications()->first();
        $this->assertNotNull($studentNotification);
        $this->assertEquals('STUDENT_APPROVED', $studentNotification->data['event']);

        // Admin receives approval notification
        $adminNotification = $this->adminUser->notifications()->first();
        $this->assertNotNull($adminNotification);
        $this->assertEquals('STUDENT_APPROVED', $adminNotification->data['event']);
    }

    /**
     * TEST 8: Duplicate API request does not create duplicate notifications.
     */
    public function test_08_duplicate_request_id_does_not_duplicate_notification(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $clientRequestId = 'client-req-' . Str::random(16);

        // First call
        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'Library', 'purpose' => 'Study'],
            clientRequestId: $clientRequestId
        );

        $initialCount = $this->guardUser1->notifications()->count();
        $this->assertEquals(1, $initialCount);

        // Second call with same clientRequestId (idempotent replay)
        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'Library', 'purpose' => 'Study'],
            clientRequestId: $clientRequestId
        );

        $finalCount = $this->guardUser1->notifications()->count();
        $this->assertEquals(1, $finalCount, 'Idempotent request must not create duplicate notification');
    }

    /**
     * TEST 9: Notification read state works (mark as read).
     */
    public function test_09_notification_mark_as_read(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'Gym', 'purpose' => 'Fitness']
        );

        $notification = $this->guardUser1->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertNull($notification->read_at);

        $token = $this->guardUser1->createToken('guard_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(200);

        $fresh = $this->guardUser1->notifications()->find($notification->id);
        $this->assertNotNull($fresh->read_at);
    }

    /**
     * TEST 10: Unread count endpoint is correct.
     */
    public function test_10_unread_count_is_correct(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'Hostel', 'purpose' => 'Rest']
        );

        $token = $this->guardUser1->createToken('guard_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1);

        // Mark all as read
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications/read-all')
            ->assertStatus(200);

        // Verify count is 0
        $responseAfter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/unread-count');

        $responseAfter->assertStatus(200)
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * TEST 11: Unauthorized user cannot access another user's notifications.
     */
    public function test_11_user_cannot_access_or_mark_read_another_users_notification(): void
    {
        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'City', 'purpose' => 'Shopping']
        );

        $guard1Notification = $this->guardUser1->notifications()->first();
        $this->assertNotNull($guard1Notification);

        // Guard 2 tries to mark Guard 1's notification as read
        $token2 = $this->guardUser2->createToken('guard2_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson("/api/v1/notifications/{$guard1Notification->id}/read");

        // Must return 404
        $response->assertStatus(404);

        // Guard 1 notification remains unread
        $fresh = $this->guardUser1->notifications()->find($guard1Notification->id);
        $this->assertNull($fresh->read_at);
    }

    /**
     * TEST 12: Notification failure does NOT break or roll back movement recording.
     */
    public function test_12_notification_failure_does_not_break_movement_recording(): void
    {
        // Fake event listener exception
        Event::listen(StudentMovementRecorded::class, function () {
            throw new \RuntimeException('Simulated notification broker failure');
        });

        $this->student1->update(['current_status' => Student::STATE_INSIDE]);
        $session = $this->createGateSession($this->student1, $this->gate1);

        // Movement must still succeed
        $movement = $this->movementService->recordMovement(
            studentModel: $this->student1,
            sessionToken: $session->session_token,
            type: Movement::TYPE_OUT,
            data: ['destination' => 'Home', 'purpose' => 'Weekend']
        );

        $this->assertNotNull($movement);
        $this->assertDatabaseHas('movements', ['id' => $movement->id]);
        $this->assertEquals(Student::STATE_OUTSIDE, $this->student1->fresh()->current_status);
    }
}
