<?php

namespace App\Notifications;

use App\Models\Student;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StudentRegistrationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        public Student $student,
        public ?User $admin = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $studentName = $this->student->name;
        $rollNumber = $this->student->roll_number;

        $title = match ($this->event) {
            'STUDENT_REGISTERED' => 'New Student Registration',
            'STUDENT_APPROVED' => 'Student Account Approved',
            'STUDENT_REJECTED' => 'Student Registration Rejected',
            'STUDENT_SUSPENDED' => 'Student Gate Access Suspended',
            'STUDENT_REACTIVATED' => 'Student Account Reactivated',
            default => 'Student Account Update',
        };

        $message = match ($this->event) {
            'STUDENT_REGISTERED' => "{$studentName} ({$rollNumber}) registered and is awaiting admin approval.",
            'STUDENT_APPROVED' => "{$studentName} ({$rollNumber}) has been approved. Gate access is now active.",
            'STUDENT_REJECTED' => "{$studentName} ({$rollNumber}) registration was rejected by administration.",
            'STUDENT_SUSPENDED' => "{$studentName} ({$rollNumber}) gate privileges were suspended.",
            'STUDENT_REACTIVATED' => "{$studentName} ({$rollNumber}) gate access was restored.",
            default => "Account status for {$studentName} ({$rollNumber}) changed to {$this->event}.",
        };

        return [
            'type' => 'STUDENT_REGISTRATION',
            'title' => $title,
            'message' => $message,
            'event' => $this->event,
            'student_name' => $studentName,
            'roll_number' => $rollNumber,
            'student_id' => $this->student->id,
            'student_user_id' => $this->student->user_id,
            'server_timestamp' => now('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
