<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    use ApiResponse;

    /**
     * Get the authenticated student's profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user->student;

        if (!$student) {
            return $this->notFound('Student record not found for this user account.');
        }

        return $this->success([
            'id' => $student->id,
            'student_id' => $student->student_id,
            'roll_number' => $student->roll_number,
            'name' => $student->name,
            'year' => $student->year,
            'email' => $student->email,
            'phone_number' => $student->phone_number,
            'program' => $student->program,
            'department' => $student->department,
            'semester' => $student->semester,
            'batch' => $student->batch,
            'profile_photo' => $student->profile_photo,
            'status' => $student->status,
            'current_status' => $student->current_status,
            'last_movement_at' => $student->last_movement_at?->toIso8601String(),
            'last_gate' => $student->lastGate ? [
                'id' => $student->lastGate->id,
                'name' => $student->lastGate->name,
                'code' => $student->lastGate->code,
            ] : null,
        ], 'Student profile retrieved successfully.');
    }

    /**
     * Get the authenticated student's current gate presence status (INSIDE / OUTSIDE).
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user->student;

        if (!$student) {
            return $this->notFound('Student record not found.');
        }

        return $this->success([
            'current_status' => $student->current_status,
            'is_inside' => $student->isInside(),
            'is_outside' => $student->isOutside(),
            'last_movement_at' => $student->last_movement_at?->toIso8601String(),
            'last_gate' => $student->lastGate ? [
                'id' => $student->lastGate->id,
                'name' => $student->lastGate->name,
            ] : null,
            'server_time' => now('Asia/Kolkata')->toIso8601String(),
        ], 'Student gate status retrieved.');
    }

    /**
     * Update allowed student profile fields.
     * Roll Number and Student ID CANNOT be modified by the student without Admin verification.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user->student;

        if (!$student) {
            return $this->notFound('Student record not found.');
        }

        // Strict security rule: Student cannot modify their verified Roll Number or Student ID
        if ($request->has('roll_number') && $request->roll_number !== $student->roll_number) {
            return $this->error('Student ID and Roll Number cannot be modified once registered without administrative verification.', [
                'roll_number' => ['Roll Number cannot be modified directly.'],
            ], 422);
        }

        if ($request->has('student_id') && $request->student_id !== $student->student_id) {
            return $this->error('Student ID and Roll Number cannot be modified once registered without administrative verification.', [
                'student_id' => ['Student ID cannot be modified directly.'],
            ], 422);
        }

        $validated = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:25', 'unique:students,phone_number,' . $student->id],
            'profile_photo' => ['nullable', 'string', 'max:255'],
            'batch' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'integer', 'between:1,12'],
        ], [
            'phone_number.unique' => 'This phone number is already registered to another student account.',
        ]);

        $student->update(array_filter($validated, fn ($val) => $val !== null));

        return $this->success($student->fresh(), 'Profile updated successfully.');
    }
}
