<?php

namespace Tests\Unit;

use App\Models\EntityBehaviorBaseline;
use App\Services\UEBAStatistics;
use PHPUnit\Framework\TestCase;

/**
 * CODE-STRUCT-DECOMPOSE: UEBAStatistics is the pure statistical math
 * extracted from UEBABaselineService (robustZScore/percentileRank/
 * computeMAD/computeMedian were already covered indirectly via
 * UEBABaselineAnalyticsTest through the DB-backed service; computeStats/
 * percentileRankFromBaseline/computeConfidence had ZERO isolated test
 * coverage before this extraction — only reachable through the full
 * DB-backed scoring path).
 *
 * Plain PHPUnit\Framework\TestCase — no Laravel bootstrap, no DB — matching
 * the TotpServiceTest/ThreatHuntQueryAllowlistTest/ReportRendererTest/
 * TraceparentServiceTest precedent for pure services in this codebase.
 */
class UEBAStatisticsTest extends TestCase
{
    public function test_robust_z_score_with_known_values(): void
    {
        // (9 - 5) / (1.4826 * 2) = 1.3489...
        $z = UEBAStatistics::robustZScore(9.0, 5.0, 2.0);
        $this->assertEqualsWithDelta(1.3489, $z, 0.001);
    }

    public function test_robust_z_score_returns_zero_when_mad_is_near_zero(): void
    {
        $this->assertSame(0.0, UEBAStatistics::robustZScore(99.0, 5.0, 0.0));
    }

    public function test_robust_z_score_returns_zero_when_median_is_null(): void
    {
        $this->assertSame(0.0, UEBAStatistics::robustZScore(5.0, null, 2.0));
    }

    public function test_percentile_rank_for_max_value_is_high(): void
    {
        $rank = UEBAStatistics::percentileRank(5.0, [1.0, 2.0, 3.0, 4.0]);
        $this->assertSame(100.0, $rank);
    }

    public function test_percentile_rank_for_min_value_is_zero(): void
    {
        $rank = UEBAStatistics::percentileRank(1.0, [1.0, 2.0, 3.0, 4.0]);
        $this->assertSame(0.0, $rank);
    }

    public function test_percentile_rank_returns_fifty_for_empty_values(): void
    {
        $this->assertSame(50.0, UEBAStatistics::percentileRank(5.0, []));
    }

    public function test_compute_median_odd_count(): void
    {
        $this->assertSame(3.0, UEBAStatistics::computeMedian([5.0, 1.0, 3.0, 2.0, 4.0]));
    }

    public function test_compute_median_even_count_averages_middle_two(): void
    {
        $this->assertSame(2.5, UEBAStatistics::computeMedian([1.0, 2.0, 3.0, 4.0]));
    }

    public function test_compute_median_empty_returns_zero(): void
    {
        $this->assertSame(0.0, UEBAStatistics::computeMedian([]));
    }

    public function test_compute_mad_with_known_values(): void
    {
        // median([1,1,2,2,4,6,9]) = 2; deviations = [1,1,0,0,2,4,7]; median(deviations) = 1
        $mad = UEBAStatistics::computeMAD([1.0, 1.0, 2.0, 2.0, 4.0, 6.0, 9.0]);
        $this->assertSame(1.0, $mad);
    }

    public function test_compute_mad_returns_zero_for_empty_array(): void
    {
        $this->assertSame(0.0, UEBAStatistics::computeMAD([]));
    }

    public function test_compute_stats_returns_all_expected_keys(): void
    {
        $stats = UEBAStatistics::computeStats([1.0, 2.0, 3.0, 4.0, 5.0]);
        foreach (['mean', 'median', 'mad', 'stddev', 'p10', 'p90'] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
        $this->assertSame(3.0, $stats['mean']);
        $this->assertSame(3.0, $stats['median']);
    }

    public function test_percentile_rank_from_baseline_midpoint(): void
    {
        $baseline = new EntityBehaviorBaseline;
        $baseline->baseline_p10 = 0.0;
        $baseline->baseline_p90 = 10.0;

        // value exactly at p10 → 10.0; value exactly at p90 → 90.0
        $this->assertSame(10.0, UEBAStatistics::percentileRankFromBaseline(0.0, $baseline));
        $this->assertSame(90.0, UEBAStatistics::percentileRankFromBaseline(10.0, $baseline));
    }

    public function test_percentile_rank_from_baseline_clamps_to_zero_and_hundred(): void
    {
        $baseline = new EntityBehaviorBaseline;
        $baseline->baseline_p10 = 0.0;
        $baseline->baseline_p90 = 10.0;

        $this->assertSame(0.0, UEBAStatistics::percentileRankFromBaseline(-100.0, $baseline));
        $this->assertSame(100.0, UEBAStatistics::percentileRankFromBaseline(100.0, $baseline));
    }

    public function test_percentile_rank_from_baseline_handles_zero_range(): void
    {
        $baseline = new EntityBehaviorBaseline;
        $baseline->baseline_p10 = 5.0;
        $baseline->baseline_p90 = 5.0;

        // range floored at 1e-10 — must not divide by zero / return NAN or INF
        $rank = UEBAStatistics::percentileRankFromBaseline(5.0, $baseline);
        $this->assertIsFloat($rank);
        $this->assertFalse(is_nan($rank));
        $this->assertFalse(is_infinite($rank));
    }

    public function test_compute_confidence_low_z_score_is_low_confidence(): void
    {
        $this->assertSame(0.30, UEBAStatistics::computeConfidence(1.0, 100));
    }

    public function test_compute_confidence_increases_with_z_score(): void
    {
        $this->assertSame(0.50, UEBAStatistics::computeConfidence(2.2, 10));
        $this->assertSame(0.65, UEBAStatistics::computeConfidence(2.7, 10));
        $this->assertSame(0.75, UEBAStatistics::computeConfidence(3.5, 10));
    }

    public function test_compute_confidence_high_z_score_scales_with_sample_count(): void
    {
        $low = UEBAStatistics::computeConfidence(5.0, 0);
        $high = UEBAStatistics::computeConfidence(5.0, 500);
        $this->assertSame(0.85, $low);
        $this->assertSame(0.97, $high);
        $this->assertGreaterThan($low, $high);
    }

    public function test_compute_confidence_never_exceeds_cap(): void
    {
        $this->assertLessThanOrEqual(0.97, UEBAStatistics::computeConfidence(50.0, 100000));
    }

    public function test_compute_confidence_uses_absolute_value(): void
    {
        $this->assertSame(
            UEBAStatistics::computeConfidence(5.0, 100),
            UEBAStatistics::computeConfidence(-5.0, 100)
        );
    }
}
