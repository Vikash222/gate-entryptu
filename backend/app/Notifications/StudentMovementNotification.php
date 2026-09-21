<?php

namespace App\Notifications;

use App\Models\Movement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StudentMovementNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Movement $movement
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->movement;
        $m->loadMissing(['student', 'gate']);

        $studentName = $m->student?->name ?? 'Student';
        $rollNumber = $m->student?->roll_number ?? 'N/A';
        $gateName = $m->gate?->name ?? 'Gate';
        $actionText = $m->type === Movement::TYPE_IN ? 'entered through' : 'went OUT through';
        $isManual = ($m->movement_source === Movement::SOURCE_SECURITY_MANUAL);
        $title = $isManual ? "Student {$m->type} (Manual Entry)" : "Student {$m->type}";
        $message = "{$studentName} ({$rollNumber}) {$actionText} {$gateName}" . ($isManual ? ' [Manual Entry].' : '.');

        if ($m->type === Movement::TYPE_OUT && !empty($m->destination)) {
            $message .= " Destination: {$m->destination}.";
        }

        return [
            'type' => 'STUDENT_MOVEMENT',
            'title' => $title,
            'message' => $message,
            'movement_id' => $m->id,
            'movement_uuid' => $m->movement_uuid,
            'movement_source' => $m->movement_source ?? Movement::SOURCE_QR,
            'student_name' => $studentName,
            'roll_number' => $rollNumber,
            'movement_type' => $m->type,
            'gate_name' => $gateName,
            'gate_id' => $m->gate_id,
            'destination' => $m->destination,
            'purpose' => $m->purpose,
            'vehicle_present' => (bool) $m->vehicle_present,
            'vehicle_number' => $m->vehicle_number,
            'verification_code' => $m->verification_code,
            'server_timestamp' => $m->server_timestamp?->toIso8601String() ?? now('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
