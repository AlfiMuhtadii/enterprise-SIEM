<?php

namespace Tests\Unit;

use App\Services\OidcSsoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\GeneratesMockOidcTokens;
use UnexpectedValueException;

/**
 * IDENTITY-SSO-MFA: OidcSsoService against a real local mock OIDC provider
 * (real RS256 signature verification via firebase/php-jwt + a genuine
 * ephemeral keypair) -- no live external IdP is available in this
 * environment.
 */
class OidcSsoServiceTest extends TestCase
{
    use GeneratesMockOidcTokens;

    private function configureMockIdp(): void
    {
        Config::set('oidc.enabled', true);
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

    public function test_enabled_reflects_config(): void
    {
        Config::set('oidc.enabled', false);
        $this->assertFalse(app(OidcSsoService::class)->enabled());

        Config::set('oidc.enabled', true);
        $this->assertTrue(app(OidcSsoService::class)->enabled());
    }

    public function test_authorization_url_contains_expected_params(): void
    {
        $url = app(OidcSsoService::class)->authorizationUrl('state-abc', 'nonce-xyz');

        $this->assertStringStartsWith('https://mock-idp.test/authorize?', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=state-abc', $url);
        $this->assertStringContainsString('nonce=nonce-xyz', $url);
        $this->assertStringContainsString(urlencode('https://app.test/sso/oidc/callback'), $url);
    }

    public function test_exchange_code_for_tokens_posts_expected_form_and_returns_body(): void
    {
        Http::fake([
            'https://mock-idp.test/token' => Http::response([
                'id_token' => 'fake-id-token',
                'access_token' => 'fake-access-token',
            ], 200),
        ]);

        $tokens = app(OidcSsoService::class)->exchangeCodeForTokens('auth-code-123');

        $this->assertSame('fake-id-token', $tokens['id_token']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://mock-idp.test/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code-123'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret'
                && $request['redirect_uri'] === 'https://app.test/sso/oidc/callback';
        });
    }

    public function test_exchange_code_for_tokens_throws_on_non_2xx(): void
    {
        Http::fake(['https://mock-idp.test/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(RuntimeException::class);
        app(OidcSsoService::class)->exchangeCodeForTokens('bad-code');
    }

    public function test_exchange_code_for_tokens_throws_when_id_token_missing(): void
    {
        Http::fake(['https://mock-idp.test/token' => Http::response(['access_token' => 'x'], 200)]);

        $this->expectException(RuntimeException::class);
        app(OidcSsoService::class)->exchangeCodeForTokens('code');
    }

    public function test_validate_id_token_returns_claims_for_valid_signed_token(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken();

        $claims = app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');

        $this->assertSame('analyst@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function test_validate_id_token_caches_jwks_across_calls(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken();

        app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');
        app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');

        Http::assertSentCount(1);
    }

    public function test_validate_id_token_rejects_wrong_issuer(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken(['iss' => 'https://attacker.test']);

        $this->expectException(UnexpectedValueException::class);
        app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');
    }

    public function test_validate_id_token_rejects_wrong_audience(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken(['aud' => 'some-other-client']);

        $this->expectException(UnexpectedValueException::class);
        app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');
    }

    public function test_validate_id_token_rejects_nonce_mismatch(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken();

        $this->expectException(UnexpectedValueException::class);
        app(OidcSsoService::class)->validateIdToken($idToken, 'wrong-nonce');
    }

    public function test_validate_id_token_rejects_expired_token(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);
        $idToken = $this->signMockOidcIdToken(['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->expectException(\Firebase\JWT\ExpiredException::class);
        app(OidcSsoService::class)->validateIdToken($idToken, 'test-nonce-value');
    }

    public function test_validate_id_token_rejects_signature_from_different_key(): void
    {
        Http::fake(['https://mock-idp.test/jwks' => Http::response($this->mockOidcJwks(), 200)]);

        // Signed with a brand-new, unregistered keypair -- the JWKS above
        // only knows about the shared fixture key, so this must fail
        // verification even though the claims themselves look legitimate.
        $rogueToken = $this->signRogueOidcIdToken();

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        app(OidcSsoService::class)->validateIdToken($rogueToken, 'test-nonce-value');
    }
}
