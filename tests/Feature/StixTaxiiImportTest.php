<?php

namespace Tests\Feature;

use App\Services\StixTaxiiImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StixTaxiiImportTest extends TestCase
{
    use RefreshDatabase;

    private StixTaxiiImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StixTaxiiImportService::class);
    }

    // ---- Pattern extraction -------------------------------------------------

    public function test_extracts_ipv4_domain_url_from_patterns(): void
    {
        $this->assertSame(
            [['ioc_type' => 'ip', 'ioc_value' => '198.51.100.23']],
            $this->service->extractFromPattern("[ipv4-addr:value = '198.51.100.23']")
        );
        $this->assertSame(
            [['ioc_type' => 'domain', 'ioc_value' => 'evil.example']],
            $this->service->extractFromPattern("[domain-name:value = 'Evil.example']") // lowercased
        );
        $this->assertSame(
            [['ioc_type' => 'url', 'ioc_value' => 'http://evil.example/x']],
            $this->service->extractFromPattern("[url:value = 'http://evil.example/x']")
        );
    }

    public function test_extracts_file_hash_pattern(): void
    {
        $pairs = $this->service->extractFromPattern("[file:hashes.'SHA-256' = 'ABCDEF0123']");
        $this->assertSame([['ioc_type' => 'hash', 'ioc_value' => 'abcdef0123']], $pairs);
    }

    public function test_unsupported_pattern_yields_nothing(): void
    {
        $this->assertSame([], $this->service->extractFromPattern("[email-addr:value = 'a@b.example']"));
    }

    public function test_ipv6_maps_to_ip(): void
    {
        $pairs = $this->service->extractFromPattern("[ipv6-addr:value = '2001:db8::1']");
        $this->assertSame([['ioc_type' => 'ip', 'ioc_value' => '2001:db8::1']], $pairs);
    }

    // ---- Indicator parsing --------------------------------------------------

    public function test_non_indicator_object_is_skipped(): void
    {
        $this->assertSame([], $this->service->parseIndicator(['type' => 'malware', 'name' => 'x']));
    }

    public function test_confidence_maps_to_reputation(): void
    {
        $high = $this->service->parseIndicator([
            'type' => 'indicator', 'pattern' => "[ipv4-addr:value = '1.1.1.1']", 'confidence' => 90,
        ]);
        $this->assertSame('malicious', $high[0]['reputation']);

        $mid = $this->service->parseIndicator([
            'type' => 'indicator', 'pattern' => "[ipv4-addr:value = '1.1.1.2']", 'confidence' => 50,
        ]);
        $this->assertSame('suspicious', $mid[0]['reputation']);

        $low = $this->service->parseIndicator([
            'type' => 'indicator', 'pattern' => "[ipv4-addr:value = '1.1.1.3']", 'confidence' => 10,
        ]);
        $this->assertSame('unknown', $low[0]['reputation']);
    }

    public function test_valid_until_becomes_expiry(): void
    {
        $rows = $this->service->parseIndicator([
            'type' => 'indicator',
            'pattern' => "[ipv4-addr:value = '1.1.1.1']",
            'valid_until' => '2027-01-01T00:00:00Z',
        ]);
        $this->assertStringStartsWith('2027-01-01', $rows[0]['expires_at']);
    }

    // ---- Import into threat_iocs -------------------------------------------

    public function test_import_bundle_upserts_iocs(): void
    {
        $bundle = json_decode(file_get_contents(base_path('storage/app/threat-intel/sample-stix-bundle.json')), true);
        $result = $this->service->importBundle($bundle, 'unit-test', 'Unit Feed');

        // 4 indicators (ip, domain, hash, url); the malware object is skipped.
        $this->assertSame(4, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseHas('threat_iocs', [
            'ioc_type' => 'ip', 'ioc_value' => '198.51.100.23', 'source' => 'unit-test', 'reputation' => 'malicious',
        ]);
        $this->assertDatabaseHas('threat_iocs', [
            'ioc_type' => 'domain', 'ioc_value' => 'evil-login.example', 'reputation' => 'suspicious',
        ]);
        $this->assertDatabaseHas('threat_iocs', ['ioc_type' => 'hash', 'ioc_value' => strtolower('AABBCCDDEEFF00112233445566778899AABBCCDDEEFF00112233445566778899')]);
        $this->assertDatabaseHas('external_ioc_feeds', ['feed_type' => 'stix-taxii', 'name' => 'Unit Feed', 'last_import_count' => 4]);
    }

    public function test_reimport_is_idempotent_and_preserves_ioc_id(): void
    {
        $bundle = ['objects' => [[
            'type' => 'indicator', 'id' => 'indicator--x', 'pattern' => "[ipv4-addr:value = '203.0.113.9']", 'confidence' => 80,
        ]]];

        $this->service->importBundle($bundle, 'unit-test');
        $first = DB::table('threat_iocs')->where('ioc_value', '203.0.113.9')->first();

        $this->service->importBundle($bundle, 'unit-test');
        $this->assertSame(1, DB::table('threat_iocs')->where('ioc_value', '203.0.113.9')->count());
        $second = DB::table('threat_iocs')->where('ioc_value', '203.0.113.9')->first();
        $this->assertSame($first->ioc_id, $second->ioc_id, 'ioc_id must be preserved across re-import');
    }

    // ---- TAXII poll ---------------------------------------------------------

    public function test_poll_taxii_returns_objects(): void
    {
        Http::fake(['*/objects/' => Http::response([
            'objects' => [['type' => 'indicator', 'pattern' => "[ipv4-addr:value = '192.0.2.5']", 'confidence' => 90]],
        ], 200)]);

        $bundle = $this->service->pollTaxii('https://taxii.example/collections/abc');
        $result = $this->service->importBundle($bundle, 'taxii-test');

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('threat_iocs', ['ioc_value' => '192.0.2.5', 'source' => 'taxii-test']);
    }

    public function test_poll_taxii_failure_is_graceful(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);
        $this->assertSame(['objects' => []], $this->service->pollTaxii('https://taxii.example/collections/abc'));
    }

    // ---- Command ------------------------------------------------------------

    public function test_command_imports_bundled_sample(): void
    {
        $this->artisan('ti:import-stix')
            ->assertExitCode(0);
        $this->assertGreaterThanOrEqual(4, DB::table('threat_iocs')->where('source', 'stix-taxii')->count());
    }

    // ---- Safety -------------------------------------------------------------

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(StixTaxiiImportService::ADVISORY_ONLY);
        $this->assertTrue(StixTaxiiImportService::NO_AUTO_RESPONSE);
    }
}
