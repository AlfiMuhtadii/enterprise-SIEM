<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentReadinessReport extends Model
{
    protected $fillable = [
        'readiness_report_id',
        'tenant_id',
        'environment',
        'readiness_score',
        'onboarding_complete',
        'telemetry_healthy',
        'environment_valid',
        'deployment_ready',
        'replay_safe',
        'is_advisory',
        'readiness_evidence',
    ];

    protected $casts = [
        'readiness_score'     => 'float',
        'onboarding_complete' => 'boolean',
        'telemetry_healthy'   => 'boolean',
        'environment_valid'   => 'boolean',
        'deployment_ready'    => 'boolean',
        'replay_safe'         => 'boolean',
        'is_advisory'         => 'boolean',
        'readiness_evidence'  => 'array',
    ];
}
