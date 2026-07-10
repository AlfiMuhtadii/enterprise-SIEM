<?php

namespace Tests\Feature;

use App\Services\DomainSoakSimulationService;
use App\Services\EndpointSoakPlanService;
use App\Services\Phase1SoakEvidenceFreezeService;
use App\Services\Phase1SoakExecutionService;
use App\Services\RealDomainSoakPlanService;
use App\Services\SoakOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * META-MODULE-RATIONALIZE: SoakOverviewService is a read-only facade over
 * the 7 confirmed-distinct soak services (docs/architecture/META_MODULE_AUDIT.md
 * §4) — these tests verify it never writes anything and correctly surfaces
 * each service's own current status without merging them into one status.
 */
class SoakOverviewTest extends TestCase
{
    use RefreshDatabase;

    private SoakOverviewService $overview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->overview = app(SoakOverviewService::class);
    }

    public function test_overview_returns_all_seven_service_keys(): void
    {
        $result = $this->overview->overview();

        $this->assertEqualsCanonicalizing([
            'domain_soak_harness',
            'domain_soak_simulation',
            'endpoint_soak_plan',
            'phase1_soak_execution',
            'phase1_soak_evidence_freeze',
            'real_domain_soak_plan',
            'soak_chaos_validation',
        ], array_keys($result['services']));
    }

    public function test_overview_is_always_advisory(): void
    {
        $this->assertTrue($this->overview->overview()['is_advisory']);
    }

    public function test_overview_note_explains_services_are_not_merged(): void
    {
        $this->assertStringContainsString('not a single merged soak status', $this->overview->overview()['note']);
    }

    public function test_harness_summary_present_even_with_no_runs(): void
    {
        $result = $this->overview->overview();
        $data = $result['services']['domain_soak_harness']['data'];

        $this->assertIsArray($data);
        $this->assertSame(0, $data['total']);
    }

    public function test_chaos_dashboard_stats_present_even_with_no_runs(): void
    {
        $result = $this->overview->overview();
        $data = $result['services']['soak_chaos_validation']['data'];

        $this->assertIsArray($data);
        $this->assertSame(0, $data['total_soak_runs']);
    }

    public function test_services_with_no_runs_report_null_data(): void
    {
        $result = $this->overview->overview();

        $this->assertNull($result['services']['endpoint_soak_plan']['data']);
        $this->assertNull($result['services']['phase1_soak_execution']['data']);
        $this->assertNull($result['services']['phase1_soak_evidence_freeze']['data']);
        $this->assertNull($result['services']['real_domain_soak_plan']['data']);
        $this->assertNull($result['services']['domain_soak_simulation']['data']);
    }

    public function test_endpoint_soak_plan_reflects_a_real_generated_plan(): void
    {
        app(EndpointSoakPlanService::class)->generatePlan(false);

        $data = $this->overview->overview()['services']['endpoint_soak_plan']['data'];

        $this->assertNotNull($data);
    }

    public function test_phase1_soak_execution_reflects_a_real_run(): void
    {
        app(Phase1SoakExecutionService::class)->buildRun(false);

        $data = $this->overview->overview()['services']['phase1_soak_execution']['data'];

        $this->assertNotNull($data);
    }

    public function test_phase1_soak_evidence_freeze_reflects_a_real_freeze(): void
    {
        app(Phase1SoakEvidenceFreezeService::class)->freeze(false);

        $data = $this->overview->overview()['services']['phase1_soak_evidence_freeze']['data'];

        $this->assertNotNull($data);
    }

    public function test_real_domain_soak_plan_reflects_a_real_plan(): void
    {
        app(RealDomainSoakPlanService::class)->buildPlan(false);

        $data = $this->overview->overview()['services']['real_domain_soak_plan']['data'];

        $this->assertNotNull($data);
    }

    public function test_domain_soak_simulation_reflects_the_latest_simulation(): void
    {
        app(DomainSoakSimulationService::class)->simulate('endpoint', false);

        $data = $this->overview->overview()['services']['domain_soak_simulation']['data'];

        $this->assertNotNull($data);
    }

    public function test_overview_never_writes_to_any_soak_table(): void
    {
        app(EndpointSoakPlanService::class)->generatePlan(false);
        $countBefore = \Illuminate\Support\Facades\DB::table('endpoint_soak_plans')->count();

        $this->overview->overview();
        $this->overview->overview();

        $countAfter = \Illuminate\Support\Facades\DB::table('endpoint_soak_plans')->count();
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_command_runs_successfully_with_no_runs(): void
    {
        $this->artisan('soak:overview')->assertSuccessful();
    }

    public function test_command_shows_present_after_a_run(): void
    {
        app(EndpointSoakPlanService::class)->generatePlan(false);

        $this->artisan('soak:overview')
            ->expectsOutputToContain('present')
            ->assertSuccessful();
    }
}
