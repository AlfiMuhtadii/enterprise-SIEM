<?php

namespace Tests\Feature;

use App\Models\SoakPlanRun;
use App\Models\User;
use App\Services\RealDomainSoakPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-060: Real Domain Soak Execution Plan
 *
 * Validates: safety constants, 4-phase structure, 16-gate coverage,
 * phase definitions, dry-run, persistence, getLatestPlan, routes, JSON API.
 */
class RealDomainSoakPlanTest extends TestCase
{
    use RefreshDatabase;

    private RealDomainSoakPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RealDomainSoakPlanService::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(RealDomainSoakPlanService::ADVISORY_ONLY);
    }

    public function test_real_execution_gated_constant_is_true(): void
    {
        $this->assertTrue(RealDomainSoakPlanService::REAL_EXECUTION_GATED);
    }

    public function test_phases_total_is_4(): void
    {
        $this->assertSame(4, RealDomainSoakPlanService::PHASES_TOTAL);
    }

    // ── Phase definitions ─────────────────────────────────────────────────────

    public function test_get_phase_definitions_returns_4_entries(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertCount(4, $defs);
    }

    public function test_phase_definitions_have_keys_1_to_4(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertArrayHasKey(1, $defs);
        $this->assertArrayHasKey(2, $defs);
        $this->assertArrayHasKey(3, $defs);
        $this->assertArrayHasKey(4, $defs);
    }

    public function test_phase_1_name_mentions_staged_active(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertStringContainsStringIgnoringCase('staged', $defs[1]['name']);
    }

    public function test_phase_2_name_mentions_shadow(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertStringContainsStringIgnoringCase('shadow', $defs[2]['name']);
    }

    public function test_phase_3_name_mentions_fixture(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertStringContainsStringIgnoringCase('fixture', $defs[3]['name']);
    }

    public function test_phase_4_name_mentions_endpoint(): void
    {
        $defs = $this->service->getPhaseDefinitions();
        $this->assertStringContainsStringIgnoringCase('endpoint', $defs[4]['name']);
    }

    // ── buildPlan() dry-run — structure ──────────────────────────────────────

    public function test_dry_run_returns_plan_phases_gates_notes(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertArrayHasKey('plan', $result);
        $this->assertArrayHasKey('phases', $result);
        $this->assertArrayHasKey('gates', $result);
        $this->assertArrayHasKey('notes', $result);
    }

    public function test_dry_run_returns_4_phases(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertCount(4, $result['phases']);
    }

    public function test_dry_run_returns_16_gates(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertCount(16, $result['gates']);
    }

    public function test_dry_run_plan_has_real_execution_gated_true(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertTrue((bool) $result['plan']['real_execution_gated']);
    }

    public function test_dry_run_plan_has_is_advisory_true(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertTrue((bool) $result['plan']['is_advisory']);
    }

    public function test_dry_run_plan_has_phases_total_4(): void
    {
        $result = $this->service->buildPlan(true);
        $this->assertSame(4, (int) $result['plan']['phases_total']);
    }

    // ── Gates — IDs and structure ─────────────────────────────────────────────

    public function test_gate_ids_cover_all_phases(): void
    {
        $result  = $this->service->buildPlan(true);
        $gateIds = array_column($result['gates'], 'gate_id');

        for ($phase = 1; $phase <= 4; $phase++) {
            for ($gate = 1; $gate <= 4; $gate++) {
                $expected = sprintf('SPG-P%d-0%d', $phase, $gate);
                $this->assertContains($expected, $gateIds, "Gate {$expected} missing");
            }
        }
    }

    public function test_all_gates_have_is_advisory_true(): void
    {
        $result = $this->service->buildPlan(true);
        foreach ($result['gates'] as $gate) {
            $this->assertTrue((bool) $gate['is_advisory'], "Gate {$gate['gate_id']} missing is_advisory=true");
        }
    }

    public function test_spg_p2_04_always_passes_safety_constant(): void
    {
        $result = $this->service->buildPlan(true);
        $gate   = array_values(array_filter($result['gates'], fn ($g) => $g['gate_id'] === 'SPG-P2-04'))[0] ?? null;
        $this->assertNotNull($gate, 'SPG-P2-04 must exist');
        $this->assertSame('pass', $gate['status'], 'SPG-P2-04 must pass — PROMOTION_RECOMMENDED=false is hardcoded');
    }

    public function test_spg_p3_04_passes_because_service_exists(): void
    {
        $result = $this->service->buildPlan(true);
        $gate   = array_values(array_filter($result['gates'], fn ($g) => $g['gate_id'] === 'SPG-P3-04'))[0] ?? null;
        $this->assertNotNull($gate, 'SPG-P3-04 must exist');
        $this->assertSame('pass', $gate['status'], 'DetectionReplayFixtureService exists — should pass');
    }

    // ── Phases — structure ────────────────────────────────────────────────────

    public function test_all_phases_have_promotion_gated_true(): void
    {
        $result = $this->service->buildPlan(true);
        foreach ($result['phases'] as $phase) {
            $this->assertTrue((bool) $phase['promotion_gated'], "Phase {$phase['phase_number']} must have promotion_gated=true");
        }
    }

    public function test_each_phase_has_4_gates(): void
    {
        $result = $this->service->buildPlan(true);
        for ($phase = 1; $phase <= 4; $phase++) {
            $phaseGates = array_filter($result['gates'], fn ($g) => $g['phase_number'] === $phase);
            $this->assertCount(4, $phaseGates, "Phase {$phase} must have exactly 4 gates");
        }
    }

    public function test_notes_include_soak_command_per_phase(): void
    {
        $result = $this->service->buildPlan(true);
        for ($phase = 1; $phase <= 4; $phase++) {
            $cmdNotes = array_filter($result['notes'], fn ($n) => $n['phase_number'] === $phase && $n['note_type'] === 'soak_command');
            $this->assertNotEmpty($cmdNotes, "Phase {$phase} must have a soak_command note");
        }
    }

    // ── dry-run does not persist ──────────────────────────────────────────────

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->buildPlan(true);
        $this->assertDatabaseCount('soak_plan_runs', 0);
    }

    // ── persistence ───────────────────────────────────────────────────────────

    public function test_build_plan_persists_run_row(): void
    {
        $this->service->buildPlan(false);
        $this->assertDatabaseCount('soak_plan_runs', 1);
    }

    public function test_build_plan_persists_4_phase_rows(): void
    {
        $this->service->buildPlan(false);
        $this->assertDatabaseCount('soak_plan_phases', 4);
    }

    public function test_build_plan_persists_16_gate_rows(): void
    {
        $this->service->buildPlan(false);
        $this->assertDatabaseCount('soak_plan_gates', 16);
    }

    public function test_build_plan_persists_notes(): void
    {
        $this->service->buildPlan(false);
        $count = DB::table('soak_plan_readiness_notes')->count();
        $this->assertGreaterThanOrEqual(8, $count);   // 2 notes per phase minimum
    }

    public function test_build_plan_persists_audit_event(): void
    {
        $this->service->buildPlan(false);
        $this->assertDatabaseCount('soak_plan_audit_events', 1);
    }

    public function test_persisted_run_has_real_execution_gated_true(): void
    {
        $this->service->buildPlan(false);
        $this->assertDatabaseHas('soak_plan_runs', ['real_execution_gated' => true]);
    }

    public function test_persisted_phases_all_have_promotion_gated_true(): void
    {
        $this->service->buildPlan(false);
        $count = DB::table('soak_plan_phases')->where('promotion_gated', false)->count();
        $this->assertSame(0, $count, 'No phase should have promotion_gated=false');
    }

    // ── getLatestPlan ─────────────────────────────────────────────────────────

    public function test_get_latest_plan_returns_null_before_any_run(): void
    {
        $this->assertNull($this->service->getLatestPlan());
    }

    public function test_get_latest_plan_returns_data_after_run(): void
    {
        $this->service->buildPlan(false);
        $latest = $this->service->getLatestPlan();
        $this->assertNotNull($latest);
        $this->assertArrayHasKey('plan', $latest);
        $this->assertArrayHasKey('phases', $latest);
        $this->assertArrayHasKey('gates', $latest);
        $this->assertArrayHasKey('notes', $latest);
    }

    public function test_get_latest_plan_phases_in_order(): void
    {
        $this->service->buildPlan(false);
        $latest      = $this->service->getLatestPlan();
        $phaseNums   = array_column($latest['phases'], 'phase_number');
        $this->assertSame([1, 2, 3, 4], array_map('intval', $phaseNums));
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/soak-execution-plan');
        $response->assertRedirect();
    }

    public function test_route_accessible_to_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/soak-execution-plan');
        $response->assertStatus(200);
    }

    public function test_json_api_returns_advisory_only_and_real_execution_gated(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/soak-execution-plan');
        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
        $response->assertJsonPath('real_execution_gated', true);
        $response->assertJsonPath('phases_total', 4);
    }
}
