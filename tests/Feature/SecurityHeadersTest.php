<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SEC-HTTP-HEADERS: the web SOC console previously sent no CSP/HSTS/
 * X-Frame-Options/X-Content-Type-Options/Referrer-Policy/Permissions-Policy
 * at all. CSP starts in report-only mode (config-flippable) since 459
 * Blade views + Alpine.js usage haven't been directive-by-directive
 * audited for an enforce-mode policy yet.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_gets_report_only_csp_by_default(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_csp_switches_to_enforcing_header_when_configured(): void
    {
        Config::set('security_headers.csp_enforce', true);

        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_csp_denies_framing_and_restricts_to_self(): void
    {
        $response = $this->get('/login');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_standard_hardening_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_hsts_is_absent_over_plain_http(): void
    {
        $response = $this->get('/login');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_absent_outside_production_even_over_https(): void
    {
        $response = $this->call('GET', 'https://localhost/login', server: ['HTTPS' => 'on']);

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_present_in_production_over_https(): void
    {
        $this->app['env'] = 'production';

        $response = $this->call('GET', 'https://localhost/login', server: ['HTTPS' => 'on']);

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_headers_absent_when_middleware_disabled(): void
    {
        Config::set('security_headers.enabled', false);

        $response = $this->get('/login');

        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
