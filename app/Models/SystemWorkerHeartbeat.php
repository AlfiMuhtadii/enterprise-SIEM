<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemWorkerHeartbeat extends Model
{
    protected $fillable = [
        'worker_id', 'service_name', 'consumer_group', 'assigned_partitions',
        'processed_event_count', 'failed_event_count', 'retry_count', 'current_lag',
        'health_state', 'last_heartbeat_at', 'is_stale', 'is_stalled', 'metadata',
    ];

    protected $casts = [
        'assigned_partitions' => 'array',
        'processed_event_count'=> 'integer',
        'failed_event_count'   => 'integer',
        'retry_count'          => 'integer',
        'current_lag'          => 'integer',
        'is_stale'             => 'boolean',
        'is_stalled'           => 'boolean',
        'metadata'             => 'array',
        'last_heartbeat_at'    => 'datetime',
    ];

    public const HEALTH_HEALTHY      = 'healthy';
    public const HEALTH_DEGRADED     = 'degraded';
    public const HEALTH_LAGGING      = 'lagging';
    public const HEALTH_STALLED      = 'stalled';
    public const HEALTH_DISCONNECTED = 'disconnected';

    public const HEALTH_STATES = [
        self::HEALTH_HEALTHY,
        self::HEALTH_DEGRADED,
        self::HEALTH_LAGGING,
        self::HEALTH_STALLED,
        self::HEALTH_DISCONNECTED,
    ];

    // Staleness threshold in seconds
    public const STALE_THRESHOLD_SECONDS  = 60;
    public const STALLED_THRESHOLD_SECONDS= 300;
}
