<?php

namespace Tests\Feature;

use App\Models\ShadowPromotionDecision;
use App\Models\User;
use App\Services\ShadowReadyPromotionDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERPRISE-047: Shadow Ready Promotion Decision
 *
 * Validates: decision threshold logic, 12-rule evaluation, summary counts,
 * advisory-only safety constants, persistence, route access, JSON API.
 */
class ShadowReadyPromotionDecisionTest extends TestCase
{
    use RefreshDatabase;

    private ShadowReadyPromotionDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ShadowReadyPromotionDecisionService::class);
    }

    // ── Safety constants ─────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(ShadowReadyPromotionDecisionService::ADVISORY_ONLY);
    }

    public function test_promotion_approved_constant_is_false(): void
    {
        $this->assertFalse(ShadowReadyPromotionDecisionService::PROMOTION_APPROVED);
    }

    public function test_promote_eligible_threshold_is_0_78(): void
    {
        $this->assertEqualsWithDelta(0.78, ShadowReadyPromotionDecisionService::PROMOTE_ELIGIBLE_THRESHOLD, 0.001);
    }

    public function test_keep_shadow_threshold_is_0_65(): void
    {
        $this->assertEqualsWithDelta(0.65, ShadowReadyPromotionDecisionService::KEEP_SHADOW_THRESHOLD, 0.001);
    }

    public function test_max_dlq_for_eligible_is_zero(): void
    {
        $this->assertSame(0, ShadowReadyPromotionDecisionService::MAX_DLQ_FOR_ELIGIBLE);
    }

    // ── Decision logic ────────────────────────────────────────────────────────

    public function test_high_confidence_zero_dlq_is_promote_eligible(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_PROMOTE_ELIGIBLE,
            $this->service->computeDecision(0.85, 0)
        );
    }

    public function test_threshold_exactly_078_zero_dlq_is_promote_eligible(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_PROMOTE_ELIGIBLE,
            $this->service->computeDecision(0.78, 0)
        );
    }

    public function test_high_confidence_with_dlq_errors_is_keep_shadow(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_KEEP_SHADOW,
            $this->service->computeDecision(0.85, 1)
        );
    }

    public function test_mid_confidence_zero_dlq_is_keep_shadow(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_KEEP_SHADOW,
            $this->service->computeDecision(0.70, 0)
        );
    }

    public function test_confidence_at_lower_threshold_is_keep_shadow(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_KEEP_SHADOW,
            $this->service->computeDecision(0.65, 0)
        );
    }

    public function test_confidence_below_threshold_is_defer(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_DEFER,
            $this->service->computeDecision(0.60, 0)
        );
    }

    public function test_zero_confidence_is_defer(): void
    {
        $this->assertSame(
            ShadowReadyPromotionDecisionService::DECISION_DEFER,
            $this->service->computeDecision(0.0, 0)
        );
    }

    // ── False positive risk ───────────────────────────────────────────────────

    public function test_fp_risk_low_at_0_78(): void
    {
        $this->assertSame('low', $this->service->computeFalsePositiveRisk(0.78));
    }

    public function test_fp_risk_medium_at_0_70(): void
    {
        $this->assertSame('medium', $this->service->computeFalsePositiveRisk(0.70));
    }

    public function test_fp_risk_high_at_0_60(): void
    {
        $this->assertSame('high', $this->service->computeFalsePositiveRisk(0.60));
    }

    // ── evaluate() dry-run ────────────────────────────────────────────────────

    public function test_dry_run_returns_12_results(): void
    {
        $results = $this->service->evaluate('', true);
        $this->assertCount(12, $results);
    }

    public function test_dry_run_does_not_persist_to_db(): void
    {
        $this->service->evaluate('', true);
        $this->assertDatabaseCount('shadow_promotion_decisions', 0);
    }

    public function test_dry_run_all_rows_have_promotion_approved_false(): void
    {
        $results = $this->service->evaluate('', true);
        $results->each(function (array $row): void {
            $this->assertFalse($row['promotion_approved'], "Rule {$row['rule_id']} has promotion_approved=true");
        });
    }

    public function test_dry_run_all_rows_have_is_advisory_true(): void
    {
        $results = $this->service->evaluate('', true);
        $results->each(function (array $row): void {
            $this->assertTrue($row['is_advisory'], "Rule {$row['rule_id']} has is_advisory=false");
        });
    }

    public function test_dry_run_summary_has_6_promote_eligible(): void
    {
        $results = $this->service->evaluate('', true);
        $summary = $this->service->getSummary($results);
        $this->assertSame(6, $summary['promote_eligible']);
    }

    public function test_dry_run_summary_has_6_keep_shadow(): void
    {
        $results = $this->service->evaluate('', true);
        $summary = $this->service->getSummary($results);
        $this->assertSame(6, $summary['keep_shadow']);
    }

    public function test_dry_run_summary_has_0_defer(): void
    {
        $results = $this->service->evaluate('', true);
        $summary = $this->service->getSummary($results);
        $this->assertSame(0, $summary['defer']);
    }

    public function test_summary_promotion_approved_is_false(): void
    {
        $results = $this->service->evaluate('', true);
        $summary = $this->service->getSummary($results);
        $this->assertFalse($summary['promotion_approved']);
    }

    public function test_summary_advisory_only_is_true(): void
    {
        $results = $this->service->evaluate('', true);
        $summary = $this->service->getSummary($results);
        $this->assertTrue($summary['advisory_only']);
    }

    public function test_cred_mfa_fatigue_is_promote_eligible(): void
    {
        $results = $this->service->evaluate('', true);
        $row = $results->firstWhere('rule_id', 'CRED_MFA_FATIGUE_PATTERN');
        $this->assertNotNull($row);
        $this->assertSame(ShadowReadyPromotionDecisionService::DECISION_PROMOTE_ELIGIBLE, $row['decision']);
    }

    public function test_ueba_unusual_login_time_is_keep_shadow(): void
    {
        $results = $this->service->evaluate('', true);
        $row = $results->firstWhere('rule_id', 'UEBA_UNUSUAL_LOGIN_TIME');
        $this->assertNotNull($row);
        $this->assertSame(ShadowReadyPromotionDecisionService::DECISION_KEEP_SHADOW, $row['decision']);
    }

    // ── evaluate() with persistence ───────────────────────────────────────────

    public function test_evaluate_persists_12_rows_to_db(): void
    {
        $this->service->evaluate('', false);
        $this->assertDatabaseCount('shadow_promotion_decisions', 12);
    }

    public function test_persisted_rows_all_have_promotion_approved_false(): void
    {
        $this->service->evaluate('', false);
        $trueCount = ShadowPromotionDecision::where('promotion_approved', true)->count();
        $this->assertSame(0, $trueCount);
    }

    public function test_domain_filter_returns_only_identity_rules(): void
    {
        $results = $this->service->evaluate('identity', true);
        $results->each(function (array $row): void {
            $this->assertSame('identity', $row['domain']);
        });
        $this->assertGreaterThan(0, $results->count());
    }

    // ── getLatestRunResults() ─────────────────────────────────────────────────

    public function test_get_latest_run_results_empty_when_no_runs(): void
    {
        $results = $this->service->getLatestRunResults();
        $this->assertEmpty($results);
    }

    public function test_get_latest_run_results_returns_latest_run(): void
    {
        $this->service->evaluate('', false);
        $results = $this->service->getLatestRunResults();
        $this->assertCount(12, $results);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/detection/shadow-promotion-decisions')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_shadow_promotion_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/shadow-promotion-decisions')
            ->assertStatus(200)
            ->assertSeeText('Shadow Promotion Decisions');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/shadow-promotion-decisions');

        $response->assertStatus(200)
            ->assertJsonPath('advisory_only', true)
            ->assertJsonPath('promotion_approved', false);
    }

    public function test_json_api_returns_thresholds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/shadow-promotion-decisions');

        $response->assertStatus(200)
            ->assertJsonPath('thresholds.promote_eligible', 0.78)
            ->assertJsonPath('thresholds.keep_shadow', 0.65);
    }
}
