<?php

namespace Tests\Feature;

use App\Services\DetectionPromotionReadinessService;
use App\Services\EndpointSoakPlanService;
use App\Services\ShadowReadyPromotionDecisionService;
use App\Services\StabilityEvidenceFreezeV2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERPRISE-051: Hardcoded Threshold Externalization
 *
 * Verifies: config file exists, services read from config with
 * constants as fallback, phase summaries dynamic, backward compat.
 */
class DetectionConfigExternalizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Config file ───────────────────────────────────────────────────────────

    public function test_xdr_detection_config_file_exists(): void
    {
        $this->assertFileExists(config_path('xdr_detection.php'));
    }

    public function test_config_has_soaked_domains(): void
    {
        $domains = config('xdr_detection.soaked_domains');
        $this->assertIsArray($domains);
        $this->assertContains('identity', $domains);
        $this->assertContains('cloud', $domains);
        $this->assertContains('saas', $domains);
    }

    public function test_config_has_promotion_thresholds(): void
    {
        $this->assertEqualsWithDelta(0.78, config('xdr_detection.promotion.promote_eligible_threshold'), 0.001);
        $this->assertEqualsWithDelta(0.65, config('xdr_detection.promotion.keep_shadow_threshold'), 0.001);
        $this->assertSame(0, config('xdr_detection.promotion.max_dlq_for_eligible'));
    }

    public function test_config_has_soak_thresholds(): void
    {
        $this->assertEqualsWithDelta(0.72, config('xdr_detection.soak.tier_1_threshold'), 0.001);
        $this->assertEqualsWithDelta(0.60, config('xdr_detection.soak.tier_2_threshold'), 0.001);
    }

    public function test_config_has_freeze_threshold(): void
    {
        $this->assertEqualsWithDelta(0.80, config('xdr_detection.freeze.stable_score_threshold'), 0.001);
    }

    public function test_config_has_confidence_sources(): void
    {
        $sources = config('xdr_detection.confidence_sources');
        $this->assertContains('manual', $sources);
        $this->assertContains('empirical', $sources);
    }

    // ── Backward compat: constants unchanged ──────────────────────────────────

    public function test_spd_constants_unchanged(): void
    {
        $this->assertEqualsWithDelta(0.78, ShadowReadyPromotionDecisionService::PROMOTE_ELIGIBLE_THRESHOLD, 0.001);
        $this->assertEqualsWithDelta(0.65, ShadowReadyPromotionDecisionService::KEEP_SHADOW_THRESHOLD, 0.001);
        $this->assertSame(0, ShadowReadyPromotionDecisionService::MAX_DLQ_FOR_ELIGIBLE);
    }

    public function test_esp_constants_unchanged(): void
    {
        $this->assertEqualsWithDelta(0.72, EndpointSoakPlanService::TIER_1_THRESHOLD, 0.001);
        $this->assertEqualsWithDelta(0.60, EndpointSoakPlanService::TIER_2_THRESHOLD, 0.001);
    }

    // ── Config override works ─────────────────────────────────────────────────

    public function test_spd_uses_config_promote_threshold(): void
    {
        config(['xdr_detection.promotion.promote_eligible_threshold' => 0.90]);
        $service = app(ShadowReadyPromotionDecisionService::class);

        // Rule with confidence 0.85: normally promote_eligible (>=0.78), now keep_shadow (< 0.90)
        $this->assertSame('keep_shadow', $service->computeDecision(0.85, 0));

        config(['xdr_detection.promotion.promote_eligible_threshold' => 0.78]); // restore
    }

    public function test_spd_uses_config_keep_shadow_threshold(): void
    {
        config(['xdr_detection.promotion.keep_shadow_threshold' => 0.70]);
        $service = app(ShadowReadyPromotionDecisionService::class);

        // Rule with confidence 0.67: normally keep_shadow (>=0.65), now defer (< 0.70)
        $this->assertSame('defer', $service->computeDecision(0.67, 0));

        config(['xdr_detection.promotion.keep_shadow_threshold' => 0.65]); // restore
    }

    public function test_esp_classifies_by_config_tier1_threshold(): void
    {
        config(['xdr_detection.soak.tier_1_threshold' => 0.80]);
        $service = app(EndpointSoakPlanService::class);

        // Rule at 0.75: normally tier_1 (>=0.72), now tier_2 (< 0.80)
        $rule = ['confidence' => 0.75, 'domain' => 'endpoint', 'status' => 'shadow'];
        $this->assertSame('tier_2_evidence_collection', $service->classifyTier($rule));

        config(['xdr_detection.soak.tier_1_threshold' => 0.72]); // restore
    }

    // ── DPR config-aware soaked domains ──────────────────────────────────────

    public function test_dpr_classifies_identity_as_shadow_ready(): void
    {
        $service = app(DetectionPromotionReadinessService::class);
        $rule    = ['rule_id' => 'TEST', 'status' => 'shadow', 'domain' => 'identity', 'confidence' => 0.70];
        $this->assertSame(DetectionPromotionReadinessService::READINESS_SHADOW_READY, $service->classifyRule($rule));
    }

    public function test_dpr_config_override_soaked_domains(): void
    {
        config(['xdr_detection.soaked_domains' => ['identity', 'cloud', 'saas', 'endpoint']]);
        $service = app(DetectionPromotionReadinessService::class);

        $rule = ['rule_id' => 'TEST', 'status' => 'shadow', 'domain' => 'endpoint', 'confidence' => 0.80];
        $this->assertSame(DetectionPromotionReadinessService::READINESS_SHADOW_READY, $service->classifyRule($rule));

        config(['xdr_detection.soaked_domains' => ['identity', 'cloud', 'saas']]); // restore
    }

    // ── Stability freeze dynamic phase queries ────────────────────────────────

    public function test_stability_freeze_e047_phase_has_promotion_approved_false(): void
    {
        $service = app(StabilityEvidenceFreezeV2Service::class);
        $result  = $service->freeze(true);

        $e047 = collect($result['phases'])->firstWhere('enterprise_id', 'E047');
        $this->assertNotNull($e047);
        $metrics = is_string($e047['metrics']) ? json_decode($e047['metrics'], true) : $e047['metrics'];
        $this->assertFalse((bool) ($metrics['promotion_approved'] ?? true));
    }

    public function test_stability_freeze_e048_phase_has_tier_counts(): void
    {
        $service = app(StabilityEvidenceFreezeV2Service::class);
        $result  = $service->freeze(true);

        $e048 = collect($result['phases'])->firstWhere('enterprise_id', 'E048');
        $this->assertNotNull($e048);
        $metrics = is_string($e048['metrics']) ? json_decode($e048['metrics'], true) : $e048['metrics'];
        $this->assertArrayHasKey('tier_1_soak_ready', $metrics);
        $this->assertArrayHasKey('tier_2_evidence', $metrics);
    }
}
