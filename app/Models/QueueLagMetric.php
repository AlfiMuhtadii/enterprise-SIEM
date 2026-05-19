<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueLagMetric extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TREND_INCREASING  = 'increasing';
    public const TREND_STABLE      = 'stable';
    public const TREND_DECREASING  = 'decreasing';

    // XDR platform consumer groups
    public const CONSUMER_GROUPS = [
        'normalizer-worker', 'correlation-worker',
        'alert-writer-service', 'incident-builder-service',
    ];

    public const LAG_WARNING_COUNT  = 1000;
    public const LAG_CRITICAL_COUNT = 10000;

    protected $fillable = [
        'consumer_group', 'topic', 'partition',
        'lag_count', 'lag_age_seconds', 'trend',
    ];

    public static function latestPerGroup(): \Illuminate\Support\Collection
    {
        return static::selectRaw('DISTINCT ON (consumer_group, topic) consumer_group, topic, lag_count, lag_age_seconds, trend, created_at')
            ->orderByRaw('consumer_group, topic, id DESC')
            ->get();
    }
}
