<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tests\Traits\GeneratesMockSamlResponses;

/**
 * IDENTITY-SSO-MFA: full SP-initiated SAML 2.0 SSO login flow against a real
 * local mock IdP (real RSA keypair, real XML-DSig-signed assertions, real
 * signature verification) -- no live external IdP (Okta/Azure AD/ADFS)
 * exists in this environment to test against.
 *
 * The ACS endpoint is posted to as an absolute https://app.test/... URL in
 * every test below (not a relative path) so it matches the Destination
 * baked into each signed fixture -- onelogin/php-saml cross-checks the
 * signed Destination attribute against the actual request URL, and Laravel's
 * test client otherwise resolves relative URIs against APP_URL, not
 * app.test.
 */
class SamlSsoControllerTest extends TestCase
{
    use RefreshDatabase;
    use GeneratesMockSamlResponses;

    private const ACS_URL = 'https://app.test/sso/saml/acs';

    private function configureMockIdp(bool $enabled = true): void
    {
        Config::set('saml.enabled', $enabled);
        Config::set('saml.sp.entity_id', 'https://app.test/saml/metadata');
        Config::set('saml.sp.acs_url', self::ACS_URL);
        Config::set('saml.idp.entity_id', 'https://mock-idp.test/metadata');
        Config::set('saml.idp.sso_url', 'https://mock-idp.test/sso');
        Config::set('saml.idp.x509cert', $this->mockSamlIdpCertBody());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMockIdp();
    }

    private function postSamlResponse(string $xml): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::ACS_URL, [
            'SAMLResponse' => $this->base64EncodeSamlResponse($xml),
        ]);
    }

    public function test_login_is_404_when_sso_disabled(): void
    {
        $this->configureMockIdp(enabled: false);

        $this->get('/sso/saml/login')->assertNotFound();
    }

    public function test_acs_is_404_when_sso_disabled(): void
    {
        $this->configureMockIdp(enabled: false);

        $this->post(self::ACS_URL)->assertNotFound();
    }

    public function test_metadata_is_404_when_sso_disabled(): void
    {
        $this->configureMockIdp(enabled: false);

        $this->get('/sso/saml/metadata')->assertNotFound();
    }

    public function test_login_redirects_to_idp_and_stores_request_id_in_session(): void
    {
        $response = $this->get('/sso/saml/login');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://mock-idp.test/sso?', $location);
        $this->assertNotEmpty(session('saml_sso_request_id'));
    }

    public function test_metadata_returns_sp_metadata_xml(): void
    {
        $response = $this->get('/sso/saml/metadata');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/samlmetadata+xml');
        $this->assertStringContainsString('https://app.test/saml/metadata', $response->getContent());
    }

    public function test_acs_logs_in_existing_user_matched_by_name_id(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.com', 'mfa_enabled' => false]);
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
        ]);

        $response = $this->postSamlResponse($xml);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_acs_denies_unknown_email_without_creating_an_account(): void
    {
        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
            'email' => 'nobody@example.com',
        ]);

        $response = $this->postSamlResponse($xml);

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
        // The core safety property: SSO login must never silently provision a new account.
        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
    }

    public function test_acs_denies_when_signature_is_from_an_unregistered_keypair(): void
    {
        User::factory()->create(['email' => 'analyst@example.com']);
        $rogue = $this->generateSamlKeypairAndCert();
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
            'keypair' => $rogue,
        ]);

        $response = $this->postSamlResponse($xml);

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_acs_denies_when_assertion_content_is_tampered(): void
    {
        User::factory()->create(['email' => 'analyst@example.com']);
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
        ]);
        $tampered = str_replace('analyst@example.com', 'attacker@example.com', $xml);

        $response = $this->postSamlResponse($tampered);

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_acs_denies_mismatched_in_response_to(): void
    {
        $this->withSession(['saml_sso_request_id' => 'expected-request-id']);
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
            'in_response_to' => 'some-other-request-id',
        ]);

        $response = $this->postSamlResponse($xml);

        $this->assertGuest();
        $response->assertSessionHasErrors('sso');
    }

    public function test_acs_requires_totp_challenge_when_user_has_mfa_enabled(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'email' => 'analyst@example.com',
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
        ]);

        $response = $this->postSamlResponse($xml);

        $this->assertGuest();
        $response->assertRedirect(route('mfa.challenge'));
        $this->assertSame($user->id, session('mfa_pending_user_id'));
    }

    public function test_acs_consumes_request_id_from_session_single_use(): void
    {
        $user = User::factory()->create(['email' => 'analyst@example.com', 'mfa_enabled' => false]);
        $this->withSession(['saml_sso_request_id' => 'expected-request-id']);
        $xml = $this->buildMockSamlResponseXml([
            'sp_entity_id' => 'https://app.test/saml/metadata',
            'acs_url' => self::ACS_URL,
            'in_response_to' => 'expected-request-id',
        ]);

        $this->postSamlResponse($xml);

        $this->assertNull(session('saml_sso_request_id'));
    }
}
