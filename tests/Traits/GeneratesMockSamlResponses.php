<?php

namespace Tests\Traits;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * IDENTITY-SSO-MFA: generates a real, ephemeral RSA keypair + self-signed
 * X.509 certificate and signs real, XSD-schema-valid SAML 2.0 Response XML
 * with it -- there is no live external IdP (Okta/Azure AD/ADFS) in this
 * environment, so SamlSsoService's signature verification is tested against
 * a genuine local mock IdP (real XML-DSig over a real Assertion element)
 * instead of asserting against a stub. The keypair is generated fresh per
 * test process (openssl_pkey_new), never committed anywhere.
 */
trait GeneratesMockSamlResponses
{
    private static ?array $mockSamlKeypair = null;

    /**
     * @return array{private_pem: string, cert_pem: string}
     */
    private function mockSamlKeypair(): array
    {
        if (self::$mockSamlKeypair === null) {
            self::$mockSamlKeypair = $this->generateSamlKeypairAndCert();
        }

        return self::$mockSamlKeypair;
    }

    /**
     * @return array{private_pem: string, cert_pem: string}
     */
    private function generateSamlKeypairAndCert(): array
    {
        $opts = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = @openssl_pkey_new($opts);
        if ($res === false) {
            // See GeneratesMockOidcTokens -- PHP-on-Windows often can't
            // locate openssl.cnf via its default search path.
            $cnf = $this->findOpensslConfigForSaml();
            if ($cnf !== null) {
                $opts['config'] = $cnf;
                $res = openssl_pkey_new($opts);
            }
        }

        if ($res === false) {
            self::markTestSkipped('openssl_pkey_new() unavailable: no usable openssl.cnf found in this environment.');
        }

        $exportOpts = isset($opts['config']) ? ['config' => $opts['config']] : [];
        openssl_pkey_export($res, $privateKeyPem, null, $exportOpts);

        $dn = ['countryName' => 'US', 'commonName' => 'mock-saml-idp.test'];
        $csr = openssl_csr_new($dn, $res, $exportOpts);
        $cert = openssl_csr_sign($csr, null, $res, 365, $exportOpts);
        openssl_x509_export($cert, $certPem);

        return ['private_pem' => $privateKeyPem, 'cert_pem' => $certPem];
    }

    private function findOpensslConfigForSaml(): ?string
    {
        foreach ([
            getenv('OPENSSL_CONF') ?: '',
            'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function mockSamlIdpCertBody(): string
    {
        $pem = $this->mockSamlKeypair()['cert_pem'];

        return trim(str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r"],
            '',
            $pem
        ));
    }

    /**
     * Builds and signs a real SAML 2.0 Response (Assertion-level enveloped
     * XML-DSig, RSA-SHA256), matching the exact wire shape SamlSsoService
     * expects: signed with the mock IdP's private key, validated by
     * onelogin/php-saml against config('saml.idp.x509cert') -- the embedded
     * <ds:KeyInfo> certificate is never the actual trust root.
     *
     * @param  array<string, mixed>  $opts  idp_entity_id, sp_entity_id, acs_url,
     *                                      email, in_response_to, keypair (override signer -- for rogue-signer tests)
     */
    private function buildMockSamlResponseXml(array $opts = []): string
    {
        $kp = $opts['keypair'] ?? $this->mockSamlKeypair();

        $now = time();
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z', $now);
        $notBefore = gmdate('Y-m-d\TH:i:s\Z', $now - 60);
        $notOnOrAfter = gmdate('Y-m-d\TH:i:s\Z', $now + ($opts['ttl_seconds'] ?? 300));

        $responseId = '_'.bin2hex(random_bytes(16));
        $assertionId = '_'.bin2hex(random_bytes(16));
        $sessionIndex = '_'.bin2hex(random_bytes(8));

        $idpEntityId = $opts['idp_entity_id'] ?? 'https://mock-idp.test/metadata';
        $spEntityId = $opts['sp_entity_id'] ?? 'https://app.test/saml/metadata';
        $acsUrl = $opts['acs_url'] ?? 'https://app.test/sso/saml/acs';
        $email = $opts['email'] ?? 'analyst@example.com';
        $inResponseTo = $opts['in_response_to'] ?? null;

        $inResponseToAttr = $inResponseTo !== null ? " InResponseTo=\"{$inResponseTo}\"" : '';

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$acsUrl}"{$inResponseToAttr}>
  <saml:Issuer>{$idpEntityId}</saml:Issuer>
  <samlp:Status>
    <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
  </samlp:Status>
  <saml:Assertion ID="{$assertionId}" Version="2.0" IssueInstant="{$issueInstant}">
    <saml:Issuer>{$idpEntityId}</saml:Issuer>
    <saml:Subject>
      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$email}</saml:NameID>
      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
        <saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$acsUrl}"{$inResponseToAttr}/>
      </saml:SubjectConfirmation>
    </saml:Subject>
    <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}">
      <saml:AudienceRestriction>
        <saml:Audience>{$spEntityId}</saml:Audience>
      </saml:AudienceRestriction>
    </saml:Conditions>
    <saml:AuthnStatement AuthnInstant="{$issueInstant}" SessionIndex="{$sessionIndex}">
      <saml:AuthnContext>
        <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
      </saml:AuthnContext>
    </saml:AuthnStatement>
  </saml:Assertion>
</samlp:Response>
XML;

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xml);

        $assertionNode = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);

        $sig = new XMLSecurityDSig;
        $sig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $sig->addReferenceList(
            [$assertionNode],
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($kp['private_pem']);
        $sig->sign($key);
        $sig->add509Cert($kp['cert_pem']);

        // Signature must be the assertion's 2nd child (right after Issuer)
        // per the SAML assertion XSD's element ordering.
        $issuerNode = $assertionNode->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
        $sig->insertSignature($assertionNode, $issuerNode->nextSibling);

        return $doc->saveXML();
    }

    private function base64EncodeSamlResponse(string $xml): string
    {
        return base64_encode($xml);
    }
}
