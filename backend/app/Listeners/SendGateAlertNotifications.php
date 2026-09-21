<?php

namespace App\Listeners;

use App\Events\GateActivityAlert;
use App\Models\SecurityDutySession;
use App\Models\User;
use App\Notifications\GateActivityAlertNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendGateAlertNotifications
{
    public function handle(GateActivityAlert $event): void
    {
        try {
            $notification = new GateActivityAlertNotification(
                alertType: $event->alertType,
                message: $event->message,
                gate: $event->gate,
                metadata: $event->metadata
            );

            // Notify active guards at gate if gate is specified
            if ($event->gate) {
                $dutySessions = SecurityDutySession::where('gate_id', $event->gate->id)
                    ->where('status', SecurityDutySession::STATUS_ACTIVE)
                    ->whereNull('ended_at')
                    ->with('user')
                    ->get();

                foreach ($dutySessions as $session) {
                    if ($session->user && $session->user->isActive()) {
                        $session->user->notify($notification);
                    }
                }
            }

            // Notify all admins
            $admins = User::where('role', User::ROLE_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('Failed to dispatch gate alert notifications: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
