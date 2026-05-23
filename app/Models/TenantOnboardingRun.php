<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantOnboardingRun extends Model
{
    public const ONBOARDING_PHASES = [
        'environment_validation',
        'schema_provisioning',
        'policy_install',
        'integration_check',
        'activation',
    ];

    public const ONBOARDING_STATES = [
        'initiated',
        'validating',
        'checkpoint_passed',
        'completed',
        'failed',
        'rolled_back',
    ];

    protected $fillable = [
        'onboarding_run_id',
        'tenant_id',
        'onboarding_phase',
        'onboarding_state',
        'endpoint_count',
        'readiness_score',
        'validation_passed',
        'rollback_capable',
        'replay_safe',
        'is_advisory',
        'onboarding_evidence',
    ];

    protected $casts = [
        'readiness_score'     => 'float',
        'validation_passed'   => 'boolean',
        'rollback_capable'    => 'boolean',
        'replay_safe'         => 'boolean',
        'is_advisory'         => 'boolean',
        'onboarding_evidence' => 'array',
    ];
}
