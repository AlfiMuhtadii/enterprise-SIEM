<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoLiveValidationRun extends Model
{
    protected $fillable = [
        'go_live_run_id', 'validation_scope', 'validation_state',
        'validation_score', 'soak_requirement_met', 'gate_requirement_met',
        'risk_acceptable', 'rollback_ready', 'autonomous_go_live',
        'replay_safe', 'certification_id', 'validation_evidence',
    ];

    protected $casts = [
        'validation_score'      => 'float',
        'soak_requirement_met'  => 'boolean',
        'gate_requirement_met'  => 'boolean',
        'risk_acceptable'       => 'boolean',
        'rollback_ready'        => 'boolean',
        'autonomous_go_live'    => 'boolean',
        'replay_safe'           => 'boolean',
        'validation_evidence'   => 'array',
    ];

    public const VALIDATION_SCOPES = [
        'pre_cutover', 'post_cutover', 'rollback_verification', 'domain_specific',
    ];

    public const VALIDATION_STATES = ['pending', 'running', 'passed', 'failed', 'aborted'];
}
