<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\SamlSsoService;
use App\Services\SecurityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * IDENTITY-SSO-MFA: SP-initiated SAML 2.0 SSO login. Off by default
 * (config('saml.enabled')) -- all three actions 404 until an operator opts
 * in, so existing password(+optional TOTP) login is completely unaffected.
 *
 * Mirrors OidcSsoController's account-matching posture exactly: never
 * auto-provisions a new user account. The assertion's NameID (configured as
 * NameIDFormat emailAddress -- see SamlSsoService) is matched against an
 * existing user's email; an unrecognized identity is turned away with a
 * message to contact an administrator, not silently given an account. SAML
 * has no equivalent to OIDC's separate email_verified claim -- the
 * cryptographic signature check against the pinned IdP certificate (see
 * SamlSsoService::processResponse()) is itself the trust anchor here, the
 * same role iss/aud/signature validation plays for the OIDC path.
 *
 * A per-user TOTP second factor (if enabled) is still enforced after SSO
 * login, exactly like password and OIDC login -- SSO does not implicitly
 * grant a pass on the local second factor.
 */
class SamlSsoController extends Controller
{
    public function __construct(private readonly SamlSsoService $saml)
    {
    }

    public function login(Request $request): RedirectResponse
    {
        if (! $this->saml->enabled()) {
            abort(404);
        }

        $redirect = $this->saml->buildLoginRedirect($request, RouteServiceProvider::HOME);
        $request->session()->put('saml_sso_request_id', $redirect['request_id']);

        return redirect()->away($redirect['url']);
    }

    public function acs(Request $request): RedirectResponse
    {
        if (! $this->saml->enabled()) {
            abort(404);
        }

        $expectedRequestId = $request->session()->pull('saml_sso_request_id');

        try {
            $result = $this->saml->processResponse(
                $request,
                is_string($expectedRequestId) ? $expectedRequestId : null
            );
        } catch (Throwable $e) {
            $this->logAuthEvent('sso_login_failed', $request, null, null);

            return redirect()->route('login')->withErrors(['sso' => 'SSO login failed.']);
        }

        $email = $result['name_id'];
        if ($email === '' || ! str_contains($email, '@')) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO identity provider did not return a usable email identity.']);
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

    public function metadata(): Response
    {
        if (! $this->saml->enabled()) {
            abort(404);
        }

        return response($this->saml->spMetadataXml(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
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
