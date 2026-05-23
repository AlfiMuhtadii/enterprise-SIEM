<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScaleProfileValidationRun extends Model
{
    public const SCALE_PROFILES = ['small_50', 'medium_250', 'large_500'];

    protected $fillable = [
        'scale_run_id',
        'scale_profile',
        'endpoint_count',
        'telemetry_continuity_pct',
        'replay_durability_pct',
        'analyst_workload_score',
        'queue_recovery_score',
        'operational_score',
        'scale_passed',
        'bounded_scope',
        'replay_safe',
        'is_advisory',
        'scale_evidence',
    ];

    protected $casts = [
        'telemetry_continuity_pct' => 'float',
        'replay_durability_pct'    => 'float',
        'analyst_workload_score'   => 'float',
        'queue_recovery_score'     => 'float',
        'operational_score'        => 'float',
        'scale_passed'             => 'boolean',
        'bounded_scope'            => 'boolean',
        'replay_safe'              => 'boolean',
        'is_advisory'              => 'boolean',
        'scale_evidence'           => 'array',
    ];
}
