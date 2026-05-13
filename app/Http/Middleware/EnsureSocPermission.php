<?php

namespace App\Http\Middleware;

use App\Support\Rbac;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSocPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!Rbac::can($request->user(), $permission)) {
            AuditLogger::log(
                $request->user()?->email ?: 'guest',
                'rbac.denied',
                'permission',
                $permission,
                ['role' => Rbac::role($request->user())],
                null,
                ['path' => $request->path(), 'method' => $request->method()]
            );

            abort(403, 'You do not have permission to access this SOC function.');
        }

        return $next($request);
    }
}
