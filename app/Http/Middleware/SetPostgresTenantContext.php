<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PostgresTenantSessionContext;
use App\Services\TenantContextAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPostgresTenantContext
{
    public function __construct(
        private readonly TenantContextAuthority $authority,
        private readonly PostgresTenantSessionContext $sessionContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sessionContext->enabled() || ! $this->sessionContext->supportsCurrentConnection()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $tenantId = $this->authority->validateAndResolve($request, $user);
        if ($tenantId === null) {
            return $next($request);
        }

        return $this->sessionContext->run($tenantId, fn () => $next($request));
    }
}
