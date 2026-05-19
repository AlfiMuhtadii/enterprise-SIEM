<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamPressureMetric extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_THROUGHPUT      = 'throughput';
    public const TYPE_BACKLOG         = 'backlog';
    public const TYPE_SATURATION      = 'saturation';
    public const TYPE_REPLAY_PRESSURE = 'replay_pressure';
    public const TYPE_INGESTION_RATE  = 'ingestion_rate';
    public const TYPE_STREAM_LAG      = 'stream_lag';

    public const STATE_NORMAL   = 'normal';
    public const STATE_WARNING  = 'warning';
    public const STATE_CRITICAL = 'critical';

    protected $fillable = [
        'metric_type', 'service_name', 'value', 'unit',
        'threshold_warning', 'threshold_critical', 'state', 'trace_id',
    ];

    protected $casts = [
        'value'              => 'float',
        'threshold_warning'  => 'float',
        'threshold_critical' => 'float',
    ];

    public static function classifyState(float $value, ?float $warnThreshold, ?float $critThreshold): string
    {
        if ($critThreshold !== null && $value >= $critThreshold) {
            return self::STATE_CRITICAL;
        }
        if ($warnThreshold !== null && $value >= $warnThreshold) {
            return self::STATE_WARNING;
        }
        return self::STATE_NORMAL;
    }

    public static function latestPerType(): \Illuminate\Support\Collection
    {
        return static::selectRaw('DISTINCT ON (metric_type, service_name) metric_type, service_name, value, unit, state, created_at')
            ->orderByRaw('metric_type, service_name, id DESC')
            ->get();
    }
}
