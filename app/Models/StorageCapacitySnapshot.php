<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Storage capacity growth snapshot — forward-looking projection.
 * DISTINCT from StoragePressureSnapshot (operational health).
 * Tracks: growth_rate, retention_projection, exhaustion_date, cost_estimate.
 */
class StorageCapacitySnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'backend', 'current_size_bytes', 'growth_rate_bytes_per_day',
        'compression_ratio', 'index_growth_pct', 'shard_pressure_pct', 'partition_pressure_pct',
        'estimated_exhaustion_date', 'retention_cost_estimate', 'capacity_state', 'details', 'created_at',
    ];

    protected $casts = [
        'current_size_bytes'          => 'integer',
        'growth_rate_bytes_per_day'   => 'float',
        'compression_ratio'           => 'float',
        'index_growth_pct'            => 'float',
        'shard_pressure_pct'          => 'float',
        'partition_pressure_pct'      => 'float',
        'retention_cost_estimate'     => 'float',
        'details'                     => 'array',
        'created_at'                  => 'datetime',
    ];

    public const BACKENDS = ['postgresql', 'clickhouse', 'opensearch', 'redpanda', 'qdrant'];

    public const STATE_HEALTHY  = 'healthy';
    public const STATE_GROWING  = 'growing';
    public const STATE_PRESSURE = 'pressure';
    public const STATE_CRITICAL = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('storage_capacity_snapshots is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
