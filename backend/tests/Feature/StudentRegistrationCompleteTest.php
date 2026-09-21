<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentRegistrationCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected Gate $gate;
    protected SecurityDutySession $dutySession;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Student',
            'roll_number' => 'REG2026' . rand(1000, 9999),
            'student_id' => 'STU' . rand(1000, 9999),
            'student_type' => 'HOSTELLER',
            'year' => 2,
            'program' => 'B.Tech IT',
            'department' => 'Information Technology',
            'email' => 'student' . rand(1000, 9999) . '@ptu.ac.in',
            'phone_number' => '+91' . rand(7000000000, 9999999999),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    protected function createValidImage(string $name, int $width = 150, int $height = 150, int $kb = 25): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height)->size($kb);
    }

    /**
     * 1. Registration with valid student_type HOSTELLER.
     */
    public function test_01_registration_with_valid_student_type_hosteller(): void
    {
        $payload = $this->validPayload(['student_type' => 'HOSTELLER']);

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.student.student_type', 'HOSTELLER');

        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student);
        $this->assertEquals('HOSTELLER', $student->student_type);
        $this->assertTrue($student->isHosteler());
        $this->assertFalse($student->isDayScholar());
    }

    /**
     * 2. Registration with valid student_type DAY_SCHOLAR.
     */
    public function test_02_registration_with_valid_student_type_day_scholar(): void
    {
        $payload = $this->validPayload(['student_type' => 'DAY_SCHOLAR']);

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.student.student_type', 'DAY_SCHOLAR');

        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student);
        $this->assertEquals('DAY_SCHOLAR', $student->student_type);
        $this->assertTrue($student->isDayScholar());
        $this->assertFalse($student->isHosteler());
    }

    /**
     * 3. Missing student_type rejected.
     */
    public function test_03_missing_student_type_rejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['student_type']);

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_type']);
    }

    /**
     * 4. Invalid student_type rejected.
     */
    public function test_04_invalid_student_type_rejected(): void
    {
        $payload = $this->validPayload(['student_type' => 'FOREIGN_EXCHANGE']);

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_type']);
    }

    /**
     * 5. Registration with valid JPG <=100 KB.
     */
    public function test_05_registration_with_valid_jpg_under_100kb(): void
    {
        $photo = $this->createValidImage('photo.jpg', 150, 150, 45);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student);
        $this->assertNotNull($student->profile_photo);
        $this->assertTrue(Storage::disk('local')->exists($student->profile_photo));
    }

    /**
     * 6. Registration with valid PNG <=100 KB.
     */
    public function test_06_registration_with_valid_png_under_100kb(): void
    {
        $photo = $this->createValidImage('avatar.png', 120, 120, 50);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student);
        $this->assertNotNull($student->profile_photo);
        $this->assertTrue(Storage::disk('local')->exists($student->profile_photo));
    }

    /**
     * 7. Registration with valid WebP <=100 KB.
     */
    public function test_07_registration_with_valid_webp_under_100kb(): void
    {
        $photo = $this->createValidImage('photo.webp', 100, 100, 30);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student);
        $this->assertNotNull($student->profile_photo);
        $this->assertTrue(Storage::disk('local')->exists($student->profile_photo));
    }

    /**
     * 8. File >100 KB rejected.
     */
    public function test_08_file_over_100kb_rejected(): void
    {
        $photo = $this->createValidImage('huge.jpg', 200, 200, 101);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);
        $this->assertStringContainsString('100 KB', $response->json('errors.profile_photo.0'));
    }

    /**
     * 9. Invalid MIME rejected.
     */
    public function test_09_invalid_mime_rejected(): void
    {
        $photo = UploadedFile::fake()->create('fake_image.jpg', 30, 'application/pdf');
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);
    }

    /**
     * 10. Fake extension rejected.
     */
    public function test_10_fake_extension_rejected(): void
    {
        $photo = UploadedFile::fake()->create('script.php', 20, 'image/jpeg');
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);
    }

    /**
     * 11. Oversized dimensions rejected (>2000x2000).
     */
    public function test_11_oversized_dimensions_rejected(): void
    {
        $photo = $this->createValidImage('giant.jpg', 2500, 2500, 50);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);
        $this->assertStringContainsString('dimensions', strtolower($response->json('errors.profile_photo.0')));
    }

    /**
     * 12. Final normalized image <=100 KB.
     */
    public function test_12_final_normalized_image_under_100kb(): void
    {
        $photo = $this->createValidImage('photo.jpg', 800, 800, 95);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $storedContent = Storage::disk('local')->get($student->profile_photo);
        $this->assertLessThanOrEqual(102400, strlen($storedContent));
    }

    /**
     * 13. Student created as PENDING.
     */
    public function test_13_student_created_as_pending(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertEquals(Student::STATUS_PENDING, $student->status);
        $this->assertEquals(User::STATUS_PENDING, $student->user->status);
    }

    /**
     * 14. Photo stored and linked to student.
     */
    public function test_14_photo_stored_and_linked_to_student(): void
    {
        $photo = $this->createValidImage('avatar.jpg', 150, 150, 40);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertNotNull($student->profile_photo);
        $this->assertTrue(Storage::disk('local')->exists($student->profile_photo));
        $this->assertNotNull($student->profile_photo_url);
    }

    /**
     * 15. student_type stored correctly.
     */
    public function test_15_student_type_stored_correctly(): void
    {
        $payload = $this->validPayload(['student_type' => 'DAY_SCHOLAR']);

        $response = $this->postJson('/api/v1/auth/register-student', $payload);

        $response->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();
        $this->assertEquals('DAY_SCHOLAR', $student->student_type);
    }

    /**
     * 16. Failed registration cleans up uploaded photo.
     */
    public function test_16_failed_registration_cleans_up_uploaded_photo(): void
    {
        // First create a student to conflict on email
        $existingUser = User::factory()->create([
            'email' => 'taken@ptu.ac.in',
        ]);

        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload([
            'email' => 'taken@ptu.ac.in', // Will fail validation before store
            'profile_photo' => $photo,
        ]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422);
        // No files should be stored in profile_photos directory
        $files = Storage::disk('local')->allFiles('profile_photos');
        $this->assertEmpty($files);
    }

    /**
     * 17. Duplicate registration does not leave orphan photo.
     */
    public function test_17_duplicate_registration_does_not_leave_orphan_photo(): void
    {
        $existing = Student::create([
            'user_id' => $this->adminUser->id,
            'student_id' => 'STU-EXIST',
            'roll_number' => 'ROLL-EXIST',
            'name' => 'Existing',
            'email' => 'existing@ptu.ac.in',
            'category' => 'HOSTELER',
            'status' => 'ACTIVE',
        ]);

        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload([
            'roll_number' => 'ROLL-EXIST', // Conflict
            'profile_photo' => $photo,
        ]);

        $response = $this->post('/api/v1/auth/register-student', $payload);

        $response->assertStatus(422);
        $files = Storage::disk('local')->allFiles('profile_photos');
        $this->assertEmpty($files);
    }

    /**
     * 18. Admin can see registration photo.
     */
    public function test_18_admin_can_see_registration_photo(): void
    {
        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $this->post('/api/v1/auth/register-student', $payload)->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->get("/api/v1/media/students/{$student->id}/photo");

        $response->assertStatus(200);
    }

    /**
     * 19. Security can see authorized student's photo during active duty.
     */
    public function test_19_security_can_see_photo_during_active_duty(): void
    {
        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $this->post('/api/v1/auth/register-student', $payload)->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();

        // Active duty guard can view
        $response = $this->actingAs($this->guardUser, 'sanctum')
            ->get("/api/v1/media/students/{$student->id}/photo");

        $response->assertStatus(200);
    }

    /**
     * 20. Student can see own registered photo.
     */
    public function test_20_student_can_see_own_registered_photo(): void
    {
        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $this->post('/api/v1/auth/register-student', $payload)->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();

        $response = $this->actingAs($student->user, 'sanctum')
            ->get("/api/v1/media/students/{$student->id}/photo");

        $response->assertStatus(200);
    }

    /**
     * 21. Unauthorized user cannot access photo.
     */
    public function test_21_unauthorized_user_cannot_access_photo(): void
    {
        $photo = $this->createValidImage('photo.jpg', 100, 100, 30);
        $payload = $this->validPayload(['profile_photo' => $photo]);

        $this->post('/api/v1/auth/register-student', $payload)->assertStatus(201);
        $student = Student::where('roll_number', $payload['roll_number'])->first();

        // 1. Unauthenticated -> 401
        $this->getJson("/api/v1/media/students/{$student->id}/photo")
            ->assertStatus(401);

        // 2. Another student -> 403
        $otherStudentUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'status' => User::STATUS_ACTIVE]);
        Student::create([
            'user_id' => $otherStudentUser->id,
            'student_id' => 'OTHER01',
            'roll_number' => 'OTHER_ROLL',
            'name' => 'Other',
            'email' => $otherStudentUser->email,
            'category' => 'HOSTELER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($otherStudentUser, 'sanctum')
            ->getJson("/api/v1/media/students/{$student->id}/photo")
            ->assertStatus(403);
    }
}
