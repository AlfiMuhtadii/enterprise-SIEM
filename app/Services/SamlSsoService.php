<?php

namespace App\Services;

use Illuminate\Http\Request;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\ValidationError as SamlValidationError;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * IDENTITY-SSO-MFA: SP-initiated SAML 2.0 SSO federation, off by default
 * (config('saml.enabled')). Signature verification and XSD schema
 * validation are delegated entirely to onelogin/php-saml -- see
 * config/saml.php for why this is the one place in the auth stack that
 * doesn't follow this codebase's usual dependency-free convention.
 *
 * onelogin/php-saml is a framework-agnostic library: it reads $_SERVER and
 * $_POST directly rather than accepting a request object, and
 * Response::isValid() cross-checks the signed <Destination> attribute
 * against the *actual* current request URL computed from $_SERVER. Both
 * entry points here mirror the already-authoritative Illuminate Request
 * into those superglobals first -- a genuine no-op under a real webserver
 * (where $_SERVER/$_POST are already populated identically), and the only
 * way this validation is exercisable at all under Laravel's test client
 * (which never touches PHP superglobals).
 */
class SamlSsoService
{
    public function enabled(): bool
    {
        return (bool) config('saml.enabled');
    }

    /**
     * @return array{url: string, request_id: string}
     */
    public function buildLoginRedirect(Request $request, string $returnTo): array
    {
        $this->syncServerFromRequest($request);

        $auth = $this->auth();
        $url = $auth->login($returnTo, [], false, false, true);

        return [
            'url' => $url,
            'request_id' => (string) $auth->getLastRequestID(),
        ];
    }

    /**
     * Validate an IdP's POSTed SAMLResponse: real XML signature
     * verification against the pinned IdP certificate, real XSD schema
     * validation, and Destination/Audience/Conditions/InResponseTo checks.
     *
     * @return array{name_id: string, attributes: array<string, mixed>}
     */
    public function processResponse(Request $request, ?string $expectedRequestId): array
    {
        $this->syncServerFromRequest($request);
        $_POST['SAMLResponse'] = (string) $request->input('SAMLResponse', '');

        $auth = $this->auth();

        try {
            $auth->processResponse($expectedRequestId);
        } catch (SamlError|SamlValidationError $e) {
            throw new RuntimeException('SAML response could not be processed.', 0, $e);
        }

        $errors = $auth->getErrors();
        if (! empty($errors) || ! $auth->isAuthenticated()) {
            throw new RuntimeException('SAML response validation failed: '.implode(', ', $errors));
        }

        return [
            'name_id' => (string) $auth->getNameId(),
            'attributes' => $auth->getAttributes(),
        ];
    }

    public function spMetadataXml(): string
    {
        return (new Settings($this->settings(), true))->getSPMetadata();
    }

    private function auth(): Auth
    {
        return new Auth($this->settings());
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => (string) config('saml.sp.entity_id'),
                'assertionConsumerService' => [
                    'url' => $this->acsUrl(),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId' => (string) config('saml.idp.entity_id'),
                'singleSignOnService' => [
                    'url' => (string) config('saml.idp.sso_url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => (string) config('saml.idp.x509cert'),
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'wantMessagesSigned' => false,
                // The one security-relevant knob this codebase actually
                // depends on: the IdP's assertion MUST be signed, verified
                // against config('saml.idp.x509cert') -- never left
                // implicit, never left to the library's own default.
                'wantAssertionsSigned' => true,
                'wantNameId' => true,
                'wantXMLValidation' => true,
                'relaxDestinationValidation' => false,
                // Every AuthnRequest this SP sends is tracked by its
                // request ID (session-bound, see SamlSsoController) and
                // that ID is always passed back into processResponse() --
                // so an unsolicited response (no matching AuthnRequest,
                // e.g. a replayed or IdP-initiated one) is rejected rather
                // than silently accepted.
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ];
    }

    private function acsUrl(): string
    {
        $configured = (string) config('saml.sp.acs_url', '');

        return $configured !== '' ? $configured : route('sso.saml.acs');
    }

    private function syncServerFromRequest(Request $request): void
    {
        foreach ($request->server->all() as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }
}
