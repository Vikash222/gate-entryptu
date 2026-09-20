<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->isSuspended()) {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
                return $this->forbidden('Your account has been suspended by administration. Please contact administration.');
            }

            if ($user->isRejected()) {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
                return $this->forbidden('Your registration has been rejected by administration.');
            }

            // Allow PENDING students to view their profile/status, but block any other inactive state
            if (!$user->isActive() && !$user->isPending()) {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
                return $this->forbidden('Your account is currently inactive. Please contact administration.');
            }
        }

        return $next($request);
    }
}
