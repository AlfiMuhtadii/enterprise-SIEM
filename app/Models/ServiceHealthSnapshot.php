<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceHealthSnapshot extends Model
{
    public const UPDATED_AT = null; // append-only

    public const STATE_HEALTHY  = 'healthy';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_DOWN     = 'down';
    public const STATE_UNKNOWN  = 'unknown';

    public const STATES = [
        self::STATE_HEALTHY,
        self::STATE_DEGRADED,
        self::STATE_DOWN,
        self::STATE_UNKNOWN,
    ];

    // Platform services tracked
    public const SERVICES = [
        'laravel-soc', 'ingestion-gateway', 'normalizer-worker',
        'correlation-worker', 'alert-writer-service', 'incident-builder-service',
        'ai-rag-service', 'endpoint-agent',
        'postgresql', 'redpanda', 'opensearch', 'clickhouse', 'qdrant', 'grafana',
    ];

    protected $fillable = [
        'service_name', 'health_state', 'response_time_ms',
        'error_message', 'dependencies_ok', 'details', 'trace_id',
    ];

    protected $casts = [
        'dependencies_ok' => 'boolean',
        'details'         => 'array',
    ];

    public static function latestForService(string $service): ?self
    {
        return static::where('service_name', $service)->orderByDesc('id')->first();
    }
}
