<?php

namespace Tests\Unit;

use App\Services\SamlSsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\GeneratesMockSamlResponses;

/**
 * IDENTITY-SSO-MFA: SamlSsoService against a real local mock SAML IdP (real
 * XML-DSig signature verification via onelogin/php-saml + a genuine
 * ephemeral keypair, real XSD schema validation) -- no live external IdP is
 * available in this environment.
 */
class SamlSsoServiceTest extends TestCase
{
    use GeneratesMockSamlResponses;

    private function configureMockIdp(): void
    {
        Config::set('saml.enabled', true);
        Config::set('saml.sp.entity_id', 'https://app.test/saml/metadata');
        Config::set('saml.sp.acs_url', 'https://app.test/sso/saml/acs');
        Config::set('saml.idp.entity_id', 'https://mock-idp.test/metadata');
        Config::set('saml.idp.sso_url', 'https://mock-idp.test/sso');
        Config::set('saml.idp.x509cert', $this->mockSamlIdpCertBody());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMockIdp();
    }

    private function acsRequest(string $samlResponseXml): Request
    {
        return Request::create('https://app.test/sso/saml/acs', 'POST', [
            'SAMLResponse' => $this->base64EncodeSamlResponse($samlResponseXml),
        ]);
    }

    public function test_enabled_reflects_config(): void
    {
        Config::set('saml.enabled', false);
        $this->assertFalse(app(SamlSsoService::class)->enabled());

        Config::set('saml.enabled', true);
        $this->assertTrue(app(SamlSsoService::class)->enabled());
    }

    public function test_build_login_redirect_targets_idp_sso_url_with_authn_request(): void
    {
        $request = Request::create('https://app.test/sso/saml/login', 'GET');

        $redirect = app(SamlSsoService::class)->buildLoginRedirect($request, '/dashboard');

        $this->assertStringStartsWith('https://mock-idp.test/sso?', $redirect['url']);
        $this->assertStringContainsString('SAMLRequest=', $redirect['url']);
        $this->assertNotSame('', $redirect['request_id']);

        // Decode the deflated HTTP-Redirect-bound AuthnRequest and confirm
        // it actually targets the configured IdP/SP -- not just that some
        // opaque blob was produced.
        parse_str(parse_url($redirect['url'], PHP_URL_QUERY), $query);
        $inflated = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('https://mock-idp.test/sso', $inflated);
        $this->assertStringContainsString('https://app.test/saml/metadata', $inflated);
        $this->assertStringContainsString($redirect['request_id'], $inflated);
    }

    public function test_process_response_returns_name_id_for_valid_signed_response(): void
    {
        $xml = $this->buildMockSamlResponseXml();

        $result = app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);

        $this->assertSame('analyst@example.com', $result['name_id']);
    }

    public function test_process_response_accepts_matching_in_response_to(): void
    {
        $xml = $this->buildMockSamlResponseXml(['in_response_to' => '_expected-request-1']);

        $result = app(SamlSsoService::class)->processResponse($this->acsRequest($xml), '_expected-request-1');

        $this->assertSame('analyst@example.com', $result['name_id']);
    }

    public function test_process_response_rejects_mismatched_in_response_to(): void
    {
        $xml = $this->buildMockSamlResponseXml(['in_response_to' => '_some-other-request']);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), '_expected-request-1');
    }

    public function test_process_response_rejects_unsolicited_response_carrying_in_response_to(): void
    {
        // No prior AuthnRequest in session (null expected id), but the
        // response claims to be answering one anyway -- reject rather than
        // silently accept a replayed/foreign response.
        $xml = $this->buildMockSamlResponseXml(['in_response_to' => '_foreign-request']);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);
    }

    public function test_process_response_rejects_tampered_assertion_content(): void
    {
        $xml = $this->buildMockSamlResponseXml();
        $tampered = str_replace('analyst@example.com', 'attacker@example.com', $xml);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($tampered), null);
    }

    public function test_process_response_rejects_signature_from_unregistered_keypair(): void
    {
        $rogueKeypair = $this->generateSamlKeypairAndCert();
        $xml = $this->buildMockSamlResponseXml(['keypair' => $rogueKeypair]);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);
    }

    public function test_process_response_rejects_wrong_audience(): void
    {
        $xml = $this->buildMockSamlResponseXml(['sp_entity_id' => 'https://someone-elses-sp.test/metadata']);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);
    }

    public function test_process_response_rejects_expired_assertion(): void
    {
        $xml = $this->buildMockSamlResponseXml(['ttl_seconds' => -3600]);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);
    }

    public function test_process_response_rejects_wrong_destination(): void
    {
        $xml = $this->buildMockSamlResponseXml(['acs_url' => 'https://attacker.test/sso/saml/acs']);

        $this->expectException(RuntimeException::class);
        app(SamlSsoService::class)->processResponse($this->acsRequest($xml), null);
    }

    public function test_sp_metadata_xml_contains_configured_entity_id(): void
    {
        $xml = app(SamlSsoService::class)->spMetadataXml();

        $this->assertStringContainsString('https://app.test/saml/metadata', $xml);
        $this->assertStringContainsString('https://app.test/sso/saml/acs', $xml);
    }
}
