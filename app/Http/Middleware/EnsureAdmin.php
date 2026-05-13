<?php

namespace App\Http\Middleware;

use App\Services\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin') {
            SecurityLogger::log('authorization_denied', [
                'request_id' => SecurityLogger::requestId(),
                'ip' => $request->ip(),
                'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
                'user_id' => optional($user)->id,
                'email_hash' => null,
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'status' => 403,
                'latency_ms' => SecurityLogger::latencyMs(),
                'query_hash' => null,
            ]);

            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
