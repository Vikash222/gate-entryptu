<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DayScholarAfterHoursTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected User $dayScholarUser;
    protected Student $dayScholarStudent;
    protected User $hostelerUser;
    protected Student $hostelerStudent;
    protected Gate $gate;
    protected SecurityDutySession $dutySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->guardUser = User::factory()->create([
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->gate = Gate::create([
            'name' => 'Main Gate',
            'code' => 'MAIN-01',
            'status' => Gate::STATUS_ACTIVE,
            'created_by' => $this->adminUser->id,
        ]);

        $this->dutySession = SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'authorized_by' => $this->adminUser->id,
            'started_at' => now(),
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'verified_at' => now(),
        ]);

        // Day Scholar Student
        $this->dayScholarUser = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->dayScholarStudent = Student::create([
            'user_id' => $this->dayScholarUser->id,
            'student_id' => 'STU-DS-001',
            'roll_number' => 'DS2026001',
            'name' => 'Aditi Sharma',
            'email' => $this->dayScholarUser->email,
            'category' => Student::CATEGORY_DAY_SCHOLAR,
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_OUTSIDE,
        ]);

        // Hosteler Student
        $this->hostelerUser = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->hostelerStudent = Student::create([
            'user_id' => $this->hostelerUser->id,
            'student_id' => 'STU-HST-001',
            'roll_number' => 'HST2026001',
            'name' => 'Rahul Verma',
            'email' => $this->hostelerUser->email,
            'category' => Student::CATEGORY_HOSTELER,
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_OUTSIDE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setSimulatedTime(string $datetimeStr): void
    {
        Carbon::setTestNow(Carbon::parse($datetimeStr, 'Asia/Kolkata'));
    }

    public function test_day_scholar_in_at_5_30_pm_is_allowed_and_classified_after_hours(): void
    {
        // 5:30 PM IST => >= 17:00:00 (After Hours, but before Late Entry 21:00)
        $this->setSimulatedTime('2026-09-21 17:30:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'movement_type' => 'IN',
                'category' => 'DAY_SCHOLAR',
                'is_day_scholar' => true,
                'day_scholar_after_hours' => true,
                'DAY_SCHOLAR_AFTER_HOURS' => true,
                'day_scholar_warning' => 'DAY SCHOLAR — AFTER HOURS',
                'is_late' => false,
            ],
        ]);

        $this->assertDatabaseHas('movements', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
            'day_scholar_after_hours' => true,
            'is_late' => false,
        ]);
    }

    public function test_day_scholar_in_at_8_00_pm_is_allowed_and_classified_after_hours(): void
    {
        // 8:00 PM IST => After Hours, Normal (not late)
        $this->setSimulatedTime('2026-09-21 20:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.day_scholar_after_hours'));
        $this->assertFalse($response->json('data.is_late'));
        $this->assertEquals('DAY SCHOLAR — AFTER HOURS', $response->json('data.day_scholar_warning'));
    }

    public function test_day_scholar_in_at_10_00_pm_is_allowed_and_both_after_hours_and_late(): void
    {
        // 10:00 PM IST => Coexistence: After Hours AND Late Entry
        $this->setSimulatedTime('2026-09-21 22:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.day_scholar_after_hours'));
        $this->assertTrue($response->json('data.is_late'));
        $this->assertEquals('DAY SCHOLAR — AFTER HOURS', $response->json('data.day_scholar_warning'));

        $this->assertDatabaseHas('movements', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
            'day_scholar_after_hours' => true,
            'is_late' => true,
        ]);
    }

    public function test_day_scholar_out_at_7_00_pm_is_allowed_and_classified_after_hours(): void
    {
        // Setup student as INSIDE first
        $this->dayScholarStudent->update(['current_status' => Student::STATE_INSIDE]);

        // 7:00 PM IST => After Hours OUT
        $this->setSimulatedTime('2026-09-21 19:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'OUT',
            'destination' => 'Home',
            'purpose' => 'Commute Back',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.day_scholar_after_hours'));
        $this->assertFalse($response->json('data.is_late'));
        $this->assertEquals('DAY SCHOLAR — AFTER HOURS', $response->json('data.day_scholar_warning'));
    }

    public function test_day_scholar_out_at_10_00_pm_is_allowed_and_both_after_hours_and_late(): void
    {
        // Setup student as INSIDE
        $this->dayScholarStudent->update(['current_status' => Student::STATE_INSIDE]);

        // 10:00 PM IST => OUT After Hours AND Late
        $this->setSimulatedTime('2026-09-21 22:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'OUT',
            'destination' => 'Home',
            'purpose' => 'Late Study Return',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.day_scholar_after_hours'));
        $this->assertTrue($response->json('data.is_late'));
    }

    public function test_daytime_movement_for_day_scholar_is_not_after_hours(): void
    {
        // 2:00 PM IST => Daytime
        $this->setSimulatedTime('2026-09-21 14:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.day_scholar_after_hours'));
        $this->assertNull($response->json('data.day_scholar_warning'));
        $this->assertFalse($response->json('data.is_late'));
    }

    public function test_hosteler_movement_after_5_pm_is_not_classified_as_day_scholar_after_hours(): void
    {
        // 6:00 PM IST => Hosteler entry is NOT day scholar after hours
        $this->setSimulatedTime('2026-09-21 18:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->hostelerStudent->id,
            'type' => 'IN',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('HOSTELER', $response->json('data.category'));
        $this->assertFalse($response->json('data.is_day_scholar'));
        $this->assertFalse($response->json('data.day_scholar_after_hours'));
        $this->assertNull($response->json('data.day_scholar_warning'));
    }

    public function test_search_student_returns_after_hours_warning_if_currently_after_5_pm(): void
    {
        // When searching at 6:30 PM IST
        $this->setSimulatedTime('2026-09-21 18:30:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->getJson('/api/v1/security/students/search?q=DS2026001');

        $response->assertStatus(200);
        $students = $response->json('data');
        $this->assertCount(1, $students);
        $this->assertTrue($students[0]['is_day_scholar']);
        $this->assertTrue($students[0]['day_scholar_after_hours']);
        $this->assertEquals('DAY SCHOLAR — AFTER HOURS', $students[0]['day_scholar_warning']);
    }

    public function test_admin_movements_query_filters_by_day_scholar_after_hours(): void
    {
        // 1. Record After-Hours IN for Day Scholar
        $this->setSimulatedTime('2026-09-21 17:30:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->dayScholarStudent->id,
            'type' => 'IN',
        ]);

        // 2. Record Normal IN for Hosteler
        $this->setSimulatedTime('2026-09-21 17:30:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->hostelerStudent->id,
            'type' => 'IN',
        ]);

        // Query with day_scholar_after_hours=true
        $responseAfterHours = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/admin/movements?day_scholar_after_hours=true');
        $responseAfterHours->assertStatus(200);
        $this->assertCount(1, $responseAfterHours->json('data.data'));
        $this->assertEquals('DS2026001', $responseAfterHours->json('data.data.0.student.roll_number'));

        // Query with category=DAY_SCHOLAR
        $responseCat = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/admin/movements?student_category=DAY_SCHOLAR');
        $responseCat->assertStatus(200);
        $this->assertCount(1, $responseCat->json('data.data'));
    }
}
