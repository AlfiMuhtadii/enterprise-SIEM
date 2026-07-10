<?php

namespace App\Http\Middleware;

use App\Support\AuditLogger;
use App\Support\Rbac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IDENTITY-SSO-MFA: mandatory MFA enforcement on specific high-consequence
 * routes (response-plan/active-response approval, data-erasure approval —
 * the "soc:response.*"/"soc:admin.*" routes named in the finding). TOTP
 * itself stays per-user opt-in everywhere else (see MfaController); this
 * middleware only blocks the *action*, not login, when the acting user has
 * not enabled MFA on their account.
 *
 * Gated behind SOC_MFA_ENFORCEMENT_ENABLED (default false) so enabling this
 * is an explicit operator decision — flipping it on before every relevant
 * account has MFA configured would lock everyone out of these actions.
 */
class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('soc.mfa_enforcement_enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user && $user->mfa_enabled) {
            return $next($request);
        }

        AuditLogger::log(
            $user?->email ?: 'guest',
            'mfa.enforcement_blocked',
            'route',
            $request->path(),
            ['role' => Rbac::role($user)],
            null,
            ['path' => $request->path(), 'method' => $request->method()]
        );

        abort(403, 'This action requires two-factor authentication. Enable MFA in your profile before continuing.');
    }
}
