<?php

namespace Tests\Traits;

use Firebase\JWT\JWT;

/**
 * IDENTITY-SSO-MFA: generates a real, ephemeral RS256 keypair and signs real
 * ID tokens with it -- there is no live external IdP (Okta/Azure AD) in this
 * environment, so OidcSsoService's JWKS-based signature verification is
 * tested against a genuine local mock IdP instead of asserting against a
 * stub. The keypair is generated fresh per test process (openssl_pkey_new),
 * never committed anywhere.
 */
trait GeneratesMockOidcTokens
{
    private static ?array $mockOidcKeypair = null;

    private function mockOidcKeypair(): array
    {
        if (self::$mockOidcKeypair === null) {
            $generated = $this->generateRsaKeypair();
            self::$mockOidcKeypair = [
                'private_pem' => $generated['private_pem'],
                'n' => $this->base64UrlEncode($generated['n']),
                'e' => $this->base64UrlEncode($generated['e']),
                'kid' => 'mock-oidc-test-key-1',
            ];
        }

        return self::$mockOidcKeypair;
    }

    /**
     * @return array{private_pem: string, n: string, e: string}
     */
    private function generateRsaKeypair(): array
    {
        $opts = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = @openssl_pkey_new($opts);
        if ($res === false) {
            // PHP-on-Windows often can't locate openssl.cnf via its default
            // search path even though openssl itself is installed (e.g. Git
            // for Windows ships one). Retry once with an explicit config
            // path instead of failing outright.
            $cnf = $this->findOpensslConfig();
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
        $details = openssl_pkey_get_details($res);

        return [
            'private_pem' => $privateKeyPem,
            'n' => $details['rsa']['n'],
            'e' => $details['rsa']['e'],
        ];
    }

    private function findOpensslConfig(): ?string
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

    private function mockOidcJwks(): array
    {
        $key = $this->mockOidcKeypair();

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $key['kid'],
                'n' => $key['n'],
                'e' => $key['e'],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $claimOverrides
     */
    private function signMockOidcIdToken(array $claimOverrides = []): string
    {
        $key = $this->mockOidcKeypair();

        $claims = array_merge([
            'iss' => 'https://mock-idp.test',
            'aud' => 'test-client-id',
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => 'mock-subject-1',
            'email' => 'analyst@example.com',
            'email_verified' => true,
            'nonce' => 'test-nonce-value',
        ], $claimOverrides);

        return JWT::encode($claims, $key['private_pem'], 'RS256', $key['kid']);
    }

    /**
     * Signs a token with a *different*, unregistered keypair -- for
     * asserting that signature verification actually rejects tokens whose
     * signature doesn't match the IdP's published JWKS, not just that a
     * well-formed claim set is accepted.
     *
     * @param  array<string, mixed>  $claimOverrides
     */
    private function signRogueOidcIdToken(array $claimOverrides = []): string
    {
        $rogue = $this->generateRsaKeypair();
        $sharedKid = $this->mockOidcKeypair()['kid'];

        $claims = array_merge([
            'iss' => 'https://mock-idp.test',
            'aud' => 'test-client-id',
            'iat' => time(),
            'exp' => time() + 3600,
            'email' => 'attacker@example.com',
            'email_verified' => true,
            'nonce' => 'test-nonce-value',
        ], $claimOverrides);

        // Signs with the *rogue* private key but claims the *shared* kid, so
        // decode() looks up the legitimate public key and correctly fails
        // signature verification against it.
        return JWT::encode($claims, $rogue['private_pem'], 'RS256', $sharedKid);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
