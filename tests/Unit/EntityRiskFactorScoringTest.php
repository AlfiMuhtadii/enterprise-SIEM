<?php

namespace Tests\Unit;

use App\Services\EntityRiskFactorScoring;
use PHPUnit\Framework\TestCase;

/**
 * CODE-STRUCT-DECOMPOSE: EntityRiskFactorScoring is the pure risk-scoring
 * math extracted from EntityRiskScoringService (WEIGHTS/LEVEL_THRESHOLDS/
 * MAX_SCORE, scoreToLevel, aggregateScore, makeFactor). This had zero
 * isolated unit test coverage before extraction — only reachable indirectly
 * through the full DB-backed EntityRiskScoringTest suite.
 *
 * Plain PHPUnit\Framework\TestCase — no Laravel bootstrap, no DB — matching
 * the TotpServiceTest/ThreatHuntQueryAllowlistTest/ReportRendererTest/
 * UEBAStatisticsTest precedent for pure services in this codebase.
 */
class EntityRiskFactorScoringTest extends TestCase
{
    public function test_score_to_level_boundaries(): void
    {
        $this->assertSame('critical', EntityRiskFactorScoring::scoreToLevel(7.5));
        $this->assertSame('critical', EntityRiskFactorScoring::scoreToLevel(10.0));
        $this->assertSame('high', EntityRiskFactorScoring::scoreToLevel(5.0));
        $this->assertSame('high', EntityRiskFactorScoring::scoreToLevel(7.4));
        $this->assertSame('medium', EntityRiskFactorScoring::scoreToLevel(2.5));
        $this->assertSame('medium', EntityRiskFactorScoring::scoreToLevel(4.9));
        $this->assertSame('low', EntityRiskFactorScoring::scoreToLevel(0.0));
        $this->assertSame('low', EntityRiskFactorScoring::scoreToLevel(2.4));
    }

    public function test_aggregate_score_sums_contributions(): void
    {
        $factors = [
            ['contribution' => 1.5],
            ['contribution' => 2.0],
            ['contribution' => 0.5],
        ];
        $this->assertSame(4.0, EntityRiskFactorScoring::aggregateScore($factors));
    }

    public function test_aggregate_score_caps_at_max_score(): void
    {
        $factors = [
            ['contribution' => 8.0],
            ['contribution' => 8.0],
        ];
        $this->assertSame(EntityRiskFactorScoring::MAX_SCORE, EntityRiskFactorScoring::aggregateScore($factors));
    }

    public function test_aggregate_score_treats_missing_contribution_as_zero(): void
    {
        $factors = [['factor' => 'no_contribution_key']];
        $this->assertSame(0.0, EntityRiskFactorScoring::aggregateScore($factors));
    }

    public function test_aggregate_score_empty_array_is_zero(): void
    {
        $this->assertSame(0.0, EntityRiskFactorScoring::aggregateScore([]));
    }

    public function test_make_factor_applies_known_weight(): void
    {
        $factor = EntityRiskFactorScoring::makeFactor('critical_alerts', 2);
        $this->assertSame(3.0, $factor['weight']);
        $this->assertSame(6.0, $factor['contribution']);
    }

    public function test_make_factor_unknown_name_defaults_to_weight_one(): void
    {
        $factor = EntityRiskFactorScoring::makeFactor('not_a_real_factor', 3);
        $this->assertSame(1.0, $factor['weight']);
        $this->assertSame(3.0, $factor['contribution']);
    }

    public function test_make_factor_contribution_is_capped_at_max_score(): void
    {
        $factor = EntityRiskFactorScoring::makeFactor('c2_indicator', 100);
        $this->assertSame(EntityRiskFactorScoring::MAX_SCORE, $factor['contribution']);
    }

    public function test_make_factor_omits_optional_fields_when_empty(): void
    {
        $factor = EntityRiskFactorScoring::makeFactor('critical_alerts', 1);
        $this->assertArrayNotHasKey('alert_ids', $factor);
        $this->assertArrayNotHasKey('trace_ids', $factor);
        $this->assertArrayNotHasKey('incident_ids', $factor);
        $this->assertArrayNotHasKey('advisory_only', $factor);
    }

    public function test_make_factor_includes_optional_fields_when_provided(): void
    {
        $factor = EntityRiskFactorScoring::makeFactor(
            'incident_involvement', 1, [10, 11], ['t-1'], [99], true
        );
        $this->assertSame([10, 11], $factor['alert_ids']);
        $this->assertSame(['t-1'], $factor['trace_ids']);
        $this->assertSame([99], $factor['incident_ids']);
        $this->assertTrue($factor['advisory_only']);
    }

    public function test_make_factor_supports_negative_weight_factors(): void
    {
        // response_execution_mitigation is intentionally negative — mitigation lowers risk.
        $factor = EntityRiskFactorScoring::makeFactor('response_execution_mitigation', 1);
        $this->assertSame(-2.0, $factor['weight']);
        $this->assertLessThan(0, $factor['contribution']);
    }

    public function test_weights_and_level_thresholds_are_non_empty(): void
    {
        $this->assertNotEmpty(EntityRiskFactorScoring::WEIGHTS);
        $this->assertCount(4, EntityRiskFactorScoring::LEVEL_THRESHOLDS);
    }
}
