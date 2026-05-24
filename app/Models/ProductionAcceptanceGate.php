<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionAcceptanceGate extends Model
{
    protected $fillable = [
        'gate_run_id', 'gate_name', 'gate_state', 'gate_score',
        'gate_passed', 'self_approve_blocked', 'autonomous_waiver',
        'replay_safe', 'certification_id', 'gate_evidence',
    ];

    protected $casts = [
        'gate_score'          => 'float',
        'gate_passed'         => 'boolean',
        'self_approve_blocked'=> 'boolean',
        'autonomous_waiver'   => 'boolean',
        'replay_safe'         => 'boolean',
        'gate_evidence'       => 'array',
    ];

    public const GATE_NAMES = [
        'soak_validation', 'replay_integrity', 'contract_compliance',
        'rule_registry', 'fleet_simulation',
    ];

    public const GATE_STATES = ['pending', 'running', 'passed', 'failed', 'waived'];
}
