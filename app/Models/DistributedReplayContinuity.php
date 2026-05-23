<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistributedReplayContinuity extends Model
{
    protected $table = 'distributed_replay_continuity';

    public const CONTINUITY_STATES = ['continuous', 'gap_detected', 'recovering', 'restored'];

    protected $fillable = [
        'replay_continuity_id',
        'cluster_id',
        'continuity_state',
        'replay_coverage_pct',
        'gap_count',
        'amplification_ratio',
        'cross_cluster_continuous',
        'durability_verified',
        'replay_safe',
        'is_advisory',
        'continuity_evidence',
    ];

    protected $casts = [
        'replay_coverage_pct'      => 'float',
        'amplification_ratio'      => 'float',
        'cross_cluster_continuous' => 'boolean',
        'durability_verified'      => 'boolean',
        'replay_safe'              => 'boolean',
        'is_advisory'              => 'boolean',
        'continuity_evidence'      => 'array',
    ];
}
