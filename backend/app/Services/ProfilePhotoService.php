<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfilePhotoService
{
    public const MAX_BYTES = 102400; // Hard server-side limit: exactly 100 KB
    public const MAX_WIDTH = 2000;
    public const MAX_HEIGHT = 2000;
    public const TARGET_MAX_DIMENSION = 600;

    protected array $allowedMimes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    protected array $allowedExtensions = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Strictly validate the uploaded profile photo file.
     * Enforces size, extension, MIME type, and safe dimensions.
     *
     * @throws ValidationException
     */
    public function validateImage(UploadedFile $file): void
    {
        // 1. Authoritative byte size check (100 KB hard limit)
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Profile photo must be 100 KB or smaller.'],
            ]);
        }

        // 2. Extension validation
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $this->allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Invalid file format. Only JPEG, PNG, and WebP images are allowed.'],
            ]);
        }

        // 3. True MIME type detection via fileinfo
        $mime = $file->getMimeType();
        if (!$mime || !in_array(strtolower($mime), $this->allowedMimes, true)) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Invalid file content. Disguised files or unsupported formats are rejected.'],
            ]);
        }

        // 4. Inspect image dimensions safely without decoding full image into memory upfront
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'profile_photo' => ['The uploaded file is not a valid or readable image.'],
            ]);
        }

        [$width, $height] = $imageInfo;
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Image dimensions exceed the maximum allowed limit of 2000x2000 pixels.'],
            ]);
        }
    }

    /**
     * Process, normalize, optimize, and store the student's profile photo.
     * Guarantees atomic replacement: old photo is deleted only AFTER new photo is safely saved.
     */
    public function processAndStore(UploadedFile $file, Student $student, ?User $actor = null): string
    {
        $this->validateImage($file);

        $oldPhotoPath = $student->profile_photo;
        $realPath = $file->getRealPath();
        $mime = strtolower($file->getMimeType());

        // Create GD resource safely
        $sourceImage = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $sourceImage = @imagecreatefromjpeg($realPath);
            // Check EXIF orientation if available
            if ($sourceImage && function_exists('exif_read_data')) {
                $exif = @exif_read_data($realPath);
                if (!empty($exif['Orientation'])) {
                    switch ($exif['Orientation']) {
                        case 3:
                            $sourceImage = imagerotate($sourceImage, 180, 0);
                            break;
                        case 6:
                            $sourceImage = imagerotate($sourceImage, -90, 0);
                            break;
                        case 8:
                            $sourceImage = imagerotate($sourceImage, 90, 0);
                            break;
                    }
                }
            }
        } elseif ($mime === 'image/png') {
            $sourceImage = @imagecreatefrompng($realPath);
        } elseif ($mime === 'image/webp') {
            $sourceImage = @imagecreatefromwebp($realPath);
        }

        if (!$sourceImage) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Failed to decode image data safely.'],
            ]);
        }

        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);

        // Calculate aspect-ratio-preserved thumbnail dimensions (max 600px)
        $targetWidth = $origWidth;
        $targetHeight = $origHeight;

        if ($origWidth > self::TARGET_MAX_DIMENSION || $origHeight > self::TARGET_MAX_DIMENSION) {
            if ($origWidth >= $origHeight) {
                $targetWidth = self::TARGET_MAX_DIMENSION;
                $targetHeight = (int) round(($origHeight / $origWidth) * self::TARGET_MAX_DIMENSION);
            } else {
                $targetHeight = self::TARGET_MAX_DIMENSION;
                $targetWidth = (int) round(($origWidth / $origHeight) * self::TARGET_MAX_DIMENSION);
            }
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve transparency for PNG/WebP
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas,
            $sourceImage,
            0, 0, 0, 0,
            $targetWidth,
            $targetHeight,
            $origWidth,
            $origHeight
        );

        // Strip metadata and encode to WebP with iterative quality control (must be <= 100 KB)
        $quality = 85;
        $outputBuffer = null;

        do {
            ob_start();
            if (function_exists('imagewebp')) {
                imagewebp($canvas, null, $quality);
                $ext = 'webp';
            } else {
                imagejpeg($canvas, null, $quality);
                $ext = 'jpg';
            }
            $outputBuffer = ob_get_clean();
            $quality -= 10;
        } while (strlen($outputBuffer) > self::MAX_BYTES && $quality >= 30);

        imagedestroy($sourceImage);
        imagedestroy($canvas);

        if (strlen($outputBuffer) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'profile_photo' => ['Profile photo must be 100 KB or smaller.'],
            ]);
        }

        // Generate safe server-side storage filename (no client-provided filename)
        $safeFilename = sprintf('student_%d_%s.%s', $student->id, Str::random(16), $ext);
        $storagePath = 'profile_photos/' . $safeFilename;

        // Store in private local disk
        Storage::disk('local')->put($storagePath, $outputBuffer);

        // Atomically update Student record
        $student->update(['profile_photo' => $storagePath]);

        // Clean up previous photo file if present (safe cleanup after successful save)
        if ($oldPhotoPath && Storage::disk('local')->exists($oldPhotoPath)) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        // Audit Log
        $action = $oldPhotoPath ? 'STUDENT_PROFILE_PHOTO_REPLACE' : 'STUDENT_PROFILE_PHOTO_UPLOAD';
        if ($actor && $actor->isAdmin()) {
            $action = 'ADMIN_PROFILE_PHOTO_UPDATE';
        }

        $this->auditService->log(
            action: $action,
            module: ($actor && $actor->isAdmin()) ? 'ADMIN' : 'STUDENT',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'student_id' => $student->id,
                'roll_number' => $student->roll_number,
                'file_size_bytes' => strlen($outputBuffer),
                'dimensions' => "{$targetWidth}x{$targetHeight}",
                'format' => $ext,
            ],
            entityType: Student::class,
            entityId: (string) $student->id,
            user: $actor ?? $student->user
        );

        return $storagePath;
    }

    /**
     * Remove the student's profile photo and clean up stored file.
     */
    public function deletePhoto(Student $student, ?User $actor = null): bool
    {
        $oldPhotoPath = $student->profile_photo;
        if (!$oldPhotoPath) {
            return false;
        }

        // Update database first
        $student->update(['profile_photo' => null]);

        // Clean up file from private disk
        if (Storage::disk('local')->exists($oldPhotoPath)) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        // Audit log
        $action = ($actor && $actor->isAdmin()) ? 'ADMIN_PROFILE_PHOTO_UPDATE' : 'STUDENT_PROFILE_PHOTO_DELETE';

        $this->auditService->log(
            action: $action,
            module: ($actor && $actor->isAdmin()) ? 'ADMIN' : 'STUDENT',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'student_id' => $student->id,
                'roll_number' => $student->roll_number,
                'action_detail' => 'Profile photo deleted',
            ],
            entityType: Student::class,
            entityId: (string) $student->id,
            user: $actor ?? $student->user
        );

        return true;
    }
}
