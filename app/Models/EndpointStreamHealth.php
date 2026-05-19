<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndpointStreamHealth extends Model
{
    protected $table = 'endpoint_stream_health'; // non-standard plural

    public const UPDATED_AT = null; // append-only

    public const STATE_HEALTHY  = 'healthy';
    public const STATE_LAGGING  = 'lagging';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_OFFLINE  = 'offline';

    public const STATES = [
        self::STATE_HEALTHY,
        self::STATE_LAGGING,
        self::STATE_DEGRADED,
        self::STATE_OFFLINE,
    ];

    // Thresholds for health state classification
    public const LAG_LAGGING_SECONDS  = 30;
    public const LAG_DEGRADED_SECONDS = 120;
    public const DROP_LAGGING_COUNT   = 5;
    public const DROP_DEGRADED_COUNT  = 20;

    protected $fillable = [
        'agent_id', 'stream_health_state', 'last_sequence_seen',
        'stream_lag_seconds', 'dropped_event_count', 'batch_count', 'trace_id',
    ];

    public static function latestForAgent(string $agentId): ?self
    {
        return static::where('agent_id', $agentId)
            ->orderByDesc('id')
            ->first();
    }

    public static function classifyState(int $lagSeconds, int $droppedCount): string
    {
        if ($lagSeconds >= self::LAG_DEGRADED_SECONDS || $droppedCount >= self::DROP_DEGRADED_COUNT) {
            return self::STATE_DEGRADED;
        }
        if ($lagSeconds >= self::LAG_LAGGING_SECONDS || $droppedCount >= self::DROP_LAGGING_COUNT) {
            return self::STATE_LAGGING;
        }
        return self::STATE_HEALTHY;
    }
}
