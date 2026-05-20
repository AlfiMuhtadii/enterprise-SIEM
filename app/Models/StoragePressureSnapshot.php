<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoragePressureSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'backend', 'retention_pressure_pct', 'index_size_bytes',
        'ingestion_lag_seconds', 'write_failure_rate', 'query_latency_ms',
        'compaction_pressure_pct', 'pressure_state', 'degraded', 'details', 'created_at',
    ];

    protected $casts = [
        'retention_pressure_pct'   => 'float',
        'index_size_bytes'         => 'integer',
        'ingestion_lag_seconds'    => 'float',
        'write_failure_rate'       => 'float',
        'query_latency_ms'         => 'float',
        'compaction_pressure_pct'  => 'float',
        'degraded'                 => 'boolean',
        'details'                  => 'array',
        'created_at'               => 'datetime',
    ];

    public const BACKENDS = ['postgresql', 'clickhouse', 'opensearch', 'redpanda', 'qdrant'];

    public const PRESSURE_NORMAL   = 'normal';
    public const PRESSURE_WARNING  = 'warning';
    public const PRESSURE_CRITICAL = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('storage_pressure_snapshots is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
