<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * I18N-LOCALIZATION: resolves the active locale for this request and calls
 * app()->setLocale() -- since `<html lang="...">` in both layouts already
 * reads app()->getLocale() dynamically, this is the one place that needs
 * to run before views render for the whole chain (html lang, __()/@lang
 * calls, validation messages) to stay consistent.
 *
 * Resolution order (first match wins):
 *   1. `?lang=xx` query param -- explicit override; persisted to the
 *      session so it survives the next request, and to the user's own
 *      `locale` column if authenticated (this *is* "setting your
 *      preference", not just a one-off).
 *   2. The authenticated user's stored `locale` preference.
 *   3. A locale already resolved earlier in this session.
 *   4. config('app.locale') (falls through with zero behavior change for
 *      every request that doesn't opt in to any of the above).
 */
class SetUserLocale
{
    /**
     * SUPPORTED_LOCALES doubles as the "how to add a locale" answer this
     * class's docblock and docs/i18n/LOCALIZATION_GUIDE.md both point to:
     * add the code here, add lang/{code}/*.php files, done -- no other
     * code path needs to change.
     */
    public const SUPPORTED_LOCALES = ['en', 'id'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('lang');
        if (is_string($requested) && in_array($requested, self::SUPPORTED_LOCALES, true)) {
            $request->session()->put('locale', $requested);
            if ($request->user() !== null) {
                $request->user()->forceFill(['locale' => $requested])->save();
            }
            app()->setLocale($requested);

            return $next($request);
        }

        $userLocale = $request->user()?->locale;
        if (is_string($userLocale) && in_array($userLocale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($userLocale);

            return $next($request);
        }

        $sessionLocale = $request->session()->get('locale');
        if (is_string($sessionLocale) && in_array($sessionLocale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($sessionLocale);
        }

        return $next($request);
    }
}
