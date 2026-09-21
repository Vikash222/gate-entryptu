<?php

namespace App\Providers;

use App\Models\SecurityDutySession;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 8-hour session policy enforcement for Student and Security Guard
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, $isValid) {
            $user = $accessToken->tokenable;

            if ($user && ($user->isStudent() || $user->isSecurity())) {
                $isExpired = !$isValid;

                // 1. If expires_at is past
                if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                    $isExpired = true;
                }

                // 2. Authoritative absolute 8-hour check from creation timestamp
                if ($accessToken->created_at && $accessToken->created_at->addHours(8)->isPast()) {
                    $isExpired = true;
                }

                if ($isExpired) {
                    // Security guard duty session must also end/be invalidated
                    if ($user->isSecurity()) {
                        SecurityDutySession::where('user_id', $user->id)
                            ->where('status', SecurityDutySession::STATUS_ACTIVE)
                            ->update([
                                'status' => SecurityDutySession::STATUS_ENDED,
                                'ended_at' => now('Asia/Kolkata'),
                            ]);
                    }
                    return false;
                }
            }

            return $isValid;
        });
    }
}
