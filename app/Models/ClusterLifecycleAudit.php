<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClusterLifecycleAudit extends Model
{
    protected $table = 'cluster_lifecycle_audit';

    public const LIFECYCLE_EVENTS = [
        'registered',
        'validated',
        'topology_updated',
        'degraded',
        'restored',
        'decommissioned',
    ];

    protected $fillable = [
        'cluster_audit_id',
        'cluster_id',
        'lifecycle_event',
        'previous_state',
        'current_state',
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
