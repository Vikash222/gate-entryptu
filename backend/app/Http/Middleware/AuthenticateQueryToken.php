<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQueryToken
{
    /**
     * Handle an incoming request.
     * If no Bearer token in header and token present in query, set Authorization header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && !$request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        if (!$request->bearerToken() && $request->has('token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
        }

        return $next($request);
    }
}
