<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\SecurityLogger;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * IDENTITY-SSO-MFA (scoped to TOTP): per-user opt-in second factor. Enabling
 * MFA never affects other users' password-only login (the existing demo
 * walkthrough is unaffected unless a user explicitly turns this on for their
 * own account). SSO/SAML/OIDC federation is a separate, larger effort
 * deferred pending a real external IdP to test and configure against.
 */
class MfaController extends Controller
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->mfa_enabled) {
            return redirect()->route('profile.edit')->with('status', 'mfa-already-enabled');
        }

        $secret = $request->session()->get('mfa_setup_secret') ?? $this->totp->generateSecret();
        $request->session()->put('mfa_setup_secret', $secret);

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'uri' => $this->totp->provisioningUri($user->email, config('app.name', 'Detector XDR'), $secret),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $secret = $request->session()->get('mfa_setup_secret');
        if (!$secret || !$this->totp->verify($secret, (string) $request->string('code'))) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        $user = $request->user();
        $user->mfa_secret = $secret;
        $user->mfa_enabled = true;
        $user->mfa_confirmed_at = now();
        $user->save();
        $request->session()->forget('mfa_setup_secret');

        SecurityLogger::log('mfa_enabled', [
            'request_id' => SecurityLogger::requestId(),
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => $user->id,
            'email_hash' => SecurityLogger::hashValue($user->email),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => 302,
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => null,
        ]);

        return redirect()->route('profile.edit')->with('status', 'mfa-enabled');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        $user->mfa_secret = null;
        $user->mfa_enabled = false;
        $user->mfa_confirmed_at = null;
        $user->save();

        SecurityLogger::log('mfa_disabled', [
            'request_id' => SecurityLogger::requestId(),
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => $user->id,
            'email_hash' => SecurityLogger::hashValue($user->email),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => 302,
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => null,
        ]);

        return redirect()->route('profile.edit')->with('status', 'mfa-disabled');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (!$request->session()->get('mfa_pending_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.mfa-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('mfa_pending_user_id');
        if (!$userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);
        if (!$user || !$user->mfa_enabled || !$this->totp->verify((string) $user->mfa_secret, (string) $request->string('code'))) {
            SecurityLogger::log('mfa_challenge_failed', [
                'request_id' => SecurityLogger::requestId(),
                'ip' => $request->ip(),
                'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
                'user_id' => $userId,
                'email_hash' => null,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => 302,
                'latency_ms' => SecurityLogger::latencyMs(),
                'query_hash' => null,
            ]);

            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        $request->session()->forget('mfa_pending_user_id');
        Auth::login($user);
        $request->session()->regenerate();

        SecurityLogger::log('auth_login_success', [
            'request_id' => SecurityLogger::requestId(),
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => $user->id,
            'email_hash' => SecurityLogger::hashValue($user->email),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => 302,
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => null,
        ]);

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
