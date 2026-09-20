<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\SecurityDutySession;
use App\Services\DutySessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveDutySession
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Authenticated Security user
        if (!$user || !$user->isSecurity()) {
            return $this->forbidden('Only security officers on active duty can access this operational resource.');
        }

        // 2. Account is ACTIVE (not suspended, rejected, or inactive)
        if (!$user->isActive()) {
            return $this->forbidden('Your security officer account is currently inactive or suspended by administration.');
        }

        // 3. Active Duty Session exists & 4. belongs to current Security user & 5. is ACTIVE & 7. not expired
        $activeSession = $this->dutySessionService->getActiveSession($user->id);

        if (!$activeSession || $activeSession->user_id !== $user->id || !$activeSession->isActive()) {
            return $this->error('Operational access denied: No active Admin-authorized duty session found. Please enter a valid Admin OTP to activate duty.', [
                'code' => 'NO_ACTIVE_DUTY_SESSION'
            ], Response::HTTP_FORBIDDEN);
        }

        // 6. Gate is assigned and active
        if (!$activeSession->gate_id || !$activeSession->gate || !$activeSession->gate->isActive()) {
            return $this->forbidden('Operational access denied: Assigned university gate is inactive or invalid.');
        }

        $request->attributes->set('active_duty_session', $activeSession);
        $request->attributes->set('active_gate_id', $activeSession->gate_id);

        return $next($request);
    }
}
