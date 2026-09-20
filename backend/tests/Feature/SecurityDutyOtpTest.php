<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\GateSession;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityDutyOtpTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected Gate $gate;
    protected DutySessionService $dutyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dutyService = app(DutySessionService::class);

        $this->adminUser = User::create([
            'name' => 'Campus Admin',
            'email' => 'admin.sec@ptu.ac.in',
            'password' => bcrypt('AdminSecure123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        $this->guardUser = User::create([
            'name' => 'Gate Officer 1',
            'email' => 'guard.gate1@ptu.ac.in',
            'password' => bcrypt('GuardSecure123!'),
            'role' => 'SECURITY',
            'status' => 'ACTIVE',
        ]);

        $this->gate = Gate::create([
            'name' => 'Gate 1 (Main Entrance)',
            'code' => 'GATE-1',
            'location' => 'North Perimeter',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * TEST 1:
     * Security ID + password only
     * -> Login successful
     * -> Dashboard operational features remain locked (HTTP 403)
     * -> QR generation unavailable (HTTP 403)
     * -> Movement APIs rejected
     */
    public function test_scenario_1_security_authenticated_without_admin_otp_is_strictly_locked(): void
    {
        // 1. Authenticate Guard with ID + Password
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'guard.gate1@ptu.ac.in',
            'password' => 'GuardSecure123!',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'role' => 'SECURITY',
                    ],
                ],
            ]);

        $token = $loginResponse->json('data.token');

        // 2. Pre-duty check shows NO active duty
        $statusResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/duty/active');

        $statusResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_active_duty' => false,
                    'duty_session' => null,
                ],
            ]);

        // 3. Operational features must be BLOCKED (HTTP 403)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/today')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'errors' => [
                    'code' => 'NO_ACTIVE_DUTY_SESSION',
                ],
            ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/students/search?query=2026')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/security/history')
            ->assertStatus(403);

        // 4. Temporary Gate QR generation must be REJECTED (HTTP 403)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/gates/{$this->gate->id}/qr")
            ->assertStatus(403);
    }

    /**
     * TEST 2:
     * Security ID + password + valid Admin OTP
     * -> Duty ACTIVE
     * -> Gate assigned
     * -> Security dashboard unlocked
     * -> QR generation available
     * -> Live movements available
     */
    public function test_scenario_2_valid_admin_otp_authorizes_duty_and_unlocks_operational_dashboard(): void
    {
        // Admin generates duty authorization OTP
        $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $this->guardUser->id);
        $validOtp = $otpData['otp'];

        // Guard submits gate_id + admin_otp
        $activateResponse = $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => $validOtp,
            ]);

        $activateResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'gate_id' => $this->gate->id,
                ],
            ]);

        $this->assertDatabaseHas('security_duty_sessions', [
            'user_id' => $this->guardUser->id,
            'gate_id' => $this->gate->id,
            'status' => 'ACTIVE',
        ]);

        // Verify OTP is burned (used_at is not null)
        $this->assertDatabaseMissing('admin_duty_otps', [
            'used_at' => null,
            'admin_user_id' => $this->adminUser->id,
        ]);

        // Operational features are now UNLOCKED (HTTP 200)
        $this->actingAs($this->guardUser)
            ->getJson('/api/v1/security/today')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->actingAs($this->guardUser)
            ->getJson('/api/v1/security/students/search?query=test')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // QR generation is available
        $qrResponse = $this->actingAs($this->guardUser)
            ->postJson("/api/v1/gates/{$this->gate->id}/qr");

        $qrResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'gate_id' => $this->gate->id,
                    'lifetime_seconds' => 30,
                ],
            ]);
    }

    /**
     * TEST 3:
     * Expired or invalid Admin OTP
     * -> Duty NOT activated
     * -> Operational dashboard remains locked
     * -> Audit log recorded
     */
    public function test_scenario_3_invalid_or_expired_admin_otp_is_rejected(): void
    {
        // 1. Test completely invalid OTP
        $responseInvalid = $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => '999999',
            ]);

        $responseInvalid->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DUTY_OTP_VERIFICATION_FAILED',
            'status' => 'FAILED',
        ]);

        // Operational APIs remain locked
        $this->actingAs($this->guardUser)
            ->getJson('/api/v1/security/today')
            ->assertStatus(403);

        // 2. Test Expired OTP
        $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $this->guardUser->id, ttlMinutes: 10);
        Carbon::setTestNow(Carbon::now('Asia/Kolkata')->addMinutes(15));

        $responseExpired = $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => $otpData['otp'],
            ]);

        $responseExpired->assertStatus(422);

        Carbon::setTestNow();
    }

    /**
     * TEST 4:
     * Security session expires or END DUTY
     * -> Operational access immediately revoked
     * -> QR generation rejected
     * -> Movement creation rejected
     */
    public function test_scenario_4_duty_ended_immediately_revokes_operational_access(): void
    {
        // Setup active duty
        $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $this->guardUser->id);
        $this->dutyService->activateDuty($this->guardUser, $this->gate->id, $otpData['otp']);

        // Verify guard can generate QR
        $this->actingAs($this->guardUser)
            ->postJson("/api/v1/gates/{$this->gate->id}/qr")
            ->assertStatus(200);

        // Guard ENDS DUTY
        $endResponse = $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/end');

        $endResponse->assertStatus(200);

        $this->assertDatabaseHas('security_duty_sessions', [
            'user_id' => $this->guardUser->id,
            'status' => 'ENDED',
        ]);

        // Operational access is IMMEDIATELY REVOKED
        $this->actingAs($this->guardUser)
            ->getJson('/api/v1/security/today')
            ->assertStatus(403);

        $this->actingAs($this->guardUser)
            ->postJson("/api/v1/gates/{$this->gate->id}/qr")
            ->assertStatus(403);

        // Student movement creation at this unstaffed gate must be REJECTED
        $studentUser = User::create([
            'name' => 'Student Test',
            'email' => 'student.unstaffed@ptu.ac.in',
            'password' => bcrypt('password'),
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_id' => 'STU-9999',
            'name' => 'Student Test',
            'roll_number' => '2026/CS/999',
            'email' => 'student.unstaffed@ptu.ac.in',
            'phone_number' => '+919999988888',
            'current_status' => 'INSIDE',
            'is_active' => true,
        ]);
        $gateSession = GateSession::create([
            'session_token' => 'sess-unstaffed-123',
            'user_id' => $studentUser->id,
            'student_id' => $student->id,
            'gate_id' => $this->gate->id,
            'status' => 'PENDING',
            'expires_at' => now('Asia/Kolkata')->addMinutes(3),
        ]);

        $movementResponse = $this->actingAs($studentUser)
            ->postJson('/api/v1/gate-entry/out', [
                'gate_session_token' => $gateSession->session_token,
                'destination' => 'Market',
                'purpose' => 'Personal',
                'vehicle_present' => false,
            ]);

        $movementResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * TEST 5:
     * Admin Security Roster reflects exact duty state, assigned gate, and duty timestamps.
     * -> Guard OFF DUTY: duty_status = 'OFF_DUTY', current_gate = null, duty_ended_at recorded
     * -> Guard ON DUTY: duty_status = 'ACTIVE', current_gate = Gate 1, duty_started_at recorded
     * -> Guard ENDS DUTY: duty_status = 'OFF_DUTY', current_gate = null, duty_ended_at updated
     */
    public function test_scenario_5_admin_security_panel_reflects_active_duty_gate_and_timestamps(): void
    {
        // 1. Initially, Guard is OFF DUTY
        $initialRoster = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/security');

        $initialRoster->assertStatus(200);
        $guardData = collect($initialRoster->json('data'))->firstWhere('id', $this->guardUser->id);
        $this->assertNotNull($guardData);
        $this->assertEquals('OFF_DUTY', $guardData['duty_status']);
        $this->assertFalse($guardData['is_on_duty']);
        $this->assertNull($guardData['current_gate']);
        $this->assertEquals('-', $guardData['current_gate_name']);
        $this->assertNull($guardData['duty_started_at']);

        // 2. Admin generates OTP and Guard activates duty at Gate 1
        $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $this->guardUser->id);
        $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => $otpData['otp'],
            ])
            ->assertStatus(200);

        // 3. Admin Roster immediately reflects ON DUTY with assigned Gate 1
        $activeRoster = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/security');

        $activeRoster->assertStatus(200);
        $activeGuardData = collect($activeRoster->json('data'))->firstWhere('id', $this->guardUser->id);
        $this->assertNotNull($activeGuardData);
        $this->assertEquals('ACTIVE', $activeGuardData['duty_status']);
        $this->assertTrue($activeGuardData['is_on_duty']);
        $this->assertNotNull($activeGuardData['current_gate']);
        $this->assertEquals($this->gate->id, $activeGuardData['current_gate']['id']);
        $this->assertEquals($this->gate->name, $activeGuardData['current_gate_name']);
        $this->assertNotNull($activeGuardData['duty_started_at']);
        $this->assertNull($activeGuardData['duty_ended_at']);

        // 4. Guard ENDS DUTY
        $this->actingAs($this->guardUser)
            ->postJson('/api/v1/security/duty/end')
            ->assertStatus(200);

        // 5. Admin Roster immediately reflects OFF DUTY with current_gate = null and duty_ended_at timestamp
        $endedRoster = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/security');

        $endedRoster->assertStatus(200);
        $endedGuardData = collect($endedRoster->json('data'))->firstWhere('id', $this->guardUser->id);
        $this->assertNotNull($endedGuardData);
        $this->assertEquals('OFF_DUTY', $endedGuardData['duty_status']);
        $this->assertFalse($endedGuardData['is_on_duty']);
        $this->assertNull($endedGuardData['current_gate']);
        $this->assertEquals('-', $endedGuardData['current_gate_name']);
        $this->assertNull($endedGuardData['duty_started_at']);
        $this->assertNotNull($endedGuardData['duty_ended_at']);
    }

    /**
     * TEST 6:
     * Maximum 4 active duty sessions transactionally enforced.
     * -> 4 guards can activate duty
     * -> 5th guard is rejected with HTTP 422
     * -> Releasing a slot allows the 5th guard to activate duty
     */
    public function test_scenario_6_maximum_4_active_duty_sessions_strictly_enforced(): void
    {
        $gate2 = Gate::create([
            'name' => 'Gate 2 (South Gate)',
            'code' => 'GATE-2',
            'location' => 'South Perimeter',
            'status' => 'ACTIVE',
        ]);

        $guards = [];
        for ($i = 1; $i <= 5; $i++) {
            $guards[$i] = User::create([
                'name' => "Security Officer {$i}",
                'email' => "officer{$i}@ptu.ac.in",
                'password' => bcrypt('password'),
                'role' => 'SECURITY',
                'status' => 'ACTIVE',
            ]);
        }

        // Activate duty for officers 1 through 4 (Max 4 capacity)
        for ($i = 1; $i <= 4; $i++) {
            $assignedGate = ($i % 2 === 0) ? $gate2 : $this->gate;
            $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $assignedGate->id, $guards[$i]->id);
            $res = $this->actingAs($guards[$i])
                ->postJson('/api/v1/security/duty/activate', [
                    'gate_id' => $assignedGate->id,
                    'admin_otp' => $otpData['otp'],
                ]);
            $res->assertStatus(200);
        }

        $this->assertEquals(4, SecurityDutySession::where('status', 'ACTIVE')->count());

        // Attempt to activate Officer 5 (5th concurrent duty) -> Must be rejected (HTTP 422)
        $otp5 = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $guards[5]->id);
        $rejectedResponse = $this->actingAs($guards[5])
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => $otp5['otp'],
            ]);

        $rejectedResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Maximum active Security sessions (4) reached. Another guard must end duty first.',
            ]);

        // Officer 1 ends duty -> Slot released
        $this->actingAs($guards[1])
            ->postJson('/api/v1/security/duty/end')
            ->assertStatus(200);

        $this->assertEquals(3, SecurityDutySession::where('status', 'ACTIVE')->count());

        // Officer 5 can now activate duty successfully
        $newOtp5 = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $guards[5]->id);
        $successResponse = $this->actingAs($guards[5])
            ->postJson('/api/v1/security/duty/activate', [
                'gate_id' => $this->gate->id,
                'admin_otp' => $newOtp5['otp'],
            ]);

        $successResponse->assertStatus(200);
        $this->assertEquals(4, SecurityDutySession::where('status', 'ACTIVE')->count());
    }

    /**
     * TEST 7:
     * Admin can force-end an active duty session from the Admin panel.
     * -> Session status changes to ENDED
     * -> Officer's operational access is immediately revoked
     * -> Duty slot is released
     */
    public function test_scenario_7_admin_can_force_end_duty_session_and_release_slot(): void
    {
        // 1. Guard activates duty
        $otpData = $this->dutyService->generateAdminOtp($this->adminUser, $this->gate->id, $this->guardUser->id);
        $session = $this->dutyService->activateDuty($this->guardUser, $this->gate->id, $otpData['otp']);

        $this->assertEquals('ACTIVE', $session->status);

        // 2. Admin forcefully ends duty from Admin panel
        $forceEndResponse = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/admin/security/sessions/{$session->id}/force-end");

        $forceEndResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Duty session forcefully ended. Slot released.',
            ]);

        // 3. Verify session in DB is now ENDED
        $session->refresh();
        $this->assertEquals('ENDED', $session->status);
        $this->assertNotNull($session->ended_at);

        // 4. Guard's operational dashboard is now locked (HTTP 403)
        $this->actingAs($this->guardUser)
            ->getJson('/api/v1/security/today')
            ->assertStatus(403);

        $this->actingAs($this->guardUser)
            ->postJson("/api/v1/gates/{$this->gate->id}/qr")
            ->assertStatus(403);
    }
}
