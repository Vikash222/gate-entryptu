<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Events\StudentMovementRecorded;
use App\Listeners\SendMovementNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Illuminate\Support\Str;

class NotificationScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_1_movement_sends_to_gate_1_guard_not_gate_2()
    {
        $gate1 = Gate::create(['name' => 'Gate 1', 'code' => 'G1', 'status' => 'ACTIVE', 'latitude' => 0, 'longitude' => 0]);
        $gate2 = Gate::create(['name' => 'Gate 2', 'code' => 'G2', 'status' => 'ACTIVE', 'latitude' => 0, 'longitude' => 0]);

        $guard1 = User::factory()->create(['role' => User::ROLE_SECURITY, 'status' => User::STATUS_ACTIVE]);
        $guard2 = User::factory()->create(['role' => User::ROLE_SECURITY, 'status' => User::STATUS_ACTIVE]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE]);
        $student = Student::create(['user_id' => $studentUser->id, 'roll_number' => 'R1', 'student_id' => 'S1', 'name' => 'Student A', 'email' => 'a@test.com', 'phone_number' => '1', 'year' => 1, 'program' => 'B.Tech', 'status' => Student::STATUS_ACTIVE]);
        
        $studentBUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE]);
        $studentB = Student::create(['user_id' => $studentBUser->id, 'roll_number' => 'R2', 'student_id' => 'S2', 'name' => 'Student B', 'email' => 'b@test.com', 'phone_number' => '2', 'year' => 1, 'program' => 'B.Tech', 'status' => Student::STATUS_ACTIVE]);

        // Active duties
        SecurityDutySession::create(['user_id' => $guard1->id, 'gate_id' => $gate1->id, 'status' => 'ACTIVE', 'started_at' => now(), 'session_token' => Str::random(32)]);
        SecurityDutySession::create(['user_id' => $guard2->id, 'gate_id' => $gate2->id, 'status' => 'ACTIVE', 'started_at' => now(), 'session_token' => Str::random(32)]);

        $movement = Movement::create([
            'movement_uuid' => Str::uuid()->toString(),
            'student_id' => $student->id,
            'gate_id' => $gate1->id,
            'type' => 'OUT',
            'server_timestamp' => now(),
            'verification_code' => Str::random(10),
            'destination' => 'Home',
            'purpose' => 'Visit',
            'vehicle_present' => false,
            'movement_source' => Movement::SOURCE_QR
        ]);

        Notification::fake();

        $listener = new SendMovementNotifications();
        $listener->handle(new StudentMovementRecorded($movement));

        Notification::assertSentTo($guard1, \App\Notifications\StudentMovementNotification::class);
        Notification::assertNotSentTo($guard2, \App\Notifications\StudentMovementNotification::class);
        Notification::assertSentTo($admin, \App\Notifications\StudentMovementNotification::class);
        Notification::assertSentTo($studentUser, \App\Notifications\StudentMovementNotification::class);
        Notification::assertNotSentTo($studentBUser, \App\Notifications\StudentMovementNotification::class);
    }

    public function test_security_manual_movement_notification_scoping_both_qr_and_manual()
    {
        $gate1 = Gate::create(['name' => 'Gate 1', 'code' => 'G1', 'status' => 'ACTIVE', 'latitude' => 0, 'longitude' => 0]);
        $gate2 = Gate::create(['name' => 'Gate 2', 'code' => 'G2', 'status' => 'ACTIVE', 'latitude' => 0, 'longitude' => 0]);

        $guard1 = User::factory()->create(['role' => User::ROLE_SECURITY, 'status' => User::STATUS_ACTIVE]);
        $guard2 = User::factory()->create(['role' => User::ROLE_SECURITY, 'status' => User::STATUS_ACTIVE]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE]);
        $student = Student::create(['user_id' => $studentUser->id, 'roll_number' => 'R10', 'student_id' => 'S10', 'name' => 'Student Manual', 'email' => 'manual@test.com', 'phone_number' => '10', 'year' => 1, 'program' => 'B.Tech', 'status' => Student::STATUS_ACTIVE]);

        SecurityDutySession::create(['user_id' => $guard1->id, 'gate_id' => $gate1->id, 'status' => 'ACTIVE', 'started_at' => now(), 'session_token' => Str::random(32)]);
        SecurityDutySession::create(['user_id' => $guard2->id, 'gate_id' => $gate2->id, 'status' => 'ACTIVE', 'started_at' => now(), 'session_token' => Str::random(32)]);

        $movement = Movement::create([
            'movement_uuid' => Str::uuid()->toString(),
            'student_id' => $student->id,
            'gate_id' => $gate1->id,
            'security_user_id' => $guard1->id,
            'type' => 'IN',
            'server_timestamp' => now(),
            'verification_code' => Str::random(10),
            'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
        ]);

        Notification::fake();

        $listener = new SendMovementNotifications();
        $listener->handle(new StudentMovementRecorded($movement));

        Notification::assertSentTo($guard1, \App\Notifications\StudentMovementNotification::class);
        Notification::assertNotSentTo($guard2, \App\Notifications\StudentMovementNotification::class);
        Notification::assertSentTo($admin, \App\Notifications\StudentMovementNotification::class);
        Notification::assertSentTo($studentUser, \App\Notifications\StudentMovementNotification::class);
    }
}
