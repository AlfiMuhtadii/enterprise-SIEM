<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XdrMaturityScorecard extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'scorecard_id', 'detection_quality_score', 'telemetry_quality_score',
        'fp_fn_health_score', 'triage_realism_score', 'performance_health_score',
        'noise_resilience_score', 'attack_coverage_score', 'overall_maturity_score',
        'maturity_tier', 'is_advisory', 'component_scores',
    ];

    protected $casts = [
        'is_advisory'       => 'boolean',
        'component_scores'  => 'array',
    ];

    const MATURITY_TIERS = ['initial', 'developing', 'defined', 'managed', 'optimizing'];

    const TIER_THRESHOLDS = [
        'optimizing' => 0.90,
        'managed'    => 0.75,
        'defined'    => 0.60,
        'developing' => 0.40,
        'initial'    => 0.0,
    ];
}
