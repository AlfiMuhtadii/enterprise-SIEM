<?php

return [
    // SEC-HTTP-HEADERS: master switch. Default on -- headers are additive
    // and report-only by default for CSP, so this is safe to leave enabled.
    'enabled' => (bool) env('XDR_SECURITY_HEADERS_ENABLED', true),

    // CSP starts in report-only mode (the browser reports violations to
    // the console/an optional report-uri but never blocks anything) --
    // this codebase's 459 Blade views, Alpine.js usage, and Vite dev-mode
    // HMR haven't been audited directive-by-directive, so enforcing from
    // day one risks silently breaking the UI. Flip to true only after a
    // report-only observation period shows zero unexpected violations.
    'csp_enforce' => (bool) env('XDR_SECURITY_HEADERS_CSP_ENFORCE', false),

    // HSTS must never be sent over plain HTTP (it would make the browser
    // remember "HTTPS-only for this host", breaking local/plain-HTTP
    // access) -- gated to APP_ENV=production AND an HTTPS request.
    'hsts_enabled' => (bool) env('XDR_SECURITY_HEADERS_HSTS_ENABLED', true),
    'hsts_max_age' => (int) env('XDR_SECURITY_HEADERS_HSTS_MAX_AGE', 31536000),
];
