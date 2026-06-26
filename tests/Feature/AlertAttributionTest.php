<?php

namespace Tests\Feature;

use App\Models\AlertAttributionContext;
use App\Services\AlertAttributionService;
use App\Services\GeoAsnLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ATTR-002: Alert attribution context (advisory OSINT enrichment).
 * ATTR-003: GeoAsnLookupService (offline fixture lookup).
 */
class AlertAttributionTest extends TestCase
{
    use RefreshDatabase;

    private GeoAsnLookupService $geo;
    private AlertAttributionService $attribution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo         = new GeoAsnLookupService();
        $this->attribution = new AlertAttributionService($this->geo);
    }

    // ── ATTR-003: GeoAsnLookupService ────────────────────────────────────────

    public function test_geo_constants_are_set(): void
    {
        $this->assertTrue(GeoAsnLookupService::ADVISORY_ONLY);
        $this->assertTrue(GeoAsnLookupService::OFFLINE_ONLY);
        $this->assertTrue(GeoAsnLookupService::NO_EXTERNAL_API);
    }

    public function test_lookup_private_rfc1918_10x(): void
    {
        $r = $this->geo->lookup('10.0.0.1');
        $this->assertSame('PRIVATE', $r['country_code']);
        $this->assertSame('private', $r['ip_type']);
    }

    public function test_lookup_private_rfc1918_172(): void
    {
        $r = $this->geo->lookup('172.16.5.1');
        $this->assertSame('PRIVATE', $r['country_code']);
        $this->assertSame('private', $r['ip_type']);
    }

    public function test_lookup_private_rfc1918_192168(): void
    {
        $r = $this->geo->lookup('192.168.1.100');
        $this->assertSame('PRIVATE', $r['country_code']);
        $this->assertSame('private', $r['ip_type']);
    }

    public function test_lookup_loopback(): void
    {
        $r = $this->geo->lookup('127.0.0.1');
        $this->assertSame('LOOPBACK', $r['country_code']);
        $this->assertSame('loopback', $r['ip_type']);
    }

    public function test_lookup_documentation_range(): void
    {
        $r = $this->geo->lookup('192.0.2.1');
        $this->assertSame('DOCUMENTATION', $r['country_code']);
        $this->assertSame('documentation', $r['ip_type']);
    }

    public function test_lookup_unknown_ip_returns_public_type(): void
    {
        $r = $this->geo->lookup('8.8.8.8');
        $this->assertSame('UNKNOWN', $r['country_code']);
        $this->assertSame('public', $r['ip_type']);
    }

    public function test_is_private_returns_true_for_rfc1918(): void
    {
        $this->assertTrue($this->geo->isPrivate('10.1.2.3'));
        $this->assertTrue($this->geo->isPrivate('192.168.0.1'));
        $this->assertTrue($this->geo->isPrivate('172.20.0.1'));
    }

    public function test_is_private_returns_false_for_public(): void
    {
        $this->assertFalse($this->geo->isPrivate('8.8.8.8'));
    }

    public function test_is_private_returns_true_for_loopback(): void
    {
        $this->assertTrue($this->geo->isPrivate('127.0.0.1'));
    }

    public function test_lookup_returns_required_keys(): void
    {
        $r = $this->geo->lookup('10.0.0.1');
        $this->assertArrayHasKey('country_code', $r);
        $this->assertArrayHasKey('country_name', $r);
        $this->assertArrayHasKey('asn', $r);
        $this->assertArrayHasKey('asn_org', $r);
        $this->assertArrayHasKey('ip_type', $r);
        $this->assertArrayHasKey('reputation_hint', $r);
    }

    public function test_lookup_reputation_hint_known_ip(): void
    {
        $r = $this->geo->lookup('10.0.0.1');
        $this->assertSame('internal_scanner', $r['reputation_hint']);
    }

    public function test_lookup_reputation_hint_unknown_ip(): void
    {
        $r = $this->geo->lookup('10.99.99.99');
        $this->assertNull($r['reputation_hint']);
    }

    public function test_demo_asns_returns_at_least_4(): void
    {
        $asns = $this->geo->demoAsns();
        $this->assertGreaterThanOrEqual(4, count($asns));
    }

    // ── ATTR-002: Migration ───────────────────────────────────────────────────

    public function test_alert_attribution_context_table_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('alert_attribution_context')
        );
    }

    public function test_alert_attribution_context_has_required_columns(): void
    {
        foreach (['attribution_id', 'alert_id', 'alert_type', 'ip', 'country_code', 'asn_org', 'confidence', 'is_advisory', 'enrichment_source'] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('alert_attribution_context', $col),
                "missing column: {$col}"
            );
        }
    }

    // ── ATTR-002: AlertAttributionService ────────────────────────────────────

    public function test_attribution_service_advisory_only_flag(): void
    {
        $this->assertTrue(AlertAttributionService::ADVISORY_ONLY);
        $this->assertTrue(AlertAttributionService::OFFLINE_ONLY);
        $this->assertTrue(AlertAttributionService::NO_AUTO_RESPONSE);
    }

    public function test_enrich_alert_creates_attribution_record(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'attr-test-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'    => 'high',
            'ip'          => '10.0.0.5',
        ]);

        $record = $this->attribution->enrichAlert('attr-test-001', 'IDENTITY_MFA_FAILURE_BURST', '10.0.0.5');

        $this->assertInstanceOf(AlertAttributionContext::class, $record);
        $this->assertSame('attr-test-001', $record->alert_id);
        $this->assertSame('PRIVATE', $record->country_code);
        $this->assertSame('private', $record->ip_type);
        $this->assertTrue($record->is_advisory);
        $this->assertSame('offline_fixture', $record->enrichment_source);
    }

    public function test_enrich_alert_is_idempotent(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'attr-test-002',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'CLOUD_MASS_DOWNLOAD',
            'severity'    => 'medium',
        ]);

        $first  = $this->attribution->enrichAlert('attr-test-002', 'CLOUD_MASS_DOWNLOAD', null);
        $second = $this->attribution->enrichAlert('attr-test-002', 'CLOUD_MASS_DOWNLOAD', null);

        $this->assertSame($first->attribution_id, $second->attribution_id);
        $this->assertSame(1, AlertAttributionContext::where('alert_id', 'attr-test-002')->count());
    }

    public function test_enrich_alert_null_ip_gives_low_confidence(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'attr-test-003',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'CLOUD_NEW_ACCESS_KEY',
            'severity'    => 'high',
        ]);

        $record = $this->attribution->enrichAlert('attr-test-003', 'CLOUD_NEW_ACCESS_KEY', null);
        $this->assertLessThan(0.5, $record->confidence);
    }

    public function test_enrich_alert_private_ip_gives_low_confidence(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'attr-test-004',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'IDENTITY_RISKY_IP_LOGIN',
            'severity'    => 'high',
            'ip'          => '192.168.1.50',
        ]);

        $record = $this->attribution->enrichAlert('attr-test-004', 'IDENTITY_RISKY_IP_LOGIN', '192.168.1.50');
        $this->assertLessThanOrEqual(0.3, $record->confidence);
    }

    public function test_enrich_recent_alerts_returns_count(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'attr-test-005',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'SAAS_UNUSUAL_ADMIN_ACTIVITY',
            'severity'    => 'medium',
            'ip'          => '10.0.0.9',
        ]);

        $count = $this->attribution->enrichRecentAlerts(10);
        $this->assertSame(1, $count);

        $count2 = $this->attribution->enrichRecentAlerts(10);
        $this->assertSame(0, $count2, 'already enriched alerts must be skipped');
    }

    public function test_latest_returns_collection(): void
    {
        $results = $this->attribution->latest(10);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    // ── ATTR-002: EnrichAlertAttributionCommand ───────────────────────────────

    public function test_enrich_attribution_command_dry_run(): void
    {
        $this->artisan('alerts:enrich-attribution --dry-run --minutes=60')
            ->assertExitCode(0);
    }

    public function test_enrich_attribution_command_runs_successfully(): void
    {
        $this->artisan('alerts:enrich-attribution --minutes=5')
            ->assertExitCode(0);
    }

    // ── ATTR-002: Controller + view ───────────────────────────────────────────

    public function test_attribution_index_returns_200(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->get(route('security.attribution'))
            ->assertStatus(200);
    }

    public function test_attribution_index_shows_advisory_note(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->get(route('security.attribution'))
            ->assertSee('Advisory OSINT enrichment');
    }

    // ── ATTR-002: Python validator script ────────────────────────────────────

    public function test_xdr_attribution_validate_script_exists(): void
    {
        $this->assertFileExists(base_path('scripts/xdr_attribution_validate.py'));
    }
}
