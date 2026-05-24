<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalValidationBaseline extends Model
{
    protected $fillable = [
        'baseline_id', 'validation_domain', 'validation_state',
        'baseline_score', 'previous_score', 'drift_delta',
        'within_bounds', 'replay_safe', 'degradation_detected',
        'is_advisory', 'baseline_evidence',
    ];

    protected $casts = [
        'baseline_score'        => 'float',
        'previous_score'        => 'float',
        'drift_delta'           => 'float',
        'within_bounds'         => 'boolean',
        'replay_safe'           => 'boolean',
        'degradation_detected'  => 'boolean',
        'is_advisory'           => 'boolean',
        'baseline_evidence'     => 'array',
    ];

    public const VALIDATION_DOMAINS = [
        'replay_continuity', 'queue_durability', 'telemetry_continuity',
        'ha_continuity', 'deployment_reproducibility',
    ];

    public const VALIDATION_STATES = ['passing', 'degraded', 'failing', 'recovering'];
}
