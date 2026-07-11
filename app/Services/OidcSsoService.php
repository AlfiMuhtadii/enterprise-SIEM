<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

/**
 * IDENTITY-SSO-MFA: OIDC authorization-code SSO federation, off by default
 * (config('oidc.enabled')). Dependency-free everywhere else in this
 * codebase's auth stack (TotpService's RFC 6238, TraceparentService's W3C
 * Trace Context) except ID-token signature verification -- unlike TOTP or
 * traceparent parsing, hand-rolling RS256/JWKS verification is a well-known
 * source of real vulnerabilities (algorithm confusion, missing signature
 * checks), so this uses the widely-audited firebase/php-jwt library instead
 * of a from-scratch implementation.
 */
class OidcSsoService
{
    public function enabled(): bool
    {
        return (bool) config('oidc.enabled');
    }

    public function authorizationUrl(string $state, string $nonce): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => config('oidc.scopes', 'openid profile email'),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return rtrim((string) config('oidc.authorization_endpoint'), '?').'?'.$query;
    }

    /**
     * Exchange an authorization code for tokens at the IdP's token endpoint.
     *
     * @return array<string, mixed> decoded token response (id_token, access_token, ...)
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post((string) config('oidc.token_endpoint'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => config('oidc.client_id'),
            'client_secret' => config('oidc.client_secret'),
        ]);

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! isset($body['id_token'])) {
            throw new RuntimeException('OIDC token exchange failed.');
        }

        return $body;
    }

    /**
     * Verify the ID token's signature against the IdP's published JWKS and
     * validate the OIDC-mandatory claims (iss, aud, nonce) that
     * firebase/php-jwt's decode() does not check on its own (it only checks
     * the signature plus exp/nbf/iat).
     *
     * @return array<string, mixed> decoded claims
     */
    public function validateIdToken(string $idToken, string $expectedNonce): array
    {
        $decoded = JWT::decode($idToken, $this->jwks());
        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($decoded), true);

        if (($claims['iss'] ?? null) !== config('oidc.issuer')) {
            throw new UnexpectedValueException('OIDC id_token issuer mismatch.');
        }

        $aud = $claims['aud'] ?? null;
        $audMatches = $aud === config('oidc.client_id')
            || (is_array($aud) && in_array(config('oidc.client_id'), $aud, true));
        if (! $audMatches) {
            throw new UnexpectedValueException('OIDC id_token audience mismatch.');
        }

        if ($expectedNonce === '' || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new UnexpectedValueException('OIDC id_token nonce mismatch.');
        }

        return $claims;
    }

    private function redirectUri(): string
    {
        $configured = (string) config('oidc.redirect_uri', '');

        return $configured !== '' ? $configured : route('sso.oidc.callback');
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function jwks(): array
    {
        $ttl = (int) config('oidc.jwks_cache_seconds', 3600);

        $raw = Cache::remember('oidc_jwks', $ttl, function () {
            $response = Http::get((string) config('oidc.jwks_uri'));
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('Unable to fetch OIDC JWKS.');
            }

            return $response->json();
        });

        return JWK::parseKeySet($raw);
    }
}
