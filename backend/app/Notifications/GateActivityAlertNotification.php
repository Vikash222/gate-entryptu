<?php

namespace App\Notifications;

use App\Models\Gate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GateActivityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $alertType,
        public string $message,
        public ?Gate $gate = null,
        public array $metadata = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $gateName = $this->gate?->name ?? 'Gate';

        return [
            'type' => 'GATE_ACTIVITY_ALERT',
            'title' => 'Gate Activity Alert',
            'message' => $this->message,
            'alert_type' => $this->alertType,
            'gate_name' => $gateName,
            'gate_id' => $this->gate?->id,
            'metadata' => $this->metadata,
            'server_timestamp' => now('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
