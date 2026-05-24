<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetectionQualityScorecard extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'scorecard_id', 'scored_rule_id', 'precision_score', 'recall_score',
        'false_positive_rate', 'false_negative_rate', 'detection_coverage',
        'attack_coverage', 'evidence_completeness', 'confidence_stability',
        'alert_amplification_ratio', 'overall_quality_score', 'is_advisory',
        'score_components',
    ];

    protected $casts = [
        'is_advisory'      => 'boolean',
        'score_components' => 'array',
    ];
}
