<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HaValidationRun extends Model
{
    public const HA_CHECK_TYPES = [
        'quorum',
        'replica_continuity',
        'replay_continuity',
        'failover_readiness',
        'replication_lag',
    ];

    public const HA_STATES = ['passing', 'degraded', 'failed', 'recovering'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'ha_run_id',
        'cluster_id',
        'ha_check_type',
        'ha_state',
        'ha_score',
        'replication_lag_ms',
        'quorum_valid',
        'replica_continuous',
        'replay_continuous',
        'failover_ready',
        'is_advisory',
        'ha_evidence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'ha_score'           => 'float',
        'replication_lag_ms' => 'float',
        'quorum_valid'       => 'boolean',
        'replica_continuous' => 'boolean',
        'replay_continuous'  => 'boolean',
        'failover_ready'     => 'boolean',
        'is_advisory'        => 'boolean',
        'ha_evidence'        => 'array',
    ];
}
