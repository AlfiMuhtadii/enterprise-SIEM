<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClusterTopologyReport extends Model
{
    public const CLUSTER_ROLES = ['primary', 'secondary', 'arbiter', 'observer'];

    public const TOPOLOGY_STATES = ['healthy', 'degraded', 'partitioned', 'rejoining'];

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
    ];

    protected $casts = [
        'replication_lag_ms' => 'float',
        'quorum_achieved'    => 'boolean',
        'replay_safe'        => 'boolean',
        'is_advisory'        => 'boolean',
        'topology_evidence'  => 'array',
    ];
}
