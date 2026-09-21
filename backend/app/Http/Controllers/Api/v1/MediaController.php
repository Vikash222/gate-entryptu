<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Models\User;
use App\Services\DutySessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService
    ) {}

    /**
     * Stream an authorized student's profile photo.
     * Enforces strict RBAC:
     * - Admin: Can view all students
     * - Student: Can view ONLY their own photo
     * - Security: Can view photo ONLY while on an active Admin-authorized duty session
     */
    public function studentPhoto(Request $request, Student $student): BinaryFileResponse
    {
        $user = $request->user();

        // 1. Admin authorization: full institutional access
        if ($user->isAdmin()) {
            // Authorized
        }
        // 2. Student authorization: strictly self-only
        elseif ($user->isStudent()) {
            if (!$user->student || $user->student->id !== $student->id) {
                abort(403, 'Access denied. Students are strictly restricted to their own profile photo.');
            }
        }
        // 3. Security Guard authorization: requires active duty session at an authorized gate
        elseif ($user->isSecurity()) {
            $activeSession = $this->dutySessionService->getActiveSession($user->id);
            if (!$activeSession || !$activeSession->isActive()) {
                abort(403, 'Operational access denied: Security guards can only view student photos while on active gate duty.');
            }
        } else {
            abort(403, 'Access denied. Insufficient permissions.');
        }

        // 4. File existence verification
        if (!$student->profile_photo || !Storage::disk('local')->exists($student->profile_photo)) {
            abort(404, 'Student profile photo not found.');
        }

        $fullPath = Storage::disk('local')->path($student->profile_photo);
        $mime = Storage::disk('local')->mimeType($student->profile_photo) ?: 'image/webp';
        $response = response()->file($fullPath, [
            'Content-Type' => $mime,
        ]);

        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
