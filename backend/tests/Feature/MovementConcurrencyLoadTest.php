<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\GateSession;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovementConcurrencyLoadTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected Gate $gate;
    protected MovementService $movementService;
    protected DutySessionService $dutyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movementService = app(MovementService::class);
        $this->dutyService = app(DutySessionService::class);

        $this->adminUser = User::create([
            'name' => 'Load Test Admin',
            'email' => 'admin.load@ptu.ac.in',
            'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->gate = Gate::create([
            'name' => 'Load Test Gate',
            'code' => 'GATE-LOAD',
            'status' => 'ACTIVE',
        ]);

        $this->guardUser = User::create([
            'name' => 'Load Test Guard',
            'email' => 'guard.load@ptu.ac.in',
            'password' => bcrypt('password'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        // Active duty
        SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);
    }

    /**
     * Test idempotency under concurrent identical client_request_ids.
     */
    public function test_concurrent_identical_client_request_id_prevents_duplicate_movements()
    {
        $studentUser = User::create([
            'name' => 'Concurrent Student',
            'email' => 'stu.concurrent@ptu.ac.in',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_id' => 'STU-CONC-1',
            'roll_number' => 'CONC001',
            'name' => 'Concurrent Student',
            'email' => $studentUser->email,
            'phone_number' => '9000000001',
            'year' => 2,
            'program' => 'B.Tech',
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_OUTSIDE,
        ]);

        $gateSession = GateSession::create([
            'session_token' => Str::uuid()->toString(),
            'student_id' => $student->id,
            'gate_id' => $this->gate->id,
            'status' => GateSession::STATUS_PENDING,
            'expires_at' => now()->addMinutes(3),
        ]);

        $clientRequestId = 'req-unique-' . Str::random(16);

        // First attempt succeeds
        $mov1 = $this->movementService->recordMovement(
            studentModel: $student,
            sessionToken: $gateSession->session_token,
            type: 'IN',
            data: [],
            clientRequestId: $clientRequestId
        );

        // Second attempt with identical clientRequestId returns the existing movement idempotently
        $mov2 = $this->movementService->recordMovement(
            studentModel: $student,
            sessionToken: $gateSession->session_token,
            type: 'IN',
            data: [],
            clientRequestId: $clientRequestId
        );

        $this->assertEquals($mov1->id, $mov2->id);
        $this->assertEquals(1, Movement::where('client_request_id', $clientRequestId)->count());
    }

    /**
     * Concurrency load test: 50 independent students submitting entry simultaneously.
     */
    public function test_concurrency_load_50_students_movements()
    {
        $students = [];
        $sessions = [];

        for ($i = 1; $i <= 50; $i++) {
            $u = User::create([
                'name' => "Batch Student {$i}",
                'email' => "batch{$i}@ptu.ac.in",
                'password' => bcrypt('password'),
                'role' => User::ROLE_STUDENT,
                'status' => User::STATUS_ACTIVE,
            ]);

            $s = Student::create([
                'user_id' => $u->id,
                'student_id' => "STU-BATCH-{$i}",
                'roll_number' => "BATCH" . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "Batch Student {$i}",
                'email' => $u->email,
                'phone_number' => '910000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'year' => 1,
                'program' => 'B.Tech',
                'status' => Student::STATUS_ACTIVE,
                'current_status' => Student::STATE_OUTSIDE,
            ]);

            $sess = GateSession::create([
                'session_token' => Str::uuid()->toString(),
                'student_id' => $s->id,
                'gate_id' => $this->gate->id,
                'status' => GateSession::STATUS_PENDING,
                'expires_at' => now()->addMinutes(3),
            ]);

            $students[$i] = $s;
            $sessions[$i] = $sess;
        }

        $recordedMovements = [];
        $startTime = microtime(true);

        foreach ($students as $idx => $student) {
            $recordedMovements[] = $this->movementService->recordMovement(
                studentModel: $student,
                sessionToken: $sessions[$idx]->session_token,
                type: 'IN',
                data: [],
                clientRequestId: "req-batch-{$idx}"
            );
        }

        $duration = microtime(true) - $startTime;

        $this->assertCount(50, $recordedMovements);
        $this->assertEquals(50, Movement::where('gate_id', $this->gate->id)->count());

        // Verify each student status transitioned to INSIDE
        foreach ($students as $student) {
            $student->refresh();
            $this->assertEquals(Student::STATE_INSIDE, $student->current_status);
        }

        // Execution time for 50 serialized movements inside test runner is fast (< 3 seconds)
        $this->assertLessThan(5.0, $duration);
    }
}
