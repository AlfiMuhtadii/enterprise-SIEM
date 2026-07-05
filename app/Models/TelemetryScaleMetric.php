<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryScaleMetric extends Model
{
    public const METRIC_TYPES = ['throughput', 'queue_lag', 'replay_backlog', 'storage', 'worker_restarts', 'duplicate_rate'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'metric_id', 'run_id', 'tenant_id', 'metric_type',
        'value', 'baseline_value', 'drift_pct', 'within_bounds', 'is_advisory', 'metadata',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'value'          => 'float',
        'baseline_value' => 'float',
        'drift_pct'      => 'float',
        'within_bounds'  => 'boolean',
        'is_advisory'    => 'boolean',
        'metadata'       => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryScaleMetric is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
