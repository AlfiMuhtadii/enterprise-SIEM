<?php

namespace App\Services;

use App\Models\EntityBehaviorBaseline;

/**
 * CODE-STRUCT-DECOMPOSE: pure statistical math extracted from
 * UEBABaselineService — robust z-score, percentile rank, median/MAD, and
 * confidence derivation. Zero Eloquent/DB dependency (percentileRankFromBaseline
 * takes an already-loaded EntityBehaviorBaseline and only reads two of its
 * properties — no query), deterministic, replay-safe.
 */
class UEBAStatistics
{
    public static function robustZScore(float $value, ?float $median, ?float $mad): float
    {
        if ($median === null || $mad === null || $mad < 1e-10) {
            return 0.0;
        }
        return ($value - $median) / (1.4826 * $mad);
    }

    /**
     * Percentile rank of a value within a sorted array of reference values.
     * Returns 0–100.
     */
    public static function percentileRank(float $value, array $sortedValues): float
    {
        if (empty($sortedValues)) {
            return 50.0;
        }
        $below = count(array_filter($sortedValues, fn ($v) => $v < $value));
        return round(($below / count($sortedValues)) * 100.0, 2);
    }

    /**
     * Compute median absolute deviation (MAD) from a set of values.
     */
    public static function computeMAD(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $median = self::computeMedian($values);
        $deviations = array_map(fn ($v) => abs($v - $median), $values);
        return self::computeMedian($deviations);
    }

    /**
     * Compute median of an array of floats. Deterministic.
     */
    public static function computeMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return ($n % 2 === 0)
            ? (($values[$mid - 1] + $values[$mid]) / 2.0)
            : (float) $values[$mid];
    }

    /**
     * Compute all stats needed for a baseline from an array of float observations.
     */
    public static function computeStats(array $values): array
    {
        $n      = count($values);
        $sum    = array_sum($values);
        $mean   = $sum / $n;
        $median = self::computeMedian($values);
        $mad    = self::computeMAD($values);

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
        $stddev   = sqrt($variance);

        $sorted = $values;
        sort($sorted);
        $p10 = $sorted[(int) floor(0.10 * ($n - 1))];
        $p90 = $sorted[(int) floor(0.90 * ($n - 1))];

        return compact('mean', 'median', 'mad', 'stddev', 'p10', 'p90');
    }

    public static function percentileRankFromBaseline(float $value, EntityBehaviorBaseline $baseline): float
    {
        $p10 = $baseline->baseline_p10 ?? 0.0;
        $p90 = $baseline->baseline_p90 ?? 1.0;
        $range = max($p90 - $p10, 1e-10);
        $rank = (($value - $p10) / $range) * 80.0 + 10.0;
        return round(max(0.0, min(100.0, $rank)), 2);
    }

    public static function computeConfidence(float $zOrDeviation, int $sampleCount): float
    {
        $absZ = abs($zOrDeviation);
        if ($absZ < 2.0) {
            return 0.30;
        }
        if ($absZ < 2.5) {
            return 0.50;
        }
        if ($absZ < 3.0) {
            return 0.65;
        }
        if ($absZ < 4.0) {
            return 0.75;
        }
        $base = 0.85;
        // More samples → more confidence (capped at 0.97)
        $sampleBonus = min(0.12, ($sampleCount / 500) * 0.12);
        return min(0.97, round($base + $sampleBonus, 4));
    }
}
