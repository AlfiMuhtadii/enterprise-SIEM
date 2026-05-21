<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapacityProjectionRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'scope', 'projection_days', 'current_daily_growth_bytes',
        'projected_30d_bytes', 'projected_90d_bytes', 'replay_scaling_threshold_eps',
        'worker_scaling_threshold', 'storage_exhaustion_forecast', 'queue_pressure_forecast',
        'confidence_level', 'assumptions', 'deterministic', 'triggered_by', 'created_at',
    ];

    protected $casts = [
        'projection_days'              => 'integer',
        'current_daily_growth_bytes'   => 'float',
        'projected_30d_bytes'          => 'float',
        'projected_90d_bytes'          => 'float',
        'replay_scaling_threshold_eps' => 'float',
        'worker_scaling_threshold'     => 'integer',
        'confidence_level'             => 'float',
        'deterministic'                => 'boolean',
        'created_at'                   => 'datetime',
    ];

    public const SCOPES = ['postgresql', 'clickhouse', 'opensearch', 'redpanda', 'qdrant', 'full'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('capacity_projection_runs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
