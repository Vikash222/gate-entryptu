<?php

namespace App\Notifications;

use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SecurityDutyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        public SecurityDutySession $session,
        public User $guard,
        public ?Gate $gate = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $gateName = $this->gate?->name ?? $this->session->gate?->name ?? 'Gate';
        $guardName = $this->guard->name;

        $title = match ($this->event) {
            'DUTY_ACTIVATED' => 'Guard Duty Activated',
            'DUTY_ENDED' => 'Guard Duty Ended',
            'DUTY_FORCE_ENDED' => 'Guard Duty Revoked by Admin',
            default => 'Security Duty Update',
        };

        $message = match ($this->event) {
            'DUTY_ACTIVATED' => "{$guardName} started duty at {$gateName}.",
            'DUTY_ENDED' => "{$guardName} ended duty at {$gateName}.",
            'DUTY_FORCE_ENDED' => "{$guardName}'s duty session at {$gateName} was terminated by Admin.",
            default => "{$guardName} duty status updated for {$gateName}.",
        };

        return [
            'type' => 'SECURITY_DUTY',
            'title' => $title,
            'message' => $message,
            'event' => $this->event,
            'guard_name' => $guardName,
            'guard_id' => $this->guard->id,
            'gate_name' => $gateName,
            'gate_id' => $this->session->gate_id,
            'session_id' => $this->session->id,
            'server_timestamp' => now('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
