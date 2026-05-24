<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StabilizationValidationRun extends Model
{
    protected $fillable = [
        'stabilization_run_id', 'validation_type', 'validation_state',
        'validation_score', 'tests_green', 'registry_valid',
        'contracts_valid', 'resilience_valid', 'fleet_sim_valid',
        'is_advisory', 'replay_safe', 'stabilization_evidence',
    ];

    protected $casts = [
        'validation_score'   => 'float',
        'tests_green'        => 'boolean',
        'registry_valid'     => 'boolean',
        'contracts_valid'    => 'boolean',
        'resilience_valid'   => 'boolean',
        'fleet_sim_valid'    => 'boolean',
        'is_advisory'        => 'boolean',
        'replay_safe'        => 'boolean',
        'stabilization_evidence' => 'array',
    ];

    public const VALIDATION_TYPES  = ['pre_freeze', 'post_freeze', 'pre_rc', 'post_rc', 'pilot_prep'];
    public const VALIDATION_STATES = ['pending', 'running', 'passed', 'failed'];
}
