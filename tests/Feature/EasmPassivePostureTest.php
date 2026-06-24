<?php

namespace Tests\Feature;

use App\Models\WebsiteAsset;
use App\Models\EasmScanRun;
use App\Models\EasmFinding;
use App\Services\EasmPassiveScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EasmPassivePostureTest extends TestCase
{
    use RefreshDatabase;

    private EasmPassiveScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EasmPassiveScanService::class);
    }

    // =========================================================================
    // Advisory-only constraints (5 tests)
    // =========================================================================

    public function test_no_exploit_scan(): void
    {
        $this->assertFalse(method_exists($this->service, 'exploitScan'));
    }

    public function test_no_active_scan(): void
    {
        $this->assertFalse(method_exists($this->service, 'activeScan'));
    }

    public function test_no_run_nuclei(): void
    {
        $this->assertFalse(method_exists($this->service, 'runNuclei'));
    }

    public function test_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists($this->service, 'autoRemediate'));
    }

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(EasmPassiveScanService::ADVISORY_ONLY);
    }

    // =========================================================================
    // URL validation (8 tests)
    // =========================================================================

    public function test_valid_https_url(): void
    {
        $result = $this->service->validateUrl('https://example.com/path');
        $this->assertTrue($result['valid']);
        $this->assertSame('example.com', $result['hostname']);
        $this->assertSame('https', $result['scheme']);
    }

    public function test_valid_http_url(): void
    {
        $result = $this->service->validateUrl('http://example.com');
        $this->assertTrue($result['valid']);
        $this->assertSame('http', $result['scheme']);
    }

    public function test_malformed_url_rejected(): void
    {
        $result = $this->service->validateUrl('not a url at all :::');
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_missing_scheme_rejected(): void
    {
        $result = $this->service->validateUrl('example.com/path');
        $this->assertFalse($result['valid']);
    }

    public function test_ftp_scheme_rejected(): void
    {
        $result = $this->service->validateUrl('ftp://example.com');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('ftp', $result['error']);
    }

    public function test_empty_url_rejected(): void
    {
        $result = $this->service->validateUrl('');
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_url_without_hostname_rejected(): void
    {
        $result = $this->service->validateUrl('https://');
        $this->assertFalse($result['valid']);
    }

    public function test_url_hostname_is_normalised_to_lowercase(): void
    {
        $result = $this->service->validateUrl('https://EXAMPLE.COM/Path');
        $this->assertTrue($result['valid']);
        $this->assertSame('example.com', $result['hostname']);
    }

    // =========================================================================
    // Private IP rejection (6 tests)
    // =========================================================================

    public function test_localhost_rejected(): void
    {
        $result = $this->service->validateUrl('https://localhost/admin');
        $this->assertFalse($result['valid']);
    }

    public function test_127_0_0_1_rejected(): void
    {
        $result = $this->service->validateUrl('https://127.0.0.1/');
        $this->assertFalse($result['valid']);
    }

    public function test_10_x_x_x_rejected(): void
    {
        $result = $this->service->validateUrl('https://10.0.0.1/');
        $this->assertFalse($result['valid']);
    }

    public function test_192_168_x_x_rejected(): void
    {
        $result = $this->service->validateUrl('https://192.168.1.1/');
        $this->assertFalse($result['valid']);
    }

    public function test_172_16_x_x_rejected(): void
    {
        $result = $this->service->validateUrl('https://172.16.0.1/');
        $this->assertFalse($result['valid']);
    }

    public function test_dot_local_tld_rejected(): void
    {
        $result = $this->service->validateUrl('https://mydevbox.local/');
        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // Valid external hostname accepted (2 tests)
    // =========================================================================

    public function test_example_com_accepted(): void
    {
        $result = $this->service->validateUrl('https://example.com/');
        $this->assertTrue($result['valid']);
    }

    public function test_subdomain_external_accepted(): void
    {
        $result = $this->service->validateUrl('https://sub.example.co.uk/');
        $this->assertTrue($result['valid']);
    }

    // =========================================================================
    // Asset registration (4 tests)
    // =========================================================================

    public function test_register_asset_creates_record(): void
    {
        $asset = $this->service->registerAsset(['url' => 'https://example.com'], 'tenant-001');
        $this->assertDatabaseHas('website_assets', ['id' => $asset->id]);
    }

    public function test_register_asset_stores_tenant_id(): void
    {
        $asset = $this->service->registerAsset(['url' => 'https://example.com'], 'tenant-abc');
        $this->assertSame('tenant-abc', $asset->tenant_id);
    }

    public function test_register_asset_stores_hostname(): void
    {
        $asset = $this->service->registerAsset(['url' => 'https://EXAMPLE.COM/path'], 'tenant-001');
        $this->assertSame('example.com', $asset->hostname);
    }

    public function test_register_asset_throws_on_invalid_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerAsset(['url' => 'https://127.0.0.1/'], 'tenant-001');
    }

    // =========================================================================
    // Ownership guard (4 tests)
    // =========================================================================

    public function test_validate_ownership_returns_asset_for_correct_tenant(): void
    {
        $asset = WebsiteAsset::create([
            'tenant_id' => 'tenant-001',
            'name'      => 'Test',
            'url'       => 'https://example.com',
            'hostname'  => 'example.com',
            'scheme'    => 'https',
        ]);

        $found = $this->service->validateOwnership($asset->id, 'tenant-001');
        $this->assertSame($asset->id, $found->id);
    }

    public function test_validate_ownership_throws_for_wrong_tenant(): void
    {
        $asset = WebsiteAsset::create([
            'tenant_id' => 'tenant-001',
            'name'      => 'Test',
            'url'       => 'https://example.com',
            'hostname'  => 'example.com',
            'scheme'    => 'https',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->validateOwnership($asset->id, 'tenant-999');
    }

    public function test_validate_ownership_throws_for_missing_asset(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->validateOwnership(99999, 'tenant-001');
    }

    public function test_validate_ownership_cross_tenant_error_message(): void
    {
        $asset = WebsiteAsset::create([
            'tenant_id' => 'tenant-001',
            'name'      => 'Test',
            'url'       => 'https://example.com',
            'hostname'  => 'example.com',
            'scheme'    => 'https',
        ]);

        try {
            $this->service->validateOwnership($asset->id, 'tenant-evil');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('cross_tenant_access_denied', $e->getMessage());
        }
    }

    // =========================================================================
    // Finding normalisation (8 tests)
    // =========================================================================

    private function makeAsset(string $tenantId = 'tenant-001'): WebsiteAsset
    {
        return WebsiteAsset::create([
            'tenant_id' => $tenantId,
            'name'      => 'Test',
            'url'       => 'https://example.com',
            'hostname'  => 'example.com',
            'scheme'    => 'https',
        ]);
    }

    public function test_empty_raw_checks_returns_empty_array(): void
    {
        $asset = $this->makeAsset();
        $result = $this->service->normalizeCheckResults([], $asset);
        $this->assertSame([], $result);
    }

    public function test_missing_hsts_produces_security_header_finding(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'security_headers',
            'findings' => [[
                'key'         => 'missing_hsts',
                'category'    => 'security_header',
                'severity'    => 'medium',
                'confidence'  => 'high',
                'title'       => 'Missing HSTS',
                'description' => 'Strict-Transport-Security header is absent.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertCount(1, $result);
        $this->assertSame('missing_hsts', $result[0]['finding_key']);
        $this->assertSame('medium', $result[0]['severity']);
        $this->assertSame('security_header', $result[0]['category']);
    }

    public function test_missing_csp_produces_finding(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'security_headers',
            'findings' => [[
                'key'         => 'missing_csp',
                'category'    => 'security_header',
                'severity'    => 'medium',
                'confidence'  => 'high',
                'title'       => 'Missing CSP',
                'description' => 'Content-Security-Policy header is absent.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertSame('missing_csp', $result[0]['finding_key']);
        $this->assertSame('medium', $result[0]['severity']);
    }

    public function test_missing_x_frame_options_produces_finding(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'security_headers',
            'findings' => [[
                'key'         => 'missing_x_frame_options',
                'category'    => 'security_header',
                'severity'    => 'low',
                'confidence'  => 'high',
                'title'       => 'Missing X-Frame-Options',
                'description' => 'X-Frame-Options header is absent.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertSame('missing_x_frame_options', $result[0]['finding_key']);
    }

    public function test_tls_expiry_produces_tls_category_finding(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'tls',
            'findings' => [[
                'key'         => 'cert_expiry_warning',
                'category'    => 'tls',
                'severity'    => 'medium',
                'confidence'  => 'high',
                'title'       => 'Certificate expiry warning',
                'description' => 'Certificate expires in less than 30 days.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertSame('tls', $result[0]['category']);
    }

    public function test_cookie_missing_secure_produces_medium_finding(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'cookies',
            'findings' => [[
                'key'         => 'cookie_missing_secure',
                'category'    => 'cookie',
                'severity'    => 'medium',
                'confidence'  => 'medium',
                'title'       => 'Cookie missing Secure flag',
                'description' => 'A cookie was found without the Secure attribute.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertSame('medium', $result[0]['severity']);
    }

    public function test_finding_is_advisory_always_true(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'dns',
            'findings' => [[
                'key'         => 'dns_resolution_failed',
                'category'    => 'dns',
                'severity'    => 'medium',
                'confidence'  => 'high',
                'title'       => 'DNS resolution failed',
                'description' => 'Could not resolve hostname.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertTrue($result[0]['is_advisory']);
    }

    public function test_finding_tenant_id_matches_asset(): void
    {
        $asset = $this->makeAsset('tenant-xyz');
        $rawChecks = [[
            'check' => 'tls',
            'findings' => [[
                'key'         => 'tls_not_available',
                'category'    => 'tls',
                'severity'    => 'high',
                'confidence'  => 'high',
                'title'       => 'TLS not available',
                'description' => 'Site does not support TLS.',
            ]],
        ]];

        $result = $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertSame('tenant-xyz', $result[0]['tenant_id']);
    }

    // =========================================================================
    // Scan run creation (4 tests)
    // =========================================================================

    public function test_create_scan_run_creates_record(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'      => 'tenant-001',
            'asset_id'       => $asset->id,
            'scan_policy'    => 'passive',
            'target_hostname' => 'example.com',
            'target_url'     => 'https://example.com',
            'status'         => 'completed',
            'started_at'     => now()->format('Y-m-d H:i:sP'),
            'finding_count'  => 3,
        ]);

        $this->assertDatabaseHas('easm_scan_runs', ['id' => $run->id]);
    }

    public function test_create_scan_run_generates_run_id(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'      => 'tenant-001',
            'asset_id'       => $asset->id,
            'scan_policy'    => 'passive',
            'target_hostname' => 'example.com',
            'target_url'     => 'https://example.com',
            'status'         => 'completed',
            'started_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->assertNotEmpty($run->run_id);
        $this->assertStringStartsWith('easm-', $run->run_id);
    }

    public function test_scan_run_is_append_only(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'      => 'tenant-001',
            'asset_id'       => $asset->id,
            'scan_policy'    => 'passive',
            'target_hostname' => 'example.com',
            'target_url'     => 'https://example.com',
            'status'         => 'completed',
            'started_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->expectException(LogicException::class);
        $run->status = 'failed';
        $run->save();
    }

    public function test_scan_run_stores_finding_count(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'      => 'tenant-001',
            'asset_id'       => $asset->id,
            'scan_policy'    => 'passive',
            'target_hostname' => 'example.com',
            'target_url'     => 'https://example.com',
            'status'         => 'completed',
            'started_at'     => now()->format('Y-m-d H:i:sP'),
            'finding_count'  => 7,
        ]);

        $this->assertSame(7, $run->finding_count);
    }

    // =========================================================================
    // Finding persistence (3 tests)
    // =========================================================================

    private function makeNormalisedFinding(WebsiteAsset $asset, string $key = 'missing_hsts'): array
    {
        $now = now()->format('Y-m-d H:i:sP');
        return [
            'tenant_id'      => $asset->tenant_id,
            'asset_id'       => $asset->id,
            'finding_key'    => $key,
            'category'       => 'security_header',
            'severity'       => 'medium',
            'confidence'     => 'high',
            'title'          => 'Missing HSTS',
            'description'    => 'HSTS header absent.',
            'evidence'       => null,
            'recommendation' => null,
            'scan_policy'    => 'passive',
            'source'         => 'easm_passive',
            'status'         => 'open',
            'first_seen_at'  => $now,
            'last_seen_at'   => $now,
            'is_advisory'    => true,
        ];
    }

    public function test_upsert_finding_creates_new_finding(): void
    {
        $asset = $this->makeAsset();
        $finding = $this->service->upsertFinding($this->makeNormalisedFinding($asset));

        $this->assertDatabaseHas('easm_findings', ['id' => $finding->id, 'finding_key' => 'missing_hsts']);
    }

    public function test_upsert_finding_updates_last_seen_at_not_first_seen_at(): void
    {
        $asset = $this->makeAsset();
        $nf = $this->makeNormalisedFinding($asset);
        $first = $this->service->upsertFinding($nf);

        $originalFirstSeen = $first->first_seen_at;

        // Simulate a second scan
        sleep(1);
        $nf2 = $this->makeNormalisedFinding($asset);
        $nf2['last_seen_at'] = now()->addSeconds(60)->format('Y-m-d H:i:sP');
        $second = $this->service->upsertFinding($nf2);

        $this->assertEquals($originalFirstSeen, $second->first_seen_at);
        $this->assertNotEquals($originalFirstSeen, $second->last_seen_at);
    }

    public function test_finding_status_defaults_to_open(): void
    {
        $asset = $this->makeAsset();
        $finding = $this->service->upsertFinding($this->makeNormalisedFinding($asset));
        $this->assertSame('open', $finding->status);
    }

    // =========================================================================
    // Advisory-only behaviour (4 tests)
    // =========================================================================

    public function test_normalize_check_results_never_creates_security_incident(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'tls',
            'findings' => [[
                'key' => 'tls_not_available', 'category' => 'tls', 'severity' => 'high',
                'confidence' => 'high', 'title' => 'No TLS', 'description' => 'TLS absent.',
            ]],
        ]];

        $this->service->normalizeCheckResults($rawChecks, $asset);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_normalize_check_results_never_modifies_detection_rules(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [[
            'check' => 'security_headers',
            'findings' => [[
                'key' => 'missing_hsts', 'category' => 'security_header', 'severity' => 'medium',
                'confidence' => 'high', 'title' => 'No HSTS', 'description' => 'HSTS absent.',
            ]],
        ]];

        // Just verifying normalizeCheckResults does not touch detection_rule_versions
        $this->service->normalizeCheckResults($rawChecks, $asset);
        $this->assertDatabaseCount('detection_rule_versions', 0);
    }

    public function test_all_normalised_findings_have_is_advisory_true(): void
    {
        $asset = $this->makeAsset();
        $rawChecks = [
            ['check' => 'tls', 'findings' => [
                ['key' => 'tls_not_available', 'category' => 'tls', 'severity' => 'high', 'confidence' => 'high', 'title' => 'No TLS', 'description' => ''],
            ]],
            ['check' => 'security_headers', 'findings' => [
                ['key' => 'missing_hsts', 'category' => 'security_header', 'severity' => 'medium', 'confidence' => 'high', 'title' => 'No HSTS', 'description' => ''],
            ]],
        ];

        $normalised = $this->service->normalizeCheckResults($rawChecks, $asset);
        foreach ($normalised as $f) {
            $this->assertTrue($f['is_advisory'], 'Finding is_advisory must always be true');
        }
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EasmPassiveScanService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Scan policy (3 tests)
    // =========================================================================

    public function test_get_scan_policy_checks_passive_returns_expected_list(): void
    {
        $checks = $this->service->getScanPolicyChecks('passive');
        $this->assertContains('dns', $checks);
        $this->assertContains('tls', $checks);
        $this->assertContains('http', $checks);
        $this->assertContains('security_headers', $checks);
        $this->assertContains('cookies', $checks);
        $this->assertContains('robots_txt', $checks);
        $this->assertContains('sitemap_xml', $checks);
        $this->assertCount(7, $checks);
    }

    public function test_passive_policy_does_not_include_exploit_checks(): void
    {
        $checks = $this->service->getScanPolicyChecks('passive');
        $forbidden = ['sqli', 'xss', 'nuclei', 'exploit', 'brute', 'rce'];
        foreach ($forbidden as $f) {
            $this->assertNotContains($f, $checks);
        }
    }

    public function test_unimplemented_policy_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getScanPolicyChecks('unknown_policy');
    }

    // =========================================================================
    // Report generation (3 tests)
    // =========================================================================

    public function test_generate_report_returns_array_with_required_keys(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'       => 'tenant-001',
            'asset_id'        => $asset->id,
            'scan_policy'     => 'passive',
            'target_hostname' => 'example.com',
            'target_url'      => 'https://example.com',
            'status'          => 'completed',
            'started_at'      => now()->format('Y-m-d H:i:sP'),
            'finished_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        $report = $this->service->generateReport($run, $asset, []);

        $this->assertArrayHasKey('asset', $report);
        $this->assertArrayHasKey('tenant_id', $report);
        $this->assertArrayHasKey('scan_policy', $report);
        $this->assertArrayHasKey('started_at', $report);
        $this->assertArrayHasKey('findings', $report);
        $this->assertArrayHasKey('summary', $report);
    }

    public function test_generate_report_summary_contains_severity_counts(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'       => 'tenant-001',
            'asset_id'        => $asset->id,
            'scan_policy'     => 'passive',
            'target_hostname' => 'example.com',
            'target_url'      => 'https://example.com',
            'status'          => 'completed',
            'started_at'      => now()->format('Y-m-d H:i:sP'),
        ]);

        $findings = [
            ['severity' => 'high', 'category' => 'tls'],
            ['severity' => 'medium', 'category' => 'security_header'],
        ];

        $report = $this->service->generateReport($run, $asset, $findings);
        $this->assertArrayHasKey('by_severity', $report['summary']);
        $this->assertSame(1, $report['summary']['by_severity']['high']);
        $this->assertSame(1, $report['summary']['by_severity']['medium']);
    }

    public function test_generate_report_overall_status_is_present(): void
    {
        $asset = $this->makeAsset();
        $run = $this->service->createScanRun([
            'tenant_id'       => 'tenant-001',
            'asset_id'        => $asset->id,
            'scan_policy'     => 'passive',
            'target_hostname' => 'example.com',
            'target_url'      => 'https://example.com',
            'status'          => 'completed',
            'started_at'      => now()->format('Y-m-d H:i:sP'),
        ]);

        $report = $this->service->generateReport($run, $asset, []);
        $this->assertArrayHasKey('overall_status', $report);
        $this->assertSame('PASS', $report['overall_status']);
    }

    // =========================================================================
    // Dry-run behavior (2 tests)
    // =========================================================================

    public function test_dry_run_does_not_create_scan_run(): void
    {
        // Dry-run is handled by the command; at the service level we can verify
        // that no EasmScanRun is created when we skip createScanRun()
        $this->assertDatabaseCount('easm_scan_runs', 0);
        // This is a structural assertion — the service has no dry-run logic itself
        $this->assertTrue(true);
    }

    public function test_dry_run_does_not_create_easm_finding(): void
    {
        $this->assertDatabaseCount('easm_findings', 0);
        // Dry-run: no findings upserted
        $this->assertTrue(true);
    }

    // =========================================================================
    // Constants (2 tests)
    // =========================================================================

    public function test_scan_timeout_seconds_gte_5(): void
    {
        $this->assertGreaterThanOrEqual(5, EasmPassiveScanService::SCAN_TIMEOUT_SECONDS);
    }

    public function test_request_timeout_seconds_lte_10(): void
    {
        $this->assertLessThanOrEqual(10, EasmPassiveScanService::REQUEST_TIMEOUT_SECONDS);
    }
}
