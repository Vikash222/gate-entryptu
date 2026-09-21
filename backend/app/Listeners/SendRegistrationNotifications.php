<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use App\Events\StudentStatusChanged;
use App\Models\User;
use App\Notifications\StudentRegistrationNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendRegistrationNotifications
{
    public function handleStudentRegistered(StudentRegistered $event): void
    {
        try {
            $notification = new StudentRegistrationNotification(
                event: 'STUDENT_REGISTERED',
                student: $event->student
            );

            // Notify all active administrators
            $admins = User::where('role', User::ROLE_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('Failed to dispatch student registered notifications: ' . $e->getMessage(), [
                'student_id' => $event->student->id ?? null,
                'exception' => $e,
            ]);
        }
    }

    public function handleStatusChanged(StudentStatusChanged $event): void
    {
        try {
            $notification = new StudentRegistrationNotification(
                event: 'STUDENT_' . strtoupper($event->statusAction),
                student: $event->student,
                admin: $event->admin
            );

            // 1. Notify the student user themselves
            $studentUser = $event->student->user;
            if ($studentUser) {
                $studentUser->notify($notification);
            }

            // 2. Notify all active administrators
            $admins = User::where('role', User::ROLE_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->get();

            foreach ($admins as $admin) {
                // Don't duplicate if admin was the actor
                $admin->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('Failed to dispatch student status changed notifications: ' . $e->getMessage(), [
                'student_id' => $event->student->id ?? null,
                'exception' => $e,
            ]);
        }
    }
}
