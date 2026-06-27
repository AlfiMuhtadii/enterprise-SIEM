<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Phase1SoakExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-061: Real Soak Phase 1 Execution
 *
 * Validates: safety constants, 8-gate structure, computeDecision logic,
 * dry-run vs live-run, persistence, getLatestRun, routes, JSON API.
 */
class Phase1SoakExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Phase1SoakExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(Phase1SoakExecutionService::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(Phase1SoakExecutionService::ADVISORY_ONLY);
    }

    public function test_no_promotion_constant_is_true(): void
    {
        $this->assertTrue(Phase1SoakExecutionService::NO_PROMOTION);
    }

    public function test_scope_is_staged_active_empirical(): void
    {
        $this->assertSame('staged_active_empirical', Phase1SoakExecutionService::SCOPE);
    }

    public function test_duration_min_is_30(): void
    {
        $this->assertSame(30, Phase1SoakExecutionService::DURATION_MIN);
    }

    public function test_duration_max_is_60(): void
    {
        $this->assertSame(60, Phase1SoakExecutionService::DURATION_MAX);
    }

    public function test_gates_total_is_8(): void
    {
        $this->assertSame(8, Phase1SoakExecutionService::GATES_TOTAL);
    }

    // ── Gate structure ────────────────────────────────────────────────────────

    public function test_build_run_dry_run_returns_plan_gates_metrics(): void
    {
        $result = $this->service->buildRun(true);
        $this->assertArrayHasKey('plan', $result);
        $this->assertArrayHasKey('gates', $result);
        $this->assertArrayHasKey('metrics', $result);
    }

    public function test_dry_run_returns_8_gates(): void
    {
        $result = $this->service->buildRun(true);
        $this->assertCount(8, $result['gates']);
    }

    public function test_gate_ids_are_p1g_01_through_p1g_08(): void
    {
        $result  = $this->service->buildRun(true);
        $gateIds = array_column($result['gates'], 'gate_id');
        for ($i = 1; $i <= 8; $i++) {
            $expected = sprintf('P1G-0%d', $i);
            $this->assertContains($expected, $gateIds, "Gate {$expected} must be present");
        }
    }

    public function test_all_gates_have_is_advisory_field(): void
    {
        $result = $this->service->buildRun(true);
        foreach ($result['gates'] as $gate) {
            $this->assertArrayHasKey('is_advisory', $gate, "Gate {$gate['gate_id']} must have is_advisory");
        }
    }

    public function test_get_gate_definitions_returns_8_entries(): void
    {
        $defs = $this->service->getGateDefinitions();
        $this->assertCount(8, $defs);
    }

    // ── computeDecision logic ─────────────────────────────────────────────────

    public function test_compute_decision_returns_pass_when_all_gates_pass(): void
    {
        $gates = array_fill(0, 8, ['status' => 'pass', 'passed' => true]);
        $this->assertSame('PASS', $this->service->computeDecision($gates));
    }

    public function test_compute_decision_returns_warn_when_any_gate_warns(): void
    {
        $gates = array_merge(
            array_fill(0, 7, ['status' => 'pass', 'passed' => true]),
            [['status' => 'warn', 'passed' => false]]
        );
        $this->assertSame('WARN', $this->service->computeDecision($gates));
    }

    public function test_compute_decision_returns_fail_when_any_gate_fails(): void
    {
        $gates = array_merge(
            array_fill(0, 7, ['status' => 'pass', 'passed' => true]),
            [['status' => 'fail', 'passed' => false]]
        );
        $this->assertSame('FAIL', $this->service->computeDecision($gates));
    }

    public function test_fail_takes_precedence_over_warn(): void
    {
        $gates = [
            ['status' => 'pass',  'passed' => true],
            ['status' => 'warn',  'passed' => false],
            ['status' => 'fail',  'passed' => false],
        ];
        $this->assertSame('FAIL', $this->service->computeDecision($gates));
    }

    public function test_dry_run_decision_is_never_pass(): void
    {
        // P1G-07 and P1G-08 are always advisory warn — no dry-run can be PASS
        $result = $this->service->buildRun(true);
        $this->assertNotSame('PASS', $result['plan']['decision']);
    }

    // ── Safety properties ─────────────────────────────────────────────────────

    public function test_plan_always_has_no_promotion_true(): void
    {
        $result = $this->service->buildRun(true);
        $this->assertTrue((bool) $result['plan']['no_promotion']);
    }

    public function test_plan_always_has_is_advisory_true(): void
    {
        $result = $this->service->buildRun(true);
        $this->assertTrue((bool) $result['plan']['is_advisory']);
    }

    public function test_plan_has_is_dry_run_flag(): void
    {
        $dryResult  = $this->service->buildRun(true);
        $this->assertTrue((bool) $dryResult['plan']['is_dry_run']);
    }

    public function test_persisted_run_has_no_promotion_true(): void
    {
        $this->service->buildRun(false);
        $this->assertDatabaseHas('phase1_soak_runs', ['no_promotion' => true]);
    }

    // ── Dry-run does not persist ──────────────────────────────────────────────

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->buildRun(true);
        $this->assertDatabaseCount('phase1_soak_runs', 0);
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function test_build_run_persists_run_row(): void
    {
        $this->service->buildRun(false);
        $this->assertDatabaseCount('phase1_soak_runs', 1);
    }

    public function test_build_run_persists_8_gate_rows(): void
    {
        $this->service->buildRun(false);
        $this->assertDatabaseCount('phase1_soak_gate_results', 8);
    }

    public function test_build_run_persists_metrics(): void
    {
        $this->service->buildRun(false);
        $this->assertGreaterThanOrEqual(5, DB::table('phase1_soak_metrics')->count());
    }

    public function test_build_run_persists_audit_event(): void
    {
        $this->service->buildRun(false);
        $this->assertDatabaseCount('phase1_soak_audit_events', 1);
    }

    public function test_persisted_run_has_correct_scope(): void
    {
        $this->service->buildRun(false);
        $this->assertDatabaseHas('phase1_soak_runs', ['scope' => Phase1SoakExecutionService::SCOPE]);
    }

    public function test_persisted_gates_have_is_advisory_column(): void
    {
        $this->service->buildRun(false);
        // All gate rows must have is_advisory column set (not null)
        $nullCount = DB::table('phase1_soak_gate_results')->whereNull('is_advisory')->count();
        $this->assertSame(0, $nullCount);
    }

    // ── getLatestRun ─────────────────────────────────────────────────────────

    public function test_get_latest_run_returns_null_before_any_run(): void
    {
        $this->assertNull($this->service->getLatestRun());
    }

    public function test_get_latest_run_returns_data_after_run(): void
    {
        $this->service->buildRun(false);
        $latest = $this->service->getLatestRun();
        $this->assertNotNull($latest);
    }

    public function test_get_latest_run_has_plan_gates_metrics(): void
    {
        $this->service->buildRun(false);
        $latest = $this->service->getLatestRun();
        $this->assertArrayHasKey('plan', $latest);
        $this->assertArrayHasKey('gates', $latest);
        $this->assertArrayHasKey('metrics', $latest);
    }

    public function test_get_latest_run_gates_count_is_8(): void
    {
        $this->service->buildRun(false);
        $latest = $this->service->getLatestRun();
        $this->assertCount(8, $latest['gates']);
    }

    // ── Duration clamping ─────────────────────────────────────────────────────

    public function test_duration_below_min_is_clamped_to_30(): void
    {
        $result = $this->service->buildRun(true, 5);
        $this->assertSame(Phase1SoakExecutionService::DURATION_MIN, (int) $result['plan']['duration_minutes']);
    }

    public function test_duration_above_max_is_clamped_to_60(): void
    {
        $result = $this->service->buildRun(true, 120);
        $this->assertSame(Phase1SoakExecutionService::DURATION_MAX, (int) $result['plan']['duration_minutes']);
    }

    public function test_duration_30_is_accepted(): void
    {
        $result = $this->service->buildRun(true, 30);
        $this->assertSame(30, (int) $result['plan']['duration_minutes']);
    }

    public function test_duration_60_is_accepted(): void
    {
        $result = $this->service->buildRun(true, 60);
        $this->assertSame(60, (int) $result['plan']['duration_minutes']);
    }

    // ── Structural gates ──────────────────────────────────────────────────────

    public function test_p1g01_checks_registry_rule_count(): void
    {
        $result  = $this->service->buildRun(true);
        $gate    = array_values(array_filter($result['gates'], fn ($g) => $g['gate_id'] === 'P1G-01'))[0] ?? null;
        $this->assertNotNull($gate);
        $this->assertStringContainsString('registry', $gate['evidence']);
        $this->assertStringContainsString('staged_active', $gate['evidence']);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/phase1-soak');
        $response->assertRedirect();
    }

    public function test_route_accessible_to_admin(): void
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/phase1-soak');
        $response->assertStatus(200);
    }

    public function test_json_api_returns_advisory_only_and_no_promotion(): void
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/phase1-soak');
        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
        $response->assertJsonPath('no_promotion', true);
        $response->assertJsonPath('scope', Phase1SoakExecutionService::SCOPE);
        $response->assertJsonPath('gates_total', 8);
    }
}
