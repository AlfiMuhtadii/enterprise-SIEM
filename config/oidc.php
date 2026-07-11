<?php

return [
    // IDENTITY-SSO-MFA: enterprise OIDC SSO federation. Off by default --
    // no real external IdP (Okta/Azure AD) is available to configure/test
    // against in this environment; built and verified against a local mock
    // OIDC provider instead. Existing password(+optional TOTP) login is
    // completely unaffected until an operator sets this true and points the
    // endpoints below at a real IdP.
    'enabled' => env('SOC_OIDC_SSO_ENABLED', false),

    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),

    // Pinned explicitly rather than fetched live from
    // {issuer}/.well-known/openid-configuration at request time, so a real
    // deployment reviews/approves the exact endpoints before they're ever
    // used -- no implicit trust in whatever a discovery document says today.
    'issuer' => env('OIDC_ISSUER'),
    'authorization_endpoint' => env('OIDC_AUTHORIZATION_ENDPOINT'),
    'token_endpoint' => env('OIDC_TOKEN_ENDPOINT'),
    'jwks_uri' => env('OIDC_JWKS_URI'),

    // Empty (default) -- computed from the sso.oidc.callback route at
    // request time instead, so it always matches the app's real APP_URL.
    'redirect_uri' => env('OIDC_REDIRECT_URI', ''),
    'scopes' => env('OIDC_SCOPES', 'openid profile email'),

    // How long to cache the IdP's published signing keys -- avoids
    // refetching JWKS on every single login while still picking up a real
    // key rotation within an hour.
    'jwks_cache_seconds' => env('OIDC_JWKS_CACHE_SECONDS', 3600),
];
