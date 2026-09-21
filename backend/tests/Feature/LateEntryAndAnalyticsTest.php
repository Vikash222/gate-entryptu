<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class LateEntryAndAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected User $studentUser;
    protected Student $student;
    protected Gate $gate;
    protected SecurityDutySession $dutySession;
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

        // 2. Gate
        $this->gate = Gate::create([
            'name' => 'Main University Gate',
            'code' => 'GATE-MAIN',
            'status' => Gate::STATUS_ACTIVE,
        ]);

        // 3. Security Guard & Active Duty
        $this->guardUser = User::create([
            'name' => 'Officer Vikram',
            'email' => 'vikram@ptu.ac.in',
            'password' => bcrypt('GuardPass123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->dutySession = SecurityDutySession::create([
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'started_at' => Carbon::now('Asia/Kolkata')->subHours(1),
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'device_identifier' => 'GUARD-TAB-01',
        ]);

        // 4. Student
        $this->studentUser = User::create([
            'name' => 'Aarav Sharma',
            'email' => 'aarav@ptu.ac.in',
            'password' => bcrypt('StudentPass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'student_id' => 'PTU-2024-001',
            'roll_number' => 'CS2024001',
            'name' => 'Aarav Sharma',
            'email' => 'aarav@ptu.ac.in',
            'program' => 'B.Tech CSE',
            'year' => 2,
            'current_status' => Student::STATE_INSIDE,
            'status' => Student::STATUS_ACTIVE,
        ]);
    }

    /**
     * Helper to set test time and sync duty session
     */
    protected function setSimulationTime(string $timeStr): void
    {
        $dt = Carbon::parse($timeStr, 'Asia/Kolkata');
        Carbon::setTestNow($dt);
        $this->dutySession->update([
            'started_at' => $dt->copy()->subMinutes(10),
            'status' => SecurityDutySession::STATUS_ACTIVE,
            'ended_at' => null,
        ]);
    }

    /**
     * Helper to create a student
     */
    protected function createExtraStudent(string $roll, string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($roll) . '@ptu.ac.in',
            'password' => bcrypt('Pass123!'),
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'student_id' => 'PTU-' . $roll,
            'roll_number' => $roll,
            'name' => $name,
            'email' => $user->email,
            'program' => 'B.Tech',
            'year' => 1,
            'current_status' => Student::STATE_INSIDE,
            'status' => Student::STATUS_ACTIVE,
        ]);
    }

    // =========================================================================
    // 1. BOUNDARY CONDITIONS TEST
    // =========================================================================

    public function test_boundary_times_classification(): void
    {
        // Asia/Kolkata boundary tests
        $boundaries = [
            // 20:59:59 -> NORMAL
            ['time' => '2026-09-21 20:59:59', 'is_late' => false, 'late_window' => null],
            // 21:00:00 -> LATE (inclusive start)
            ['time' => '2026-09-21 21:00:00', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 21:00:01 -> LATE
            ['time' => '2026-09-21 21:00:01', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 23:59:59 -> LATE
            ['time' => '2026-09-21 23:59:59', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 00:00:00 -> LATE (midnight, window belongs to previous night 2026-09-21)
            ['time' => '2026-09-22 00:00:00', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 01:30:00 -> LATE
            ['time' => '2026-09-22 01:30:00', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 03:59:59 -> LATE
            ['time' => '2026-09-22 03:59:59', 'is_late' => true, 'late_window' => '2026-09-21'],
            // 04:00:00 -> NORMAL (exclusive boundary)
            ['time' => '2026-09-22 04:00:00', 'is_late' => false, 'late_window' => null],
            // 04:00:01 -> NORMAL
            ['time' => '2026-09-22 04:00:01', 'is_late' => false, 'late_window' => null],
            // 12:00:00 -> NORMAL (noon)
            ['time' => '2026-09-22 12:00:00', 'is_late' => false, 'late_window' => null],
        ];

        foreach ($boundaries as $b) {
            $dt = Carbon::parse($b['time'], 'Asia/Kolkata');
            $this->assertEquals(
                $b['is_late'],
                Movement::isLateTimestamp($dt),
                "Failed asserting is_late for timestamp {$b['time']}"
            );
            $this->assertEquals(
                $b['late_window'],
                Movement::calculateLateWindowDate($dt),
                "Failed asserting late_window_date for timestamp {$b['time']}"
            );
        }
    }

    // =========================================================================
    // 2. MOVEMENT RECORDING SOURCES & TYPES TEST
    // =========================================================================

    public function test_security_manual_movement_during_late_hours_is_classified_as_late(): void
    {
        $this->setSimulationTime('2026-09-21 23:30:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'City Hospital',
            'purpose' => 'Medical Emergency',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_late', true);
        $response->assertJsonPath('data.late_window_date', '2026-09-21');
        $response->assertJsonPath('data.movement_source', Movement::SOURCE_SECURITY_MANUAL);

        $dbRecord = Movement::where('verification_code', $response->json('data.verification_code'))->first();
        $this->assertNotNull($dbRecord);
        $this->assertTrue((bool) $dbRecord->is_late);
        $this->assertEquals('2026-09-21', $dbRecord->late_window_date->format('Y-m-d'));

        Carbon::setTestNow();
    }

    public function test_security_manual_movement_during_daytime_is_classified_as_normal(): void
    {
        $this->setSimulationTime('2026-09-21 14:00:00');

        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Market',
            'purpose' => 'Shopping',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_late', false);
        $this->assertNull($response->json('data.late_window_date'));

        $dbRecord = Movement::where('verification_code', $response->json('data.verification_code'))->first();
        $this->assertNotNull($dbRecord);
        $this->assertFalse((bool) $dbRecord->is_late);
        $this->assertNull($dbRecord->late_window_date);

        Carbon::setTestNow();
    }

    // =========================================================================
    // 3. ZERO-CLIENT-TRUST / TAMPERING RESISTANCE
    // =========================================================================

    public function test_client_cannot_tamper_with_is_late_or_late_window_date(): void
    {
        $this->setSimulationTime('2026-09-21 22:15:00');

        // Client attempts to pass is_late=false and fake late_window_date
        $response = $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Market',
            'purpose' => 'Shopping',
            'is_late' => false,
            'late_window_date' => '2020-01-01',
        ]);

        $response->assertStatus(200);
        // Authoritative server override must be in effect
        $response->assertJsonPath('data.is_late', true);
        $response->assertJsonPath('data.late_window_date', '2026-09-21');

        $dbRecord = Movement::where('verification_code', $response->json('data.verification_code'))->first();
        $this->assertNotNull($dbRecord);
        $this->assertTrue((bool) $dbRecord->is_late);
        $this->assertEquals('2026-09-21', $dbRecord->late_window_date->format('Y-m-d'));

        Carbon::setTestNow();
    }

    // =========================================================================
    // 4. OVERNIGHT GROUPING TEST
    // =========================================================================

    public function test_overnight_movements_share_the_same_late_window_date(): void
    {
        $student2 = $this->createExtraStudent('CS2024002', 'Bhavna Roy');

        // Movement A: 21 Sep 2026 at 22:00
        $this->setSimulationTime('2026-09-21 22:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Home',
            'purpose' => 'Weekend',
        ])->assertStatus(200);

        // Movement B: 22 Sep 2026 at 02:30 (next calendar day, but same overnight shift)
        $this->setSimulationTime('2026-09-22 02:30:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $student2->id,
            'type' => 'OUT',
            'destination' => 'Hospital',
            'purpose' => 'Checkup',
        ])->assertStatus(200);

        // Check both in DB
        $records = Movement::where('late_window_date', '2026-09-21')->get();
        $this->assertCount(2, $records);
        foreach ($records as $rec) {
            $this->assertTrue((bool) $rec->is_late);
            $this->assertEquals('2026-09-21', $rec->late_window_date->format('Y-m-d'));
        }

        Carbon::setTestNow();
    }

    // =========================================================================
    // 5. ADMIN FILTERING & QUERY PIPELINE
    // =========================================================================

    public function test_admin_can_filter_by_late_status_and_late_window_date(): void
    {
        $student2 = $this->createExtraStudent('CS2024002', 'Bhavna Roy');

        // 1 Normal daytime movement
        $this->setSimulationTime('2026-09-21 10:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Library',
            'purpose' => 'Study',
        ])->assertStatus(200);

        // 1 Late movement
        $this->setSimulationTime('2026-09-21 23:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $student2->id,
            'type' => 'OUT',
            'destination' => 'Airport',
            'purpose' => 'Flight',
        ])->assertStatus(200);

        Carbon::setTestNow();

        // Admin queries all movements
        $respAll = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/admin/movements');
        $respAll->assertStatus(200);
        $this->assertCount(2, $respAll->json('data.data'));

        // Admin queries late only
        $respLate = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/admin/movements?late=true');
        $respLate->assertStatus(200);
        $this->assertCount(1, $respLate->json('data.data'));
        $this->assertTrue($respLate->json('data.data.0.is_late'));

        // Admin queries normal only
        $respNormal = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/admin/movements?late=false');
        $respNormal->assertStatus(200);
        $this->assertCount(1, $respNormal->json('data.data'));
        $this->assertFalse($respNormal->json('data.data.0.is_late'));

        // Admin queries by late_window_date
        $respWindow = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/admin/movements?late_window_date=2026-09-21');
        $respWindow->assertStatus(200);
        $this->assertCount(1, $respWindow->json('data.data'));
    }

    // =========================================================================
    // 6. EXCEL (.XLSX) EXPORT & OPENXML STRUCTURE
    // =========================================================================

    public function test_admin_can_export_movements_as_valid_xlsx(): void
    {
        // Create sample movement
        $this->setSimulationTime('2026-09-21 22:10:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'City Hospital & Clinic <Emergency>', // Test XML special characters
            'purpose' => 'Checkup "Urgent"',
        ])->assertStatus(200);

        Carbon::setTestNow();

        $response = $this->actingAs($this->adminUser, 'sanctum')->get('/api/v1/admin/movements/export?format=xlsx');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);

        // Verify ZIP / OpenXML structure
        $streamContent = $response->streamedContent();
        $this->assertNotEmpty($streamContent);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($tempFile, $streamContent);

        $zip = new ZipArchive();
        $res = $zip->open($tempFile);
        $this->assertTrue($res === true, 'Downloaded file must be a valid ZipArchive');

        // Check essential OpenXML parts
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'), 'Must contain [Content_Types].xml');
        $this->assertNotFalse($zip->locateName('_rels/.rels'), 'Must contain _rels/.rels');
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'Must contain xl/workbook.xml');
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'), 'Must contain xl/worksheets/sheet1.xml');
        $this->assertNotFalse($zip->locateName('xl/styles.xml'), 'Must contain xl/styles.xml');

        // Inspect worksheet content
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tempFile);

        // Verify well-formed XML
        $xmlDoc = simplexml_load_string($sheetXml);
        $this->assertNotFalse($xmlDoc, 'Worksheet XML must be well-formed');

        // Check header columns presence
        $this->assertStringContainsString('Verification ID', $sheetXml);
        $this->assertStringContainsString('Late Status', $sheetXml);
        $this->assertStringContainsString('Late Window Date', $sheetXml);
        $this->assertStringContainsString('Movement Source', $sheetXml);
        $this->assertStringContainsString('Roll Number', $sheetXml);

        // Check XML entity escaping for special characters
        $this->assertStringContainsString('City Hospital &amp; Clinic &lt;Emergency&gt;', $sheetXml);
        $this->assertStringContainsString('Checkup &quot;Urgent&quot;', $sheetXml);

        // Verify Audit Log creation
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ADMIN_MOVEMENT_EXPORT',
            'module' => 'MOVEMENT',
            'user_id' => $this->adminUser->id,
            'status' => AuditLog::STATUS_SUCCESS,
        ]);
    }

    public function test_admin_can_export_movements_as_csv_fallback(): void
    {
        $this->setSimulationTime('2026-09-21 22:10:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Hostel',
            'purpose' => 'Night',
        ])->assertStatus(200);
        Carbon::setTestNow();

        $response = $this->actingAs($this->adminUser, 'sanctum')->get('/api/v1/admin/movements/export?format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Verification ID', $content);
        $this->assertStringContainsString('LATE', $content);
    }

    // =========================================================================
    // 7. EXPORT RBAC SECURITY
    // =========================================================================

    public function test_student_and_security_cannot_export_movements(): void
    {
        // Unauthenticated forbidden (test first before any actingAs)
        $respGuest = $this->getJson('/api/v1/admin/movements/export');
        $respGuest->assertStatus(401);

        // Student forbidden
        $respStudent = $this->actingAs($this->studentUser, 'sanctum')->getJson('/api/v1/admin/movements/export');
        $respStudent->assertStatus(403);

        // Security forbidden
        $respSecurity = $this->actingAs($this->guardUser, 'sanctum')->getJson('/api/v1/admin/movements/export');
        $respSecurity->assertStatus(403);
    }

    // =========================================================================
    // 8. MOVEMENT ANALYTICS & STUDENT REPORT
    // =========================================================================

    public function test_admin_movement_analytics_endpoint(): void
    {
        $student2 = $this->createExtraStudent('CS2024002', 'Bhavna Roy');

        // Record 1 daytime OUT
        $this->setSimulationTime('2026-09-21 11:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Market',
            'purpose' => 'Groceries',
        ])->assertStatus(200);

        // Record 1 daytime IN
        $this->setSimulationTime('2026-09-21 15:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'IN',
        ])->assertStatus(200);

        // Record 1 late OUT
        $this->setSimulationTime('2026-09-21 23:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $student2->id,
            'type' => 'OUT',
            'destination' => 'Station',
            'purpose' => 'Travel',
        ])->assertStatus(200);

        Carbon::setTestNow();

        $response = $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/admin/reports/movements');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Total movements: 3
        $response->assertJsonPath('data.summary.total_movements', 3);
        $response->assertJsonPath('data.summary.in_count', 1);
        $response->assertJsonPath('data.summary.out_count', 2);
        $response->assertJsonPath('data.summary.late_count', 1);
        $response->assertJsonPath('data.summary.normal_count', 2);
        $response->assertJsonPath('data.summary.unique_students', 2);

        // Gate stats
        $gateStats = $response->json('data.gate_stats');
        $this->assertCount(1, $gateStats);
        $this->assertEquals(3, $gateStats[0]['total']);
        $this->assertEquals(1, $gateStats[0]['late']);

        // Source stats
        $sourceStats = $response->json('data.source_stats');
        $this->assertNotEmpty($sourceStats);
        $manualStats = collect($sourceStats)->firstWhere('movement_source', 'SECURITY_MANUAL');
        $this->assertNotNull($manualStats);
        $this->assertEquals(3, $manualStats['total']);
        $this->assertEquals(1, $manualStats['late']);
    }

    public function test_admin_student_specific_report_endpoint(): void
    {
        // 1 Late OUT for student 1
        $this->setSimulationTime('2026-09-21 23:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'OUT',
            'destination' => 'Station',
            'purpose' => 'Travel',
        ])->assertStatus(200);

        // 1 Normal IN for student 1
        $this->setSimulationTime('2026-09-22 10:00:00');
        $this->actingAs($this->guardUser, 'sanctum')->postJson('/api/v1/security/manual-movement', [
            'student_id' => $this->student->id,
            'type' => 'IN',
        ])->assertStatus(200);

        Carbon::setTestNow();

        $response = $this->actingAs($this->adminUser, 'sanctum')->getJson("/api/v1/admin/reports/students/{$this->student->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_movements', 2);
        $response->assertJsonPath('data.late_movements_count', 1);
        $response->assertJsonPath('data.normal_movements_count', 1);
        $response->assertJsonPath('data.late_rate_percentage', 50);
        $response->assertJsonPath('data.student.roll_number', 'CS2024001');
    }
}
