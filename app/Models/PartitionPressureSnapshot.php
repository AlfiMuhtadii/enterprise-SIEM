<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mutable — current per-partition pressure state (upserted on each heartbeat).
 * NOT append-only: updated in-place to reflect current partition health.
 */
class PartitionPressureSnapshot extends Model
{
    protected $fillable = [
        'partition_key', 'topic', 'partition_id', 'offset_lag', 'utilization_pct',
        'throughput_eps', 'is_leader', 'health_state', 'metadata',
    ];

    protected $casts = [
        'partition_id'     => 'integer',
        'offset_lag'       => 'integer',
        'utilization_pct'  => 'float',
        'throughput_eps'   => 'float',
        'is_leader'        => 'boolean',
        'metadata'         => 'array',
    ];

    public const HEALTH_HEALTHY  = 'healthy';
    public const HEALTH_LAGGING  = 'lagging';
    public const HEALTH_PRESSURE = 'pressure';
    public const HEALTH_STALLED  = 'stalled';

    public const HEALTH_STATES = [
        self::HEALTH_HEALTHY,
        self::HEALTH_LAGGING,
        self::HEALTH_PRESSURE,
        self::HEALTH_STALLED,
    ];
}
