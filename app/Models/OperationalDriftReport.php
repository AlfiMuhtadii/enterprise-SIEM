<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalDriftReport extends Model
{
    public const DRIFT_TYPES = [
        'memory', 'queue', 'replay_amplification', 'worker_restart',
        'telemetry_throughput', 'storage_latency', 'query_latency', 'graph_traversal',
    ];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'report_id', 'run_id', 'drift_type', 'baseline_value', 'observed_value',
        'drift_delta', 'drift_pct', 'window_minutes', 'drift_exceeds_threshold', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'drift_exceeds_threshold' => 'boolean',
        'is_advisory'             => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalDriftReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
