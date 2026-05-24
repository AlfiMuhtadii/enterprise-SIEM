<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentAcceptanceAudit extends Model
{
    protected $table = 'deployment_acceptance_audit';

    protected $fillable = [
        'acceptance_audit_id', 'audit_event_type', 'actor_type',
        'target_entity', 'self_approve_blocked', 'autonomous_action_taken',
        'destructive_action_taken', 'replay_safe', 'audit_evidence',
    ];

    protected $casts = [
        'self_approve_blocked'     => 'boolean',
        'autonomous_action_taken'  => 'boolean',
        'destructive_action_taken' => 'boolean',
        'replay_safe'              => 'boolean',
        'audit_evidence'           => 'array',
    ];

    public const AUDIT_EVENT_TYPES = [
        'gate_evaluated', 'risk_registered', 'limitation_recorded',
        'report_generated', 'certification_issued', 'go_live_validated',
    ];

    public const ACTOR_TYPES = ['operator', 'analyst', 'system'];
}
