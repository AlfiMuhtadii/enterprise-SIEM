<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\OidcSsoService;
use App\Services\SecurityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * IDENTITY-SSO-MFA: OIDC authorization-code SSO login. Off by default
 * (config('oidc.enabled')) -- both actions 404 until an operator opts in,
 * so existing password(+TOTP) login is completely unaffected.
 *
 * Never auto-provisions a new user account: only an existing user matched
 * by a *verified* email claim can sign in this way, matching this
 * platform's "advisory-only, no autonomous account actions" posture used
 * for every other IdP-adjacent feature (Enterprise Integrations). An
 * unrecognized identity is turned away with a message to contact an
 * administrator, not silently given an account.
 *
 * A per-user TOTP second factor (if enabled) is still enforced after SSO
 * login, exactly like password login -- SSO does not implicitly grant a
 * pass on the local second factor.
 */
class OidcSsoController extends Controller
{
    public function __construct(private readonly OidcSsoService $oidc)
    {
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oidc->enabled()) {
            abort(404);
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('oidc_sso_state', $state);
        $request->session()->put('oidc_sso_nonce', $nonce);

        return redirect()->away($this->oidc->authorizationUrl($state, $nonce));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->oidc->enabled()) {
            abort(404);
        }

        $expectedState = $request->session()->pull('oidc_sso_state');
        $expectedNonce = (string) $request->session()->pull('oidc_sso_nonce', '');

        if ($request->query('error')) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO login was cancelled or denied.']);
        }

        if (! is_string($expectedState) || $expectedState === ''
            || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['sso' => 'Invalid SSO login state.']);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()->route('login')->withErrors(['sso' => 'Missing SSO authorization code.']);
        }

        try {
            $tokens = $this->oidc->exchangeCodeForTokens($code);
            $claims = $this->oidc->validateIdToken((string) $tokens['id_token'], $expectedNonce);
        } catch (Throwable $e) {
            $this->logAuthEvent('sso_login_failed', $request, null, null);

            return redirect()->route('login')->withErrors(['sso' => 'SSO login failed.']);
        }

        $email = (string) ($claims['email'] ?? '');
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        if ($email === '' || ! $emailVerified) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO identity provider did not return a verified email address.']);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->logAuthEvent('sso_login_unknown_user', $request, null, $email);

            return redirect()->route('login')->withErrors(['sso' => 'No account is provisioned for this identity. Contact an administrator.']);
        }

        if ($user->mfa_enabled) {
            $request->session()->put('mfa_pending_user_id', $user->id);

            return redirect()->route('mfa.challenge');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->logAuthEvent('auth_login_success', $request, $user->id, $user->email);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function logAuthEvent(string $event, Request $request, ?int $userId, ?string $email): void
    {
        SecurityLogger::log($event, [
            'request_id' => SecurityLogger::requestId(),
            'ip' => $request->ip(),
            'user_agent_hash' => SecurityLogger::hashValue($request->userAgent()),
            'user_id' => $userId,
            'email_hash' => $email !== null ? SecurityLogger::hashValue($email) : null,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => 302,
            'latency_ms' => SecurityLogger::latencyMs(),
            'query_hash' => null,
        ]);
    }
}
