<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\GeneratesMockOidcTokens;

/**
 * IDENTITY-SSO-MFA: full OIDC authorization-code SSO login flow against a
 * real local mock IdP (real RSA keypair, real RS256-signed ID tokens, real
 * signature verification) -- no live external IdP (Okta/Azure AD) exists in
 * this environment to test against.
 */
class OidcSsoControllerTest extends TestCase
{
    use RefreshDatabase;
    use GeneratesMockOidcTokens;

    private function configureMockIdp(bool $enabled = true): void
    {
        Config::set('oidc.enabled', $enabled);
        Config::set('oidc.client_id', 'test-client-id');
        Config::set('oidc.client_secret', 'test-client-secret');
        Config::set('oidc.issuer', 'https://mock-idp.test');
        Config::set('oidc.authorization_endpoint', 'https://mock-idp.test/authorize');
        Config::set('oidc.token_endpoint', 'https://mock-idp.test/token');
        Config::set('oidc.jwks_uri', 'https://mock-idp.test/jwks');
        Config::set('oidc.redirect_uri', 'https://app.test/sso/oidc/callback');
        Cache::forget('oidc_jwks');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMockIdp();
    }

    /**
     * The signed ID token's `nonce` claim must match whatever's placed in
     * the test's session under `oidc_sso_nonce` -- every test in this file
     * uses the literal 'test-nonce-value' for both, matching the trait's
     * own default nonce claim.
     */
    private function fakeCallbackFlow(string $email = 'analyst@example.com', bool $emailVerified = true): void
    {
        Http::fake([
            'https://mock-idp.test/token' => Http::response([
                'id_token' => $this->signMockOidcIdToken([
                    'email' => $email,
                    'email_verified' => $emailVerified,
                ]),
                'access_token' => 'fake-access-token',
            ], 200),
            'https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200),
        ]);
    }

    public function test_redirect_is_404_when_sso_disabled(): void
    {
        $this->configureMockIdp(enabled: false);

        $this->get('/sso/oidc/redirect')->assertNotFound();
    }

    public function test_callback_is_404_when_sso_disabled(): void
    {
        $this->configureMockIdp(enabled: false);

        $this->get('/sso/oidc/callback')->assertNotFound();
    }

    public function test_redirect_sends_user_to_idp_authorize_endpoint_with_state_and_nonce(): void
    {
        $response = $this->get('/sso/oidc/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://mock-idp.test/authorize?', $location);
        $this->assertNotEmpty(session('oidc_sso_state'));
        $this->assertNotEmpty(session('oidc_sso_nonce'));
    }

    public function test_callback_denies_when_idp_returns_error(): void
    {
        $response = $this->get('/sso/oidc/callback?error=access_denied');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_denies_when_state_missing_from_session(): void
    {
        $response = $this->get('/sso/oidc/callback?state=whatever&code=abc');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_denies_when_state_does_not_match_session(): void
    {
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=wrong-state&code=abc');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_denies_when_code_missing(): void
    {
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_logs_in_existing_user_with_matching_verified_email(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.com', 'mfa_enabled' => false]);
        $this->fakeCallbackFlow(email: 'analyst@example.com', emailVerified: true);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_callback_denies_unknown_email_without_creating_an_account(): void
    {
        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
        $this->fakeCallbackFlow(email: 'nobody@example.com', emailVerified: true);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
        // The core safety property: SSO login must never silently provision a new account.
        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
    }

    public function test_callback_denies_unverified_email(): void
    {
        User::factory()->create(['email' => 'analyst@example.com']);
        $this->fakeCallbackFlow(email: 'analyst@example.com', emailVerified: false);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_denies_when_id_token_signature_invalid(): void
    {
        User::factory()->create(['email' => 'analyst@example.com']);
        Http::fake([
            'https://mock-idp.test/token' => Http::response([
                'id_token' => $this->signRogueOidcIdToken(['email' => 'analyst@example.com']),
                'access_token' => 'fake-access-token',
            ], 200),
            'https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200),
        ]);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_callback_requires_totp_challenge_when_user_has_mfa_enabled(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'email' => 'analyst@example.com',
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);
        $this->fakeCallbackFlow(email: 'analyst@example.com', emailVerified: true);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $response = $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertGuest();
        $response->assertRedirect(route('mfa.challenge'));
        $this->assertSame($user->id, session('mfa_pending_user_id'));
    }

    public function test_callback_consumes_state_and_nonce_from_session_single_use(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.com', 'mfa_enabled' => false]);
        $this->fakeCallbackFlow(email: 'analyst@example.com', emailVerified: true);
        $this->withSession(['oidc_sso_state' => 'expected-state', 'oidc_sso_nonce' => 'test-nonce-value']);

        $this->get('/sso/oidc/callback?state=expected-state&code=auth-code-123');

        $this->assertNull(session('oidc_sso_state'));
        $this->assertNull(session('oidc_sso_nonce'));
    }
}
