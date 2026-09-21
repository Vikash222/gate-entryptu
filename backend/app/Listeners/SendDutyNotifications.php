<?php

namespace App\Listeners;

use App\Events\SecurityDutyChanged;
use App\Models\User;
use App\Notifications\SecurityDutyNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDutyNotifications
{
    public function handle(SecurityDutyChanged $event): void
    {
        try {
            $notification = new SecurityDutyNotification(
                event: $event->event,
                session: $event->session,
                guard: $event->guard,
                gate: $event->gate
            );

            // Notify all active administrators
            $admins = User::where('role', User::ROLE_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('Failed to dispatch security duty notifications: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
