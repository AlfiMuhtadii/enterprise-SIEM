<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailoverCoordinationHistory extends Model
{
    protected $table = 'failover_coordination_history';

    public const FAILOVER_TYPES = [
        'drill',
        'simulation',
        'dependency_sequenced',
        'replay_verified',
    ];

    public const FAILOVER_STATES = [
        'planned',
        'sequencing',
        'validating',
        'completed',
        'rolled_back',
        'aborted',
    ];

    protected $fillable = [
        'failover_coordination_id',
        'cluster_id',
        'failover_type',
        'failover_state',
        'duration_s',
        'dependency_ordered',
        'replay_verified',
        'rollback_ready',
        'uncontrolled_failover',
        'is_advisory',
        'failover_evidence',
    ];

    protected $casts = [
        'duration_s'            => 'float',
        'dependency_ordered'    => 'boolean',
        'replay_verified'       => 'boolean',
        'rollback_ready'        => 'boolean',
        'uncontrolled_failover' => 'boolean',
        'is_advisory'           => 'boolean',
        'failover_evidence'     => 'array',
    ];
}
