<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueryPerformanceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'backend', 'p50_latency_ms', 'p95_latency_ms', 'p99_latency_ms',
        'slow_query_count', 'slow_query_threshold_ms', 'index_hit_ratio', 'index_miss_ratio',
        'search_degradation_pct', 'replay_query_overhead_pct', 'latency_state',
        'details', 'created_at',
    ];

    protected $casts = [
        'p50_latency_ms'           => 'float',
        'p95_latency_ms'           => 'float',
        'p99_latency_ms'           => 'float',
        'slow_query_count'         => 'integer',
        'slow_query_threshold_ms'  => 'float',
        'index_hit_ratio'          => 'float',
        'index_miss_ratio'         => 'float',
        'search_degradation_pct'   => 'float',
        'replay_query_overhead_pct'=> 'float',
        'details'                  => 'array',
        'created_at'               => 'datetime',
    ];

    public const BACKENDS = ['postgresql', 'clickhouse', 'opensearch', 'threat_hunting', 'graph_traversal'];

    public const LATENCY_NORMAL   = 'normal';
    public const LATENCY_ELEVATED = 'elevated';
    public const LATENCY_DEGRADED = 'degraded';
    public const LATENCY_CRITICAL = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('query_performance_snapshots is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
