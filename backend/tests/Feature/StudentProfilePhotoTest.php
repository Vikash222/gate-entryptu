<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $guardUser;
    protected User $studentUser1;
    protected Student $student1;
    protected User $studentUser2;
    protected Student $student2;
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

        // Student 1
        $this->studentUser1 = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->student1 = Student::create([
            'user_id' => $this->studentUser1->id,
            'student_id' => 'STU-001',
            'roll_number' => 'CS2026001',
            'name' => 'Rahul Sharma',
            'email' => $this->studentUser1->email,
            'category' => Student::CATEGORY_HOSTELER,
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_INSIDE,
        ]);

        // Student 2
        $this->studentUser2 = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->student2 = Student::create([
            'user_id' => $this->studentUser2->id,
            'student_id' => 'STU-002',
            'roll_number' => 'CS2026002',
            'name' => 'Aman Singh',
            'email' => $this->studentUser2->email,
            'category' => Student::CATEGORY_DAY_SCHOLAR,
            'status' => Student::STATUS_ACTIVE,
            'current_status' => Student::STATE_OUTSIDE,
        ]);
    }

    /**
     * Helper to generate a valid JPEG image file.
     */
    protected function createValidImage(string $name, int $width = 150, int $height = 150, int $kb = 25): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height)->size($kb);
    }

    public function test_jpeg_under_100kb_accepted(): void
    {
        $file = $this->createValidImage('photo.jpg', 150, 150, 45);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();

        $this->assertNotNull($this->student1->profile_photo);
        $this->assertTrue($this->student1->hasProfilePhoto());
        Storage::disk('local')->assertExists($this->student1->profile_photo);
    }

    public function test_png_under_100kb_accepted(): void
    {
        $file = $this->createValidImage('photo.png', 150, 150, 45);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();

        $this->assertNotNull($this->student1->profile_photo);
        Storage::disk('local')->assertExists($this->student1->profile_photo);
    }

    public function test_webp_under_100kb_accepted(): void
    {
        $file = $this->createValidImage('photo.webp', 150, 150, 30);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();

        $this->assertNotNull($this->student1->profile_photo);
        Storage::disk('local')->assertExists($this->student1->profile_photo);
    }

    public function test_exactly_100kb_accepted(): void
    {
        // 102400 bytes = exactly 100 KB
        $file = $this->createValidImage('exact_100kb.jpg', 100, 100, 100);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();
        $this->assertNotNull($this->student1->profile_photo);
    }

    public function test_100kb_plus_1_byte_rejected(): void
    {
        // 101 KB > 100 KB limit (102,400 bytes)
        $file = $this->createValidImage('too_large.jpg', 200, 200, 101);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
        $this->assertStringContainsString('100 KB', $response->json('errors.profile_photo.0'));
        $this->assertNull($this->student1->fresh()->profile_photo);
    }

    public function test_pdf_rejected(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 30, 'application/pdf');

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_svg_rejected(): void
    {
        $file = UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml');

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_gif_rejected(): void
    {
        $file = UploadedFile::fake()->create('animated.gif', 20, 'image/gif');

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_executable_rejected(): void
    {
        $file = UploadedFile::fake()->create('malware.exe', 40, 'application/x-msdownload');

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_mime_spoof_rejected(): void
    {
        // PDF disguised with a .jpg filename
        $file = UploadedFile::fake()->create('photo.jpg', 35, 'application/pdf');

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_fake_extension_rejected(): void
    {
        // Genuine image but disallowed extension
        $file = UploadedFile::fake()->image('photo.bmp', 100, 100)->size(30);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_dimensions_exceeding_2000px_rejected(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 2500, 2500)->size(60);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_photo']);
        $this->assertStringContainsString('2000', $response->json('errors.profile_photo.0'));
    }

    public function test_final_normalized_image_is_under_100kb(): void
    {
        $file = $this->createValidImage('photo.jpg', 500, 500, 90);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();

        $storedBytes = Storage::disk('local')->size($this->student1->profile_photo);
        $this->assertLessThanOrEqual(102400, $storedBytes);
    }

    public function test_unsafe_filename_cannot_affect_storage_path(): void
    {
        // Attempt path traversal filename
        $file = $this->createValidImage('../../../../etc/passwd.jpg', 150, 150, 30);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();

        // Must be stored under profile_photos/student_{id}_{random}.webp
        $this->assertStringStartsWith('profile_photos/student_' . $this->student1->id . '_', $this->student1->profile_photo);
        $this->assertStringNotContainsString('..', $this->student1->profile_photo);
        $this->assertStringNotContainsString('passwd', $this->student1->profile_photo);
    }

    public function test_student_can_upload_own_photo(): void
    {
        $file = $this->createValidImage('my_photo.jpg', 150, 150, 30);

        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $response->assertStatus(200);
        $this->student1->refresh();
        $this->assertNotNull($this->student1->profile_photo);
    }

    public function test_student_cannot_upload_for_another_student(): void
    {
        $file = $this->createValidImage('malicious.jpg', 150, 150, 30);

        // Student 1 tries calling Admin endpoint for Student 2
        $response = $this->actingAs($this->studentUser1, 'sanctum')
            ->postJson("/api/v1/admin/students/{$this->student2->id}/photo", [
                'profile_photo' => $file,
            ]);

        $response->assertStatus(403);
        $this->assertNull($this->student2->fresh()->profile_photo);
    }

    public function test_student_can_replace_and_delete_photo(): void
    {
        // 1. Initial upload
        $file1 = $this->createValidImage('first.png', 150, 150, 35);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file1,
        ])->assertStatus(200);

        $oldPath = $this->student1->fresh()->profile_photo;
        Storage::disk('local')->assertExists($oldPath);

        // 2. Replace with new photo
        $file2 = $this->createValidImage('second.webp', 150, 150, 30);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file2,
        ])->assertStatus(200);

        $newPath = $this->student1->fresh()->profile_photo;
        $this->assertNotEquals($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);

        // 3. Delete photo
        $this->actingAs($this->studentUser1, 'sanctum')->deleteJson('/api/v1/student/profile/photo')
            ->assertStatus(200);

        $this->assertNull($this->student1->fresh()->profile_photo);
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_old_photo_deleted_only_after_successful_replacement(): void
    {
        // Upload photo 1
        $file1 = $this->createValidImage('p1.jpg', 150, 150, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file1,
        ])->assertStatus(200);

        $p1Path = $this->student1->fresh()->profile_photo;
        Storage::disk('local')->assertExists($p1Path);

        // Upload photo 2 successfully
        $file2 = $this->createValidImage('p2.jpg', 150, 150, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file2,
        ])->assertStatus(200);

        $p2Path = $this->student1->fresh()->profile_photo;
        $this->assertNotEquals($p1Path, $p2Path);
        Storage::disk('local')->assertMissing($p1Path);
        Storage::disk('local')->assertExists($p2Path);
    }

    public function test_failed_replacement_preserves_old_photo(): void
    {
        // Upload initial valid photo
        $file1 = $this->createValidImage('initial.jpg', 150, 150, 30);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file1,
        ])->assertStatus(200);

        $initialPath = $this->student1->fresh()->profile_photo;
        Storage::disk('local')->assertExists($initialPath);

        // Attempt replacement with an invalid oversized file (150 KB)
        $badFile = $this->createValidImage('bad.jpg', 300, 300, 150);
        $response = $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $badFile,
        ]);

        $response->assertStatus(422);

        // Crucial check: old photo MUST still exist on disk and in database
        $this->student1->refresh();
        $this->assertEquals($initialPath, $this->student1->profile_photo);
        Storage::disk('local')->assertExists($initialPath);
    }

    public function test_anonymous_media_access_returns_401(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $photoEndpoint = "/api/v1/media/students/{$this->student1->id}/photo";

        $this->app['auth']->forgetGuards();
        $this->getJson($photoEndpoint)->assertStatus(401);
    }

    public function test_student_a_accessing_student_b_photo_returns_403(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $photoEndpoint = "/api/v1/media/students/{$this->student1->id}/photo";

        // Student 2 tries accessing Student 1's photo
        $this->actingAs($this->studentUser2, 'sanctum')->getJson($photoEndpoint)->assertStatus(403);
    }

    public function test_security_off_duty_returns_403(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $photoEndpoint = "/api/v1/media/students/{$this->student1->id}/photo";

        // End duty session
        $this->dutySession->update(['status' => SecurityDutySession::STATUS_ENDED]);

        $this->actingAs($this->guardUser, 'sanctum')->getJson($photoEndpoint)->assertStatus(403);
    }

    public function test_security_active_duty_returns_200_with_correct_content_type_and_private_cache_headers(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $photoEndpoint = "/api/v1/media/students/{$this->student1->id}/photo";

        $response = $this->actingAs($this->guardUser, 'sanctum')->get($photoEndpoint);

        $response->assertStatus(200);
        $this->assertStringContainsString('image/', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_admin_can_access_any_student_photo(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $photoEndpoint = "/api/v1/media/students/{$this->student1->id}/photo";

        $this->actingAs($this->adminUser, 'sanctum')->get($photoEndpoint)->assertStatus(200);
    }

    public function test_photo_url_does_not_expose_bearer_token(): void
    {
        $file = $this->createValidImage('avatar.jpg', 120, 120, 25);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ]);

        $this->student1->refresh();
        $url = $this->student1->profile_photo_url;

        $this->assertNotNull($url);
        $this->assertStringNotContainsString('token=', $url);
        $this->assertStringNotContainsString('bearer', strtolower($url));
        $this->assertStringEndsWith('/photo', $url);
    }

    public function test_admin_can_upload_and_delete_student_photo(): void
    {
        $file = $this->createValidImage('admin_pic.jpg', 180, 180, 40);

        // Admin uploads photo for Student 2
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/admin/students/{$this->student2->id}/photo", [
                'profile_photo' => $file,
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($this->student2->fresh()->profile_photo);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ADMIN_PROFILE_PHOTO_UPDATE',
            'module' => 'ADMIN',
            'user_id' => $this->adminUser->id,
        ]);

        // Admin deletes photo for Student 2
        $delResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/admin/students/{$this->student2->id}/photo");

        $delResponse->assertStatus(200);
        $this->assertNull($this->student2->fresh()->profile_photo);
    }

    public function test_existing_apis_unaffected_and_audit_logs_created(): void
    {
        // 1. Upload photo as Student 1
        $file = $this->createValidImage('check.jpg', 150, 150, 30);
        $this->actingAs($this->studentUser1, 'sanctum')->postJson('/api/v1/student/profile/photo', [
            'profile_photo' => $file,
        ])->assertStatus(200);

        // 2. Student Profile API returns profile_photo_url
        $profileRes = $this->actingAs($this->studentUser1, 'sanctum')->getJson('/api/v1/student/profile');
        $profileRes->assertStatus(200);
        $this->assertNotNull($profileRes->json('data.profile_photo_url'));

        // 3. Security Search API returns profile_photo_url when on active duty
        $searchRes = $this->actingAs($this->guardUser, 'sanctum')->getJson('/api/v1/security/students/search?roll_number=CS2026001');
        $searchRes->assertStatus(200);
        $this->assertNotNull($searchRes->json('data.0.profile_photo_url'));

        // 4. Security Search API strictly blocks access when off duty (403)
        $this->dutySession->update(['status' => SecurityDutySession::STATUS_ENDED]);
        $searchOffDuty = $this->actingAs($this->guardUser, 'sanctum')->getJson('/api/v1/security/students/search?roll_number=CS2026001');
        $searchOffDuty->assertStatus(403);

        // 5. Audit logs for upload exist
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STUDENT_PROFILE_PHOTO_UPLOAD',
            'module' => 'STUDENT',
            'user_id' => $this->studentUser1->id,
        ]);
    }
}
