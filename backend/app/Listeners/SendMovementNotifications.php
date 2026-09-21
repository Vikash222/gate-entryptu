<?php

namespace App\Listeners;

use App\Events\StudentMovementRecorded;
use App\Models\SecurityDutySession;
use App\Models\User;
use App\Notifications\StudentMovementNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMovementNotifications
{
    public function handle(StudentMovementRecorded $event): void
    {
        try {
            $movement = $event->movement;
            $movement->loadMissing(['student.user', 'gate']);

            $notification = new StudentMovementNotification($movement);

            // 1. Send confirmation to student themselves
            $studentUser = $movement->student?->user;
            if ($studentUser && $studentUser->isActive()) {
                $studentUser->notify($notification);
            }

            // 2. Send to active-duty guards assigned to this gate
            $dutySessions = SecurityDutySession::where('gate_id', $movement->gate_id)
                ->where('status', SecurityDutySession::STATUS_ACTIVE)
                ->whereNull('ended_at')
                ->with('user')
                ->get();

            $guardUsers = collect();
            foreach ($dutySessions as $session) {
                if ($session->user && $session->user->isActive()) {
                    $guardUsers->push($session->user);
                }
            }
            $guardUsers = $guardUsers->unique('id');

            foreach ($guardUsers as $guard) {
                if (!$studentUser || $guard->id !== $studentUser->id) {
                    $guard->notify($notification);
                }
            }

            // 3. Send to active admins
            $admins = User::where('role', User::ROLE_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->get()
                ->unique('id');

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('Failed to dispatch movement notifications: ' . $e->getMessage(), [
                'movement_id' => $event->movement->id ?? null,
                'exception' => $e,
            ]);
        }
    }
}
