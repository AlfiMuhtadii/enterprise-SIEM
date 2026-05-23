<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingCheckpointHistory extends Model
{
    protected $table = 'onboarding_checkpoint_history';

    public const CHECKPOINT_TYPES = [
        'pre_onboarding',
        'environment_validated',
        'schema_provisioned',
        'policy_installed',
        'integration_verified',
        'activation_complete',
    ];

    protected $fillable = [
        'onboarding_checkpoint_id',
        'onboarding_run_id',
        'checkpoint_type',
        'passed',
        'rollback_triggered',
        'replay_safe',
        'is_advisory',
        'checkpoint_details',
    ];

    protected $casts = [
        'passed'             => 'boolean',
        'rollback_triggered' => 'boolean',
        'replay_safe'        => 'boolean',
        'is_advisory'        => 'boolean',
        'checkpoint_details' => 'array',
    ];
}
