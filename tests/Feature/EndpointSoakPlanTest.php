<?php

namespace Tests\Feature;

use App\Models\EndpointSoakPlan;
use App\Models\User;
use App\Services\EndpointSoakPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-048: Endpoint Shadow Domain Soak Plan
 *
 * Validates: tier classification, 93-rule distribution, advisory constants,
 * gate evaluation, persistence, route access, JSON API.
 */
class EndpointSoakPlanTest extends TestCase
{
    use RefreshDatabase;

    private EndpointSoakPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EndpointSoakPlanService::class);
    }

    // ── Safety constants ─────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EndpointSoakPlanService::ADVISORY_ONLY);
    }

    public function test_plan_approved_constant_is_false(): void
    {
        $this->assertFalse(EndpointSoakPlanService::PLAN_APPROVED);
    }

    public function test_tier_1_threshold_is_0_72(): void
    {
        $this->assertEqualsWithDelta(0.72, EndpointSoakPlanService::TIER_1_THRESHOLD, 0.001);
    }

    public function test_tier_2_threshold_is_0_60(): void
    {
        $this->assertEqualsWithDelta(0.60, EndpointSoakPlanService::TIER_2_THRESHOLD, 0.001);
    }

    // ── Tier classification logic ─────────────────────────────────────────────

    public function test_high_confidence_is_tier_1_soak_ready(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_1_SOAK_READY,
            $this->service->classifyTier(['confidence' => 0.85])
        );
    }

    public function test_threshold_exactly_072_is_tier_1(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_1_SOAK_READY,
            $this->service->classifyTier(['confidence' => 0.72])
        );
    }

    public function test_mid_confidence_is_tier_2_evidence_collection(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_2_EVIDENCE_COLLECTION,
            $this->service->classifyTier(['confidence' => 0.65])
        );
    }

    public function test_threshold_exactly_060_is_tier_2(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_2_EVIDENCE_COLLECTION,
            $this->service->classifyTier(['confidence' => 0.60])
        );
    }

    public function test_low_confidence_is_tier_3_needs_tuning(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_3_NEEDS_TUNING,
            $this->service->classifyTier(['confidence' => 0.55])
        );
    }

    public function test_zero_confidence_is_tier_3(): void
    {
        $this->assertSame(
            EndpointSoakPlanService::TIER_3_NEEDS_TUNING,
            $this->service->classifyTier(['confidence' => 0.0])
        );
    }

    // ── generatePlan() dry-run ────────────────────────────────────────────────

    public function test_dry_run_returns_93_tiered_rules(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertCount(93, $result['tiered']);
    }

    public function test_dry_run_summary_has_80_tier_1(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertSame(80, $result['summary']['tier_1_count']);
    }

    public function test_dry_run_summary_has_13_tier_2(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertSame(13, $result['summary']['tier_2_count']);
    }

    public function test_dry_run_summary_has_0_tier_3(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertSame(0, $result['summary']['tier_3_count']);
    }

    public function test_dry_run_summary_plan_approved_is_false(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertFalse($result['summary']['plan_approved']);
    }

    public function test_dry_run_summary_is_advisory_is_true(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertTrue($result['summary']['is_advisory']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->generatePlan(true);
        $this->assertDatabaseCount('endpoint_soak_plans', 0);
    }

    public function test_dry_run_all_tier_1_rules_have_soak_window_1(): void
    {
        $result = $this->service->generatePlan(true);
        $t1 = $result['tiered']->where('tier', EndpointSoakPlanService::TIER_1_SOAK_READY);
        $t1->each(function (array $row): void {
            $this->assertSame(1, $row['estimated_soak_window']);
        });
    }

    public function test_dry_run_all_tier_2_rules_have_soak_window_2(): void
    {
        $result = $this->service->generatePlan(true);
        $t2 = $result['tiered']->where('tier', EndpointSoakPlanService::TIER_2_EVIDENCE_COLLECTION);
        $t2->each(function (array $row): void {
            $this->assertSame(2, $row['estimated_soak_window']);
        });
    }

    public function test_dry_run_all_rules_have_endpoint_domain(): void
    {
        $result = $this->service->generatePlan(true);
        $result['tiered']->each(function (array $row): void {
            $this->assertSame('endpoint', $row['domain']);
        });
    }

    public function test_dry_run_evaluates_5_gates(): void
    {
        $result = $this->service->generatePlan(true);
        $this->assertCount(5, $result['gates']);
    }

    public function test_dry_run_gates_all_have_is_advisory_true(): void
    {
        $result = $this->service->generatePlan(true);
        foreach ($result['gates'] as $gate) {
            $this->assertTrue($gate['is_advisory']);
        }
    }

    // ── generatePlan() with persistence ──────────────────────────────────────

    public function test_persist_creates_plan_row(): void
    {
        $this->service->generatePlan(false);
        $this->assertDatabaseCount('endpoint_soak_plans', 1);
    }

    public function test_persist_creates_93_rule_rows(): void
    {
        $this->service->generatePlan(false);
        $this->assertSame(93, DB::table('endpoint_soak_plan_rules')->count());
    }

    public function test_persist_creates_5_gate_rows(): void
    {
        $this->service->generatePlan(false);
        $this->assertSame(5, DB::table('endpoint_soak_plan_gates')->count());
    }

    public function test_persisted_plan_has_plan_approved_false(): void
    {
        $this->service->generatePlan(false);
        $plan = EndpointSoakPlan::first();
        $this->assertFalse((bool) $plan->plan_approved);
    }

    // ── getLatestPlan() ───────────────────────────────────────────────────────

    public function test_get_latest_plan_returns_null_when_no_plans(): void
    {
        $this->assertNull($this->service->getLatestPlan());
    }

    public function test_get_latest_plan_returns_plan_after_generation(): void
    {
        $this->service->generatePlan(false);
        $latest = $this->service->getLatestPlan();
        $this->assertNotNull($latest);
        $this->assertArrayHasKey('summary', $latest);
        $this->assertArrayHasKey('rules', $latest);
        $this->assertArrayHasKey('gates', $latest);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/detection/endpoint-soak-plan')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_endpoint_soak_plan_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/endpoint-soak-plan')
            ->assertStatus(200)
            ->assertSeeText('Endpoint Shadow Domain Soak Plan');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/endpoint-soak-plan')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true)
            ->assertJsonPath('plan_approved', false);
    }

    public function test_json_api_returns_thresholds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/endpoint-soak-plan')
            ->assertStatus(200)
            ->assertJsonPath('thresholds.tier_1', 0.72)
            ->assertJsonPath('thresholds.tier_2', 0.60);
    }
}
