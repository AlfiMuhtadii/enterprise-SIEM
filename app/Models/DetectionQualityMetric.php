<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Current aggregated quality metrics for a detection rule.
 * Mutable — recomputed whenever evidence changes.
 * Complements detection_quality_history (which stores historical snapshots).
 */
class DetectionQualityMetric extends Model
{
    protected $fillable = [
        'rule_id', 'quality_score', 'replay_pass_count', 'replay_fail_count',
        'fp_report_count', 'suppression_count', 'version_count',
        'fp_rate_7d', 'fp_rate_30d', 'quality_trend', 'computed_at',
    ];

    protected $casts = [
        'quality_score'  => 'float',
        'fp_rate_7d'     => 'float',
        'fp_rate_30d'    => 'float',
        'computed_at'    => 'datetime',
    ];

    public const TREND_IMPROVING  = 'improving';
    public const TREND_STABLE     = 'stable';
    public const TREND_DEGRADING  = 'degrading';
}
