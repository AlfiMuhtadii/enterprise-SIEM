<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // IDENTITY-SSO-MFA: the IdP POSTs the SAMLResponse directly (HTTP-POST
        // binding) -- there is no Laravel session/CSRF token to carry across
        // that external redirect. Authenticity comes from the assertion's
        // XML signature (SamlSsoService::processResponse()), not a CSRF token.
        'sso/saml/acs',
    ];
}
