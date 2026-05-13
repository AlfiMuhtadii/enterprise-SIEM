<?php

namespace App\Http\Middleware;

use App\Services\SecurityLogger;
use App\Support\SecurityResponsePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceRevokedSessions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && SecurityResponsePolicy::isUserRevoked($user->id)) {
            SecurityLogger::log('session_revoked_enforced', [
                'request_id' => SecurityLogger::requestId(),
                'ip' => $request->ip(),
                'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
                'user_id' => $user->id,
                'email_hash' => SecurityLogger::hashValue($user->email),
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'status' => 302,
                'latency_ms' => SecurityLogger::latencyMs(),
                'query_hash' => null,
            ]);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Session revoked due to security policy. Please login again.',
            ]);
        }

        return $next($request);
    }
}
