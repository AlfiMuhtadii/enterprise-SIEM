<?php

namespace App\Http\Middleware;

use App\Models\SecurityHardeningEvent;
use App\Services\InternalAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalServiceAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Internal-Service-Token', '');

        if (!InternalAuthService::verifyToken($token)) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_AUTH_FAILURE,
                'internal-api',
                [
                    'ip'     => $request->ip(),
                    'path'   => $request->path(),
                    'reason' => $token !== '' ? 'invalid_token' : 'missing_token',
                ],
                $request->ip()
            );

            return response()->json(['error' => 'unauthorized', 'code' => 'invalid_internal_token'], 401);
        }

        return $next($request);
    }
}
