<?php

namespace Tests\Feature;

use App\Models\AssetCriticality;
use App\Models\AssetInventory;
use App\Models\User;
use App\Services\AssetContextService;
use App\Services\ThreatHuntingService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class AssetInventoryTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private AssetContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AssetContextService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return AssetContextService::class;
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(AssetContextService::ADVISORY_ONLY);
    }

    public function test_no_auto_response_constant_is_true(): void
    {
        $this->assertTrue(AssetContextService::NO_AUTO_RESPONSE);
    }

    // =========================================================================
    // registerAsset
    // =========================================================================

    public function test_register_asset_creates_record(): void
    {
        $asset = $this->service->registerAsset('t1', 'web-01', '10.0.0.1', 'platform-team', 'production', 'server');

        $this->assertInstanceOf(AssetInventory::class, $asset);
        $this->assertSame('web-01', $asset->hostname);
        $this->assertSame('10.0.0.1', $asset->ip_address);
        $this->assertSame('production', $asset->environment);
        $this->assertNotEmpty($asset->external_id);
    }

    public function test_register_asset_invalid_environment_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerAsset('t1', 'web-02', null, null, 'moon-base');
    }

    public function test_register_asset_invalid_asset_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerAsset('t1', 'web-03', null, null, 'production', 'time-machine');
    }

    public function test_register_asset_upserts_by_external_id(): void
    {
        $first = $this->service->registerAsset('t1', 'web-04', '10.0.0.4', null, 'production', 'server', 'ext-1');
        $second = $this->service->registerAsset('t1', 'web-04-renamed', '10.0.0.44', null, 'staging', 'server', 'ext-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('web-04-renamed', $second->fresh()->hostname);
        $this->assertSame(1, AssetInventory::where('external_id', 'ext-1')->count());
    }

    /** ASSET-TENANT-OVERWRITE: same external_id under different tenants must not collide. */
    public function test_register_asset_same_external_id_different_tenants_does_not_overwrite(): void
    {
        $tenantA = $this->service->registerAsset('tenant-a', 'a-host', '10.9.0.1', null, 'production', 'server', 'shared-ext-id');
        $tenantB = $this->service->registerAsset('tenant-b', 'b-host', '10.9.0.2', null, 'staging', 'workstation', 'shared-ext-id');

        $this->assertNotSame($tenantA->id, $tenantB->id);
        $this->assertSame('tenant-a', $tenantA->fresh()->tenant_id);
        $this->assertSame('a-host', $tenantA->fresh()->hostname);
        $this->assertSame('tenant-b', $tenantB->fresh()->tenant_id);
        $this->assertSame(2, AssetInventory::where('external_id', 'shared-ext-id')->count());
    }

    // =========================================================================
    // setCriticality
    // =========================================================================

    public function test_set_criticality_creates_record(): void
    {
        $asset = $this->service->registerAsset('t1', 'db-01', '10.0.0.5');
        $criticality = $this->service->setCriticality($asset->id, 'crown_jewel', 'primary database', 'analyst@example.com');

        $this->assertInstanceOf(AssetCriticality::class, $criticality);
        $this->assertSame('crown_jewel', $criticality->criticality_tier);
        $this->assertNotNull($criticality->assessed_at);
    }

    public function test_set_criticality_invalid_tier_throws(): void
    {
        $asset = $this->service->registerAsset('t1', 'db-02', '10.0.0.6');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setCriticality($asset->id, 'ultra-mega-critical', null, 'analyst@example.com');
    }

    public function test_set_criticality_is_idempotent_upsert(): void
    {
        $asset = $this->service->registerAsset('t1', 'db-03', '10.0.0.7');
        $this->service->setCriticality($asset->id, 'high', null, 'a@example.com');
        $this->service->setCriticality($asset->id, 'low', null, 'a@example.com');

        $this->assertSame(1, AssetCriticality::where('asset_id', $asset->id)->count());
        $this->assertSame('low', AssetCriticality::where('asset_id', $asset->id)->value('criticality_tier'));
    }

    public function test_set_criticality_unknown_asset_throws(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->setCriticality(999999, 'high', null, 'a@example.com');
    }

    // =========================================================================
    // importCsv
    // =========================================================================

    public function test_import_csv_imports_valid_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset_csv');
        file_put_contents($path, "hostname,ip_address,owner,environment,asset_type\nweb-10,10.0.1.10,team-a,production,server\nweb-11,10.0.1.11,team-b,staging,workstation\n");

        $result = $this->service->importCsv('t1', $path, 'cli');
        unlink($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('asset_inventory', ['hostname' => 'web-10', 'tenant_id' => 't1']);
        $this->assertDatabaseHas('asset_inventory', ['hostname' => 'web-11', 'tenant_id' => 't1']);
    }

    public function test_import_csv_skips_rows_missing_hostname(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset_csv');
        file_put_contents($path, "hostname,ip_address\n,10.0.1.12\nweb-13,10.0.1.13\n");

        $result = $this->service->importCsv('t1', $path, 'cli');
        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_import_csv_falls_back_to_default_environment_when_invalid(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asset_csv');
        file_put_contents($path, "hostname,environment\nweb-14,atlantis\n");

        $this->service->importCsv('t1', $path, 'cli');
        unlink($path);

        $this->assertDatabaseHas('asset_inventory', ['hostname' => 'web-14', 'environment' => 'production']);
    }

    public function test_import_csv_missing_file_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->importCsv('t1', '/no/such/file.csv', 'cli');
    }

    // =========================================================================
    // findAssetForAlert / assetContextForIps
    // =========================================================================

    public function test_find_asset_for_alert_matches_by_ip(): void
    {
        $this->service->registerAsset('t1', 'app-01', '10.0.2.1');
        $found = $this->service->findAssetForAlert('t1', '10.0.2.1');

        $this->assertNotNull($found);
        $this->assertSame('app-01', $found->hostname);
    }

    public function test_find_asset_for_alert_matches_by_hostname(): void
    {
        $this->service->registerAsset('t1', 'app-02', null);
        $found = $this->service->findAssetForAlert('t1', null, 'app-02');

        $this->assertNotNull($found);
    }

    public function test_find_asset_for_alert_returns_null_when_no_match(): void
    {
        $this->assertNull($this->service->findAssetForAlert('t1', '10.9.9.9', 'ghost-host'));
    }

    public function test_find_asset_for_alert_is_tenant_scoped(): void
    {
        $this->service->registerAsset('tenant-a', 'shared-ip-host', '10.0.3.1');
        $found = $this->service->findAssetForAlert('tenant-b', '10.0.3.1');

        $this->assertNull($found);
    }

    public function test_asset_context_for_ips_returns_matches_with_criticality(): void
    {
        $asset = $this->service->registerAsset('t1', 'crit-01', '10.0.4.1');
        $this->service->setCriticality($asset->id, 'crown_jewel', null, 'a@example.com');

        $context = $this->service->assetContextForIps('t1', ['10.0.4.1', '10.0.4.99']);

        $this->assertCount(1, $context);
        $this->assertSame('crown_jewel', $context->first()->criticality->criticality_tier);
    }

    public function test_asset_context_for_ips_empty_input_returns_empty_collection(): void
    {
        $this->assertTrue($this->service->assetContextForIps('t1', [])->isEmpty());
    }

    // =========================================================================
    // dashboardStats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $this->service->registerAsset('t1', 'stat-01', '10.0.5.1', null, 'production');
        $stats = $this->service->dashboardStats('t1');

        $this->assertArrayHasKey('total_assets', $stats);
        $this->assertArrayHasKey('by_environment', $stats);
        $this->assertArrayHasKey('by_criticality', $stats);
        $this->assertArrayHasKey('crown_jewel_count', $stats);
        $this->assertSame(1, $stats['total_assets']);
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_admin_has_view_and_manage_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'assetinventory.view'));
        $this->assertTrue(Rbac::can($admin, 'assetinventory.manage'));
    }

    public function test_analyst_has_view_and_manage_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'assetinventory.view'));
        $this->assertTrue(Rbac::can($analyst, 'assetinventory.manage'));
    }

    public function test_viewer_has_view_but_not_manage_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertTrue(Rbac::can($viewer, 'assetinventory.view'));
        $this->assertFalse(Rbac::can($viewer, 'assetinventory.manage'));
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_index_route_requires_auth(): void
    {
        $this->get('/asset-inventory')->assertRedirect('/login');
    }

    public function test_index_route_accessible_to_viewer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/asset-inventory')->assertOk();
    }

    public function test_store_route_forbidden_for_viewer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->post('/asset-inventory', [
            'hostname' => 'x', 'environment' => 'production', 'asset_type' => 'server',
        ])->assertForbidden();
    }

    public function test_store_route_succeeds_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/asset-inventory', [
            'hostname' => 'route-test-01', 'environment' => 'production', 'asset_type' => 'server',
        ])->assertRedirect();

        $this->assertDatabaseHas('asset_inventory', ['hostname' => 'route-test-01']);
    }

    public function test_import_route_succeeds_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('assets.csv', "hostname,environment\nroute-csv-01,production\n");

        $this->actingAs($admin)->post('/asset-inventory/import', ['csv' => $file])->assertRedirect();
        $this->assertDatabaseHas('asset_inventory', ['hostname' => 'route-csv-01']);
    }

    // =========================================================================
    // ThreatHuntingService domain registration
    // =========================================================================

    public function test_hunt_domains_include_asset_inventory(): void
    {
        $svc = app(ThreatHuntingService::class);
        $this->assertContains('asset_inventory', $svc->supportedDomains());
        $this->assertContains('asset_criticality', $svc->supportedDomains());
    }

    public function test_total_hunt_domains_is_179(): void
    {
        $this->assertCount(179, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_hunt_asset_inventory_domain(): void
    {
        $this->service->registerAsset('t1', 'hunt-host', '10.0.6.1');
        $results = app(ThreatHuntingService::class)->hunt('asset_inventory', ['hostname' => 'hunt-host']);
        $this->assertCount(1, $results);
    }

    // =========================================================================
    // Incident detail page — advisory asset context panel
    // =========================================================================

    public function test_incident_show_page_renders_asset_context_panel(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        \Illuminate\Support\Facades\DB::table('security_incidents')->insert([
            'incident_id' => 'incident-asset-1',
            'title' => 'Asset Context Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('security_alerts')->insert([
            'alert_id' => 'alert-asset-1',
            'detected_at' => now(),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'ip' => '10.0.7.1',
            'incident_id' => 'incident-asset-1',
            'score' => 0.9,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asset = $this->service->registerAsset('default', 'crown-host', '10.0.7.1');
        $this->service->setCriticality($asset->id, 'crown_jewel', null, 'a@example.com');

        $this->actingAs($analyst)
            ->get('/soc/incidents/incident-asset-1')
            ->assertOk()
            ->assertSee('Asset Context', false)
            ->assertSee('crown-host')
            ->assertSee('crown_jewel');
    }

    public function test_incident_show_page_renders_without_asset_match(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        \Illuminate\Support\Facades\DB::table('security_incidents')->insert([
            'incident_id' => 'incident-no-asset-1',
            'title' => 'No Asset Match Incident',
            'status' => 'open',
            'severity' => 'medium',
            'confidence' => 0.7,
            'first_seen_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc/incidents/incident-no-asset-1')
            ->assertOk();
    }
}
