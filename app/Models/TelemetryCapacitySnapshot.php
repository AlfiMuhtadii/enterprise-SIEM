<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryCapacitySnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'topic', 'events_per_sec', 'events_per_day', 'topic_growth_pct',
        'partition_utilization_pct', 'queue_depth', 'ingestion_spike_ratio',
        'cardinality_pressure_score', 'pressure_state', 'window_duration', 'details', 'created_at',
    ];

    protected $casts = [
        'events_per_sec'               => 'float',
        'events_per_day'               => 'integer',
        'topic_growth_pct'             => 'float',
        'partition_utilization_pct'    => 'float',
        'queue_depth'                  => 'integer',
        'ingestion_spike_ratio'        => 'float',
        'cardinality_pressure_score'   => 'float',
        'details'                      => 'array',
        'created_at'                   => 'datetime',
    ];

    public const PRESSURE_NORMAL   = 'normal';
    public const PRESSURE_ELEVATED = 'elevated';
    public const PRESSURE_HIGH     = 'high';
    public const PRESSURE_CRITICAL = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('telemetry_capacity_snapshots is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
