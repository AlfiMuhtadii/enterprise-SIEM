<?php

namespace Tests\Feature;

use App\Models\RuleEvidenceBatchPlan;
use App\Models\RuleFixtureBacklog;
use App\Models\User;
use App\Services\RuleEvidenceGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-050: Empirical Rule Evidence & Replay Fixture Plan
 *
 * Validates: tier classification, confidence_source derivation,
 * inventory (133 rows), batch plan, advisory safety, routes.
 */
class RuleEvidenceGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private RuleEvidenceGovernanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RuleEvidenceGovernanceService::class);
    }

    // ── Safety constants ───────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(RuleEvidenceGovernanceService::ADVISORY_ONLY);
    }

    public function test_plan_approved_constant_is_false(): void
    {
        $this->assertFalse(RuleEvidenceGovernanceService::PLAN_APPROVED);
    }

    public function test_tier_constants_exist(): void
    {
        $this->assertSame('tier_1_immediate',  RuleEvidenceGovernanceService::TIER_1);
        $this->assertSame('tier_2_next_batch', RuleEvidenceGovernanceService::TIER_2);
        $this->assertSame('tier_3_deferred',   RuleEvidenceGovernanceService::TIER_3);
    }

    // ── classifyTier ──────────────────────────────────────────────────────────

    public function test_staged_active_always_tier_1(): void
    {
        $rule = ['rule_id' => 'IDENTITY_MFA_FAILURE_BURST', 'status' => 'staged_active', 'domain' => 'identity', 'confidence' => 0.71];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_1, $this->service->classifyTier($rule));
    }

    public function test_shadow_soaked_domain_is_tier_2(): void
    {
        $rule = ['status' => 'shadow', 'domain' => 'identity', 'confidence' => 0.50];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_2, $this->service->classifyTier($rule));
    }

    public function test_shadow_high_confidence_is_tier_2(): void
    {
        $rule = ['status' => 'shadow', 'domain' => 'endpoint', 'confidence' => 0.80];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_2, $this->service->classifyTier($rule));
    }

    public function test_shadow_low_confidence_non_soaked_is_tier_3(): void
    {
        $rule = ['status' => 'shadow', 'domain' => 'endpoint', 'confidence' => 0.60];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_3, $this->service->classifyTier($rule));
    }

    public function test_shadow_network_low_conf_is_tier_3(): void
    {
        $rule = ['status' => 'shadow', 'domain' => 'network', 'confidence' => 0.65];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_3, $this->service->classifyTier($rule));
    }

    public function test_tier_2_boundary_at_0_72(): void
    {
        $below = ['status' => 'shadow', 'domain' => 'endpoint', 'confidence' => 0.719];
        $at    = ['status' => 'shadow', 'domain' => 'endpoint', 'confidence' => 0.720];
        $this->assertSame(RuleEvidenceGovernanceService::TIER_3, $this->service->classifyTier($below));
        $this->assertSame(RuleEvidenceGovernanceService::TIER_2, $this->service->classifyTier($at));
    }

    // ── deriveConfidenceSource ────────────────────────────────────────────────

    public function test_both_fixture_and_evidence_is_empirical(): void
    {
        $rule = ['replay_fixture' => 'tests/fixtures/foo.json', 'validation_evidence' => 'tested'];
        $this->assertSame('empirical', $this->service->deriveConfidenceSource($rule));
    }

    public function test_fixture_only_is_fixture_tested(): void
    {
        $rule = ['replay_fixture' => 'tests/fixtures/foo.json', 'validation_evidence' => null];
        $this->assertSame('fixture_tested', $this->service->deriveConfidenceSource($rule));
    }

    public function test_evidence_only_is_manual(): void
    {
        $rule = ['replay_fixture' => null, 'validation_evidence' => 'validated'];
        $this->assertSame('manual', $this->service->deriveConfidenceSource($rule));
    }

    public function test_neither_is_manual(): void
    {
        $rule = ['replay_fixture' => null, 'validation_evidence' => null];
        $this->assertSame('manual', $this->service->deriveConfidenceSource($rule));
    }

    // ── inventoryRules dry-run ────────────────────────────────────────────────

    public function test_dry_run_returns_133_rows(): void
    {
        $results = $this->service->inventoryRules(true);
        $this->assertCount(133, $results);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->inventoryRules(true);
        $this->assertDatabaseCount('rule_fixture_backlogs', 0);
    }

    public function test_dry_run_has_12_tier_1_rules(): void
    {
        $results = $this->service->inventoryRules(true);
        $tier1 = $results->where('priority_tier', RuleEvidenceGovernanceService::TIER_1);
        $this->assertCount(12, $tier1);
    }

    public function test_dry_run_all_tier_1_are_staged_active(): void
    {
        $results = $this->service->inventoryRules(true);
        $tier1 = $results->where('priority_tier', RuleEvidenceGovernanceService::TIER_1);
        foreach ($tier1 as $r) {
            $this->assertSame('staged_active', $r['status']);
        }
    }

    public function test_dry_run_113_rules_missing_fixture(): void
    {
        $results = $this->service->inventoryRules(true);
        $missing = $results->where('has_replay_fixture', false);
        $this->assertCount(113, $missing);
    }

    public function test_dry_run_all_advisory_true(): void
    {
        $results = $this->service->inventoryRules(true);
        foreach ($results as $r) {
            $this->assertTrue($r['is_advisory']);
        }
    }

    // ── inventoryRules with persistence ───────────────────────────────────────

    public function test_persist_creates_133_backlog_rows(): void
    {
        $this->service->inventoryRules(false);
        $this->assertDatabaseCount('rule_fixture_backlogs', 133);
    }

    public function test_persist_upsert_does_not_duplicate(): void
    {
        $this->service->inventoryRules(false);
        $this->service->inventoryRules(false);
        $this->assertDatabaseCount('rule_fixture_backlogs', 133);
    }

    // ── generateBatchPlan ─────────────────────────────────────────────────────

    public function test_batch_plan_summary_has_133_total_rules(): void
    {
        $plan = $this->service->generateBatchPlan(true);
        $this->assertSame(133, $plan['summary']['total_rules']);
    }

    public function test_batch_plan_summary_has_12_tier_1(): void
    {
        $plan = $this->service->generateBatchPlan(true);
        $this->assertSame(12, $plan['summary']['tier_1_count']);
    }

    public function test_batch_plan_summary_plan_approved_false(): void
    {
        $plan = $this->service->generateBatchPlan(true);
        $this->assertFalse($plan['summary']['plan_approved']);
    }

    public function test_batch_plan_dry_run_does_not_persist(): void
    {
        $this->service->generateBatchPlan(true);
        $this->assertDatabaseCount('rule_evidence_batch_plans', 0);
    }

    public function test_batch_plan_persist_creates_batch_rows(): void
    {
        $plan = $this->service->generateBatchPlan(false);
        $count = DB::table('rule_evidence_batch_plans')->count();
        $this->assertGreaterThan(0, $count);
        $this->assertCount($count, $plan['batches']);
    }

    public function test_batch_plan_batches_have_required_keys(): void
    {
        $plan = $this->service->generateBatchPlan(true);
        $batch = $plan['batches'][0];
        $this->assertArrayHasKey('domain', $batch);
        $this->assertArrayHasKey('priority_tier', $batch);
        $this->assertArrayHasKey('rules_count', $batch);
        $this->assertArrayHasKey('missing_fixture_count', $batch);
        $this->assertArrayHasKey('estimated_effort_days', $batch);
        $this->assertArrayHasKey('is_advisory', $batch);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/detection/rule-evidence-governance')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_governance_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/rule-evidence-governance')
            ->assertStatus(200)
            ->assertSeeText('Rule Evidence');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/rule-evidence-governance')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true)
            ->assertJsonPath('plan_approved', false);
    }
}
