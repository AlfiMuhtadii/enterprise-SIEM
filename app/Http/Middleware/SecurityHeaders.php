<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-HTTP-HEADERS: browser-facing security headers. Global middleware
 * (applies to every response, web and API alike) rather than a route-group
 * concern, since none of these headers are ever wrong to send.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! (bool) config('security_headers.enabled', true)) {
            return $response;
        }

        $csp = $this->contentSecurityPolicy($request);
        $cspHeaderName = config('security_headers.csp_enforce', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
        $response->headers->set($cspHeaderName, $csp);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        );

        if (config('security_headers.hsts_enabled', true) && $request->isSecure() && app()->environment('production')) {
            $maxAge = (int) config('security_headers.hsts_max_age', 31536000);
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        // Vite's dev server (HMR over a websocket) only runs locally --
        // production serves the same-origin built public/build assets, so
        // this extra allowance is never present outside local development.
        $connectExtra = app()->environment('local') ? ' ws://localhost:5173 http://localhost:5173' : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'".$connectExtra,
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
