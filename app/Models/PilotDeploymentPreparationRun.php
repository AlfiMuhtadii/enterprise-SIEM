<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotDeploymentPreparationRun extends Model
{
    protected $fillable = [
        'pilot_prep_run_id', 'preparation_scope', 'preparation_state',
        'pilot_endpoint_count', 'readiness_score', 'onboarding_ready',
        'ha_ready', 'rollback_ready', 'telemetry_continuous',
        'replay_safe', 'autonomous_rollout', 'is_advisory', 'preparation_evidence',
    ];

    protected $casts = [
        'pilot_endpoint_count' => 'integer',
        'readiness_score'      => 'float',
        'onboarding_ready'     => 'boolean',
        'ha_ready'             => 'boolean',
        'rollback_ready'       => 'boolean',
        'telemetry_continuous' => 'boolean',
        'replay_safe'          => 'boolean',
        'autonomous_rollout'   => 'boolean',
        'is_advisory'          => 'boolean',
        'preparation_evidence' => 'array',
    ];

    public const PREPARATION_SCOPES  = ['onboarding_readiness', 'deployment_check', 'continuity_verification', 'ha_checkpoint', 'rollback_readiness'];
    public const PREPARATION_STATES  = ['pending', 'running', 'passed', 'failed', 'blocked'];
}
