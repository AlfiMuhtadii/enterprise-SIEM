<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use App\Services\SecurityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // IDENTITY-SSO-MFA: per-user opt-in second factor. If this account has
        // TOTP enabled, revert the password-only login Auth::attempt() just
        // established and require a verified code before completing it.
        $user = $request->user();
        if ($user->mfa_enabled) {
            Auth::guard('web')->logout();
            $request->session()->put('mfa_pending_user_id', $user->id);

            return redirect()->route('mfa.challenge');
        }

        $request->session()->regenerate();

        SecurityLogger::log('auth_login_success', [
            'request_id' => SecurityLogger::requestId(),
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => optional($request->user())->id,
            'email_hash' => SecurityLogger::hashValue((string) $request->string('email')),
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'status' => 302,
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => null,
        ]);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
