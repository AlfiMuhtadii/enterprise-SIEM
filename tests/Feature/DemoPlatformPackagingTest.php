<?php

namespace Tests\Feature;

use App\Models\DemoScenarioRun;
use App\Models\DemoReadinessSnapshot;
use App\Models\PlatformShowcaseExport;
use App\Services\DemoPlatformPackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class DemoPlatformPackagingTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private DemoPlatformPackagingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DemoPlatformPackagingService();
    }

    protected function getAdvisoryServiceClass(): string
    {
        return DemoPlatformPackagingService::class;
    }

    // -----------------------------------------------------------------------
    // Table existence
    // -----------------------------------------------------------------------

    public function test_demo_scenario_runs_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('demo_scenario_runs'));
    }

    public function test_demo_readiness_snapshots_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('demo_readiness_snapshots'));
    }

    public function test_platform_showcase_exports_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('platform_showcase_exports'));
    }

    // -----------------------------------------------------------------------
    // Append-only: no updated_at
    // -----------------------------------------------------------------------

    public function test_demo_scenario_runs_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('demo_scenario_runs', 'updated_at'));
    }

    public function test_demo_readiness_snapshots_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('demo_readiness_snapshots', 'updated_at'));
    }

    public function test_platform_showcase_exports_has_no_updated_at(): void
    {
        $this->assertFalse(\Schema::hasColumn('platform_showcase_exports', 'updated_at'));
    }

    // -----------------------------------------------------------------------
    // Service constants
    // -----------------------------------------------------------------------

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(DemoPlatformPackagingService::ADVISORY_ONLY);
    }

    public function test_demo_mode_constant_is_true(): void
    {
        $this->assertTrue(DemoPlatformPackagingService::DEMO_MODE);
    }

    public function test_max_scenario_events_constant(): void
    {
        $this->assertEquals(200, DemoPlatformPackagingService::MAX_SCENARIO_EVENTS);
    }

    public function test_max_dataset_size_constant(): void
    {
        $this->assertEquals(500, DemoPlatformPackagingService::MAX_DATASET_SIZE);
    }

    // -----------------------------------------------------------------------
    // No forbidden methods
    // -----------------------------------------------------------------------

    public function test_no_real_malware_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'realMalware'));
    }

    public function test_no_deploy_ransomware_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'deployRansomware'));
    }

    // -----------------------------------------------------------------------
    // Demo scenario scenarios
    // -----------------------------------------------------------------------

    public function test_all_demo_scenarios_are_valid(): void
    {
        foreach (DemoScenarioRun::DEMO_SCENARIOS as $scenario) {
            $result = $this->svc->launchDemoScenario($scenario, []);
            $this->assertInstanceOf(DemoScenarioRun::class, $result);
        }
    }

    public function test_scenario_launch_sets_lab_safe_flag(): void
    {
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->assertTrue((bool) $result->is_lab_safe);
    }

    public function test_scenario_launch_sets_not_destructive(): void
    {
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->assertFalse((bool) $result->is_destructive);
    }

    public function test_scenario_launch_sets_demo_mode(): void
    {
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->assertTrue((bool) $result->demo_mode);
    }

    public function test_scenario_launch_sets_no_real_malware(): void
    {
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->assertFalse((bool) $result->has_real_malware);
    }

    public function test_scenario_launch_sets_no_autonomous_remediation(): void
    {
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->assertFalse((bool) $result->has_autonomous_remediation);
    }

    public function test_scenario_launch_rejects_unknown_scenario(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->launchDemoScenario('unknown_exploit_scenario', []);
    }

    public function test_scenario_event_count_bounded_to_max(): void
    {
        $events = array_fill(0, 300, ['type' => 'test_event', 'data' => []]);
        $result = $this->svc->launchDemoScenario('phishing_attack_chain', $events);
        $this->assertLessThanOrEqual(DemoPlatformPackagingService::MAX_SCENARIO_EVENTS, $result->event_count);
    }

    // -----------------------------------------------------------------------
    // Readiness snapshot
    // -----------------------------------------------------------------------

    public function test_readiness_snapshot_returns_instance(): void
    {
        $result = $this->svc->generateDemoReadinessSnapshot();
        $this->assertInstanceOf(DemoReadinessSnapshot::class, $result);
    }

    public function test_readiness_snapshot_hash_is_deterministic(): void
    {
        $snap1 = $this->svc->generateDemoReadinessSnapshot();
        $snap2 = $this->svc->generateDemoReadinessSnapshot();
        $this->assertEquals($snap1->snapshot_hash, $snap2->snapshot_hash);
    }

    public function test_readiness_snapshot_has_overall_ready_field(): void
    {
        $result = $this->svc->generateDemoReadinessSnapshot();
        $this->assertNotNull($result->overall_ready);
    }

    // -----------------------------------------------------------------------
    // Capability matrix
    // -----------------------------------------------------------------------

    public function test_capability_matrix_returns_expected_categories(): void
    {
        $matrix = $this->svc->generateCapabilityMatrix();
        $expected = ['siem', 'xdr', 'soar', 'ueba', 'endpoint', 'threat_hunting', 'operational_governance'];
        foreach ($expected as $cat) {
            $this->assertArrayHasKey($cat, $matrix, "Missing capability category: $cat");
        }
    }

    public function test_capability_matrix_categories_have_items(): void
    {
        $matrix = $this->svc->generateCapabilityMatrix();
        foreach ($matrix as $category => $caps) {
            $this->assertNotEmpty($caps, "Category $category should have capabilities");
        }
    }

    // -----------------------------------------------------------------------
    // Showcase export
    // -----------------------------------------------------------------------

    public function test_showcase_export_returns_instance(): void
    {
        $result = $this->svc->exportPlatformShowcase('capability_matrix', 'json');
        $this->assertInstanceOf(PlatformShowcaseExport::class, $result);
    }

    public function test_showcase_export_is_not_fabricated(): void
    {
        $result = $this->svc->exportPlatformShowcase('capability_matrix', 'json');
        $this->assertFalse((bool) $result->is_fabricated);
    }

    public function test_showcase_export_is_deterministic(): void
    {
        $exp1 = $this->svc->exportPlatformShowcase('architecture_summary', 'json');
        $exp2 = $this->svc->exportPlatformShowcase('architecture_summary', 'json');
        $this->assertEquals($exp1->export_hash, $exp2->export_hash);
    }

    public function test_showcase_export_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->exportPlatformShowcase('unknown_export_type', 'json');
    }

    // -----------------------------------------------------------------------
    // ThreatHuntingService domain count
    // -----------------------------------------------------------------------

    public function test_threat_hunting_domain_count_is_161(): void
    {
        $this->assertCount(
            161,
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS,
            'Should have 161 threat hunting domains after all phases through Final Demo / Portfolio / Thesis Packaging Phase 1'
        );
    }

    // -----------------------------------------------------------------------
    // Route authentication
    // -----------------------------------------------------------------------

    public function test_demo_platform_dashboard_requires_auth(): void
    {
        $response = $this->get('/demo-platform');
        $response->assertRedirect('/login');
    }

    public function test_demo_platform_scenarios_requires_auth(): void
    {
        $response = $this->get('/demo-platform/scenarios');
        $response->assertRedirect('/login');
    }

    public function test_demo_platform_readiness_requires_auth(): void
    {
        $response = $this->get('/demo-platform/readiness');
        $response->assertRedirect('/login');
    }

    public function test_demo_platform_showcase_requires_auth(): void
    {
        $response = $this->get('/demo-platform/showcase');
        $response->assertRedirect('/login');
    }

    public function test_demo_platform_walkthrough_requires_auth(): void
    {
        $response = $this->get('/demo-platform/walkthrough');
        $response->assertRedirect('/login');
    }

    // -----------------------------------------------------------------------
    // Append-only integrity
    // -----------------------------------------------------------------------

    public function test_demo_scenario_runs_are_append_only(): void
    {
        $this->svc->launchDemoScenario('phishing_attack_chain', []);
        $this->svc->launchDemoScenario('lolbin_execution_chain', []);
        $this->assertEquals(2, DemoScenarioRun::count());
    }

    public function test_demo_readiness_snapshots_are_append_only(): void
    {
        $this->svc->generateDemoReadinessSnapshot();
        $this->svc->generateDemoReadinessSnapshot();
        $this->assertEquals(2, DemoReadinessSnapshot::count());
    }

    // -----------------------------------------------------------------------
    // Static fixture files
    // -----------------------------------------------------------------------

    public function test_phishing_attack_chain_fixture_exists(): void
    {
        $path = base_path('fixtures/demo/scenarios/phishing_attack_chain.json');
        $this->assertFileExists($path);
    }

    public function test_lolbin_execution_chain_fixture_exists(): void
    {
        $path = base_path('fixtures/demo/scenarios/lolbin_execution_chain.json');
        $this->assertFileExists($path);
    }

    public function test_noisy_enterprise_simulation_fixture_exists(): void
    {
        $path = base_path('fixtures/demo/scenarios/noisy_enterprise_simulation.json');
        $this->assertFileExists($path);
    }

    // -----------------------------------------------------------------------
    // Dashboard stats
    // -----------------------------------------------------------------------

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->dashboardStats();
        $this->assertArrayHasKey('demo_runs', $stats);
        $this->assertArrayHasKey('readiness_snapshots', $stats);
        $this->assertArrayHasKey('showcase_exports', $stats);
        $this->assertArrayHasKey('latest_php_tests', $stats);
        $this->assertArrayHasKey('total_rules', $stats);
        $this->assertArrayHasKey('domain_count', $stats);
    }
}
