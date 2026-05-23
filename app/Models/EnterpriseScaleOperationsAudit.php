<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseScaleOperationsAudit extends Model
{
    protected $table = 'enterprise_scale_operations_audit';

    public const AUDIT_EVENT_TYPES = [
        'cluster_registered',
        'ha_validated',
        'telemetry_balanced',
        'failover_drilled',
        'cost_assessed',
        'scale_validated',
        'continuity_verified',
    ];

    public const ACTOR_TYPES = ['operator', 'system', 'analyst'];

    protected $fillable = [
        'scale_audit_id',
        'audit_event_type',
        'actor_type',
        'cluster_id',
        'autonomous_action_taken',
        'destructive_action_taken',
        'replay_safe',
        'audit_evidence',
    ];

    protected $casts = [
        'autonomous_action_taken'  => 'boolean',
        'destructive_action_taken' => 'boolean',
        'replay_safe'              => 'boolean',
        'audit_evidence'           => 'array',
    ];
}
