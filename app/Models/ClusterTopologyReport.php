<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClusterTopologyReport extends Model
{
    public const CLUSTER_ROLES = ['primary', 'secondary', 'arbiter', 'observer'];

    public const TOPOLOGY_STATES = ['healthy', 'degraded', 'partitioned', 'rejoining'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'topology_report_id',
        'cluster_id',
        'cluster_role',
        'topology_state',
        'node_count',
        'replica_count',
        'replication_lag_ms',
        'quorum_achieved',
        'replay_safe',
        'is_advisory',
        'topology_evidence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'replication_lag_ms' => 'float',
        'quorum_achieved'    => 'boolean',
        'replay_safe'        => 'boolean',
        'is_advisory'        => 'boolean',
        'topology_evidence'  => 'array',
    ];
}
