<?php

return [
    // IDENTITY-SSO-MFA: enterprise SAML 2.0 SSO federation (SP-initiated,
    // HTTP-POST binding), off by default. Uses onelogin/php-saml -- like
    // firebase/php-jwt for OIDC, hand-rolling XML digital-signature
    // verification is a well-known vulnerability source (XML signature
    // wrapping attacks), so this deliberately does not follow this
    // codebase's usual dependency-free convention. No real external IdP
    // (Okta/Azure AD/ADFS) is available in this environment -- built and
    // verified against a real local mock SAML IdP (genuine XML-DSig signing
    // with an ephemeral keypair, real XSD schema validation against the
    // library's own bundled schemas) instead. Existing password(+optional
    // TOTP) login is completely unaffected until an operator sets this true
    // and points the settings below at a real IdP.
    'enabled' => env('SOC_SAML_SSO_ENABLED', false),

    'sp' => [
        // This SP's own entity ID, handed to the IdP when configuring the
        // trust relationship. Not a secret.
        'entity_id' => env('SAML_SP_ENTITY_ID', ''),

        // Empty (default) -- computed from the sso.saml.acs route at
        // request time instead, matching config('oidc.redirect_uri')'s
        // exact pattern.
        'acs_url' => env('SAML_SP_ACS_URL', ''),
    ],

    // Pinned explicitly (entity ID, SSO URL, signing certificate) rather
    // than fetched live from IdP metadata at request time -- matches the
    // OIDC config's "reviewed/approved before use" posture: no implicit
    // trust in whatever a metadata endpoint happens to say today.
    'idp' => [
        'entity_id' => env('SAML_IDP_ENTITY_ID', ''),
        'sso_url' => env('SAML_IDP_SSO_URL', ''),

        // The IdP's own public signing certificate (PEM or bare base64
        // body). This -- not whatever certificate is embedded in an
        // incoming response's <ds:KeyInfo> -- is what every assertion
        // signature is actually verified against, so a forged response
        // signed with an attacker-controlled key/cert is rejected
        // regardless of what it claims about itself.
        'x509cert' => env('SAML_IDP_X509_CERT', ''),
    ],
];
