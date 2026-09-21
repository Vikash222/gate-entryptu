<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Gate $gate;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = Gate::create([
            'name' => 'Gate 1 (Main Entrance)',
            'code' => 'GATE-1',
            'location' => 'North Perimeter',
            'is_active' => true,
        ]);

        $this->adminUser = User::create([
            'name' => 'Campus Admin',
            'email' => 'admin@ptu.ac.in',
            'password' => bcrypt('AdminSecret123!'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->adminToken = $this->adminUser->createToken('admin_token')->plainTextToken;

        $guard = User::create([
            'name' => 'Duty Officer',
            'email' => 'guard@ptu.ac.in',
            'password' => bcrypt('GuardSecret123!'),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        SecurityDutySession::create([
            'user_id' => $guard->id,
            'gate_id' => $this->gate->id,
            'started_at' => now('Asia/Kolkata'),
            'status' => SecurityDutySession::STATUS_ACTIVE,
        ]);
    }

    protected function asAdmin()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$this->adminToken}");
    }

    protected function asStudent(string $token)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Helper to get sample registration payload
     */
    protected function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahul Sharma',
            'roll_number' => '2026/BTECH/CS/042',
            'student_id' => 'STU-2026-042',
            'student_type' => 'HOSTELLER',
            'year' => 3,
            'program' => 'B.Tech Computer Science',
            'department' => 'Computer Science & Engineering',
            'email' => 'rahul.sharma@ptu.ac.in',
            'phone_number' => '+919876500001',
            'password' => 'SecureStudent123!',
            'password_confirmation' => 'SecureStudent123!',
        ], $overrides);
    }

    /**
     * TEST 1: Student self-registration creates User & Student with status PENDING.
     */
    public function test_1_student_self_registration_creates_pending_account(): void
    {
        $payload = $this->validRegistrationData();

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'student' => [
                        'roll_number' => '2026/BTECH/CS/042',
                        'name' => 'Rahul Sharma',
                        'email' => 'rahul.sharma@ptu.ac.in',
                        'status' => Student::STATUS_PENDING,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'rahul.sharma@ptu.ac.in',
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('students', [
            'roll_number' => '2026/BTECH/CS/042',
            'email' => 'rahul.sharma@ptu.ac.in',
            'status' => Student::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STUDENT_REGISTERED',
            'module' => 'AUTH',
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * TEST 2: PENDING student can log in, but Gate Entry operations return HTTP 403.
     */
    public function test_2_pending_student_login_succeeds_but_gate_entry_is_forbidden(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData())->assertStatus(201);

        // Login with pending credentials
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'rahul.sharma@ptu.ac.in',
            'password' => 'SecureStudent123!',
        ]);

        $loginRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'rahul.sharma@ptu.ac.in',
                        'role' => 'STUDENT',
                        'status' => 'PENDING',
                    ],
                ],
            ]);

        $token = $loginRes->json('data.token');

        // Profile reading works
        $profileRes = $this->asStudent($token)
            ->getJson('/api/v1/student/profile');
        $profileRes->assertStatus(200);

        // Gate QR verification MUST BE FORBIDDEN (HTTP 403)
        $qrService = app(QrTokenService::class);
        $qrData = $qrService->generateToken($this->gate);

        $verifyRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/verify-qr', [
                'qr_payload' => $qrData['qr_payload'],
            ]);
        $verifyRes->assertStatus(403)
            ->assertJsonPath('success', false);

        // Movement IN & OUT MUST ALSO BE FORBIDDEN (HTTP 403)
        $inRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/in', ['gate_session_token' => 'dummy-session']);
        $inRes->assertStatus(403);

        $outRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/out', [
                'gate_session_token' => 'dummy-session',
                'destination' => 'Market',
                'purpose' => 'Personal Work',
                'vehicle_present' => false,
            ]);
        $outRes->assertStatus(403);
    }

    /**
     * TEST 3: Admin approves pending student -> status becomes ACTIVE + audit log logged.
     */
    public function test_3_admin_approves_pending_student(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData())->assertStatus(201);
        $student = Student::where('roll_number', '2026/BTECH/CS/042')->firstOrFail();

        // Admin checks pending student list
        $listRes = $this->asAdmin()
            ->getJson('/api/v1/admin/students?status=PENDING');
        $listRes->assertStatus(200)
            ->assertJsonFragment(['roll_number' => '2026/BTECH/CS/042']);

        // Admin views single student details
        $showRes = $this->asAdmin()
            ->getJson("/api/v1/admin/students/{$student->id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.roll_number', '2026/BTECH/CS/042');

        // Admin approves student
        $approveRes = $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/approve");

        $approveRes->assertStatus(200)
            ->assertJsonPath('data.status', Student::STATUS_ACTIVE);

        $this->assertEquals(Student::STATUS_ACTIVE, $student->fresh()->status);
        $this->assertEquals(User::STATUS_ACTIVE, $student->user->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STUDENT_APPROVED',
            'module' => 'ADMIN',
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * TEST 4: ACTIVE student can successfully verify Gate QR and record movement.
     */
    public function test_4_approved_active_student_can_verify_qr_and_record_movement(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData())->assertStatus(201);
        $student = Student::where('roll_number', '2026/BTECH/CS/042')->firstOrFail();

        // Admin approves
        $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/approve")
            ->assertStatus(200);

        // Student logs in
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'rahul.sharma@ptu.ac.in',
            'password' => 'SecureStudent123!',
        ])->assertStatus(200);

        $token = $loginRes->json('data.token');

        // Active student verifies QR -> SUCCESS (HTTP 200)
        $qrService = app(QrTokenService::class);
        $qrData = $qrService->generateToken($this->gate);

        $verifyRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/verify-qr', [
                'qr_payload' => $qrData['qr_payload'],
            ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $sessionToken = $verifyRes->json('data.gate_session_token');
        $this->assertNotEmpty($sessionToken);

        // Student exits gate (OUT) -> SUCCESS (HTTP 200)
        $outRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/out', [
                'gate_session_token' => $sessionToken,
                'destination' => 'Market',
                'purpose' => 'Personal Work',
                'vehicle_present' => false,
            ]);

        $outRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['receipt']]);

        $this->assertEquals(Student::STATE_OUTSIDE, $student->fresh()->current_status);
    }

    /**
     * TEST 5: Admin rejects student -> status becomes REJECTED and gate access is blocked.
     */
    public function test_5_admin_rejects_student_and_gate_access_is_forbidden(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData())->assertStatus(201);
        $student = Student::where('roll_number', '2026/BTECH/CS/042')->firstOrFail();

        // Admin rejects
        $rejectRes = $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/reject");

        $rejectRes->assertStatus(200)
            ->assertJsonPath('data.status', Student::STATUS_REJECTED);

        $this->assertEquals(Student::STATUS_REJECTED, $student->fresh()->status);
        $this->assertEquals(User::STATUS_REJECTED, $student->user->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STUDENT_REJECTED',
            'module' => 'ADMIN',
            'status' => 'SUCCESS',
        ]);

        // Attempt login -> Rejected user blocked (HTTP 403)
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'rahul.sharma@ptu.ac.in',
            'password' => 'SecureStudent123!',
        ]);

        $loginRes->assertStatus(403);
    }

    /**
     * TEST 6: Admin suspends student -> gate access is immediately revoked (HTTP 403).
     */
    public function test_6_admin_suspends_student_and_access_revoked_immediately(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData())->assertStatus(201);
        $student = Student::where('roll_number', '2026/BTECH/CS/042')->firstOrFail();

        // Approve first
        $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/approve")
            ->assertStatus(200);

        // Student logs in and obtains token
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'rahul.sharma@ptu.ac.in',
            'password' => 'SecureStudent123!',
        ])->assertStatus(200);
        $token = $loginRes->json('data.token');

        // Admin suspends student
        $suspendRes = $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/suspend");

        $suspendRes->assertStatus(200)
            ->assertJsonPath('data.status', Student::STATUS_SUSPENDED);

        $this->assertEquals(Student::STATUS_SUSPENDED, $student->fresh()->status);
        $this->assertEquals(User::STATUS_SUSPENDED, $student->user->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STUDENT_SUSPENDED',
            'module' => 'ADMIN',
            'status' => 'SUCCESS',
        ]);

        // Gate operation with token MUST BE FORBIDDEN (HTTP 403)
        $qrService = app(QrTokenService::class);
        $qrData = $qrService->generateToken($this->gate);

        $verifyRes = $this->asStudent($token)
            ->postJson('/api/v1/gate-entry/verify-qr', [
                'qr_payload' => $qrData['qr_payload'],
            ]);

        $verifyRes->assertStatus(403);
    }

    /**
     * TEST 7: Duplicate Roll Number is rejected (HTTP 422).
     */
    public function test_7_duplicate_roll_number_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/001',
            'email' => 'student1@ptu.ac.in',
            'phone_number' => '+919000000001',
        ]))->assertStatus(201);

        // Attempt duplicate roll number
        $dupResponse = $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/001',
            'email' => 'student2@ptu.ac.in',
            'phone_number' => '+919000000002',
        ]));

        $dupResponse->assertStatus(422)
            ->assertJsonValidationErrors(['roll_number']);
    }

    /**
     * TEST 8: Duplicate Email is rejected (HTTP 422).
     */
    public function test_8_duplicate_email_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/002',
            'email' => 'common.email@ptu.ac.in',
            'phone_number' => '+919000000003',
        ]))->assertStatus(201);

        // Attempt duplicate email
        $dupResponse = $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/003',
            'email' => 'common.email@ptu.ac.in',
            'phone_number' => '+919000000004',
        ]));

        $dupResponse->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * TEST 9: Duplicate Phone Number is rejected (HTTP 422).
     */
    public function test_9_duplicate_phone_number_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/004',
            'email' => 'student4@ptu.ac.in',
            'phone_number' => '+919999888877',
        ]))->assertStatus(201);

        // Attempt duplicate phone number
        $dupResponse = $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'UNIQUE/2026/005',
            'email' => 'student5@ptu.ac.in',
            'phone_number' => '+919999888877',
        ]));

        $dupResponse->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    /**
     * TEST 10: Student cannot modify Roll Number or Student ID via profile update (HTTP 422).
     */
    public function test_10_student_cannot_modify_roll_number_via_profile(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->validRegistrationData([
            'roll_number' => 'TAMPER/2026/001',
            'student_id' => 'STU-ORIGINAL-001',
            'email' => 'tamper.student@ptu.ac.in',
            'phone_number' => '+919876543299',
        ]))->assertStatus(201);

        $student = Student::where('roll_number', 'TAMPER/2026/001')->firstOrFail();

        // Admin approves
        $this->asAdmin()
            ->postJson("/api/v1/admin/students/{$student->id}/approve")
            ->assertStatus(200);

        // Student logs in
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'tamper.student@ptu.ac.in',
            'password' => 'SecureStudent123!',
        ])->assertStatus(200);

        $token = $loginRes->json('data.token');

        // Attempt to alter Roll Number
        $tamperRollRes = $this->asStudent($token)
            ->putJson('/api/v1/student/profile', [
                'roll_number' => 'MODIFIED/2026/999',
            ]);

        $tamperRollRes->assertStatus(422)
            ->assertJsonPath('errors.roll_number.0', 'Roll Number cannot be modified directly.');

        // Attempt to alter Student ID
        $tamperIdRes = $this->asStudent($token)
            ->putJson('/api/v1/student/profile', [
                'student_id' => 'STU-HACKED-999',
            ]);

        $tamperIdRes->assertStatus(422)
            ->assertJsonPath('errors.student_id.0', 'Student ID cannot be modified directly.');

        // Update allowed fields (e.g., semester, batch, phone) succeeds
        $validUpdateRes = $this->asStudent($token)
            ->putJson('/api/v1/student/profile', [
                'semester' => 6,
                'batch' => '2023-2027',
                'phone_number' => '+919876543290',
            ]);

        $validUpdateRes->assertStatus(200);
        $this->assertEquals(6, $student->fresh()->semester);
        $this->assertEquals('TAMPER/2026/001', $student->fresh()->roll_number);
    }
}
