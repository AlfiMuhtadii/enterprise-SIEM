<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InfrastructurePressureRun extends Model
{
    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'pressure_id', 'run_id', 'tenant_id', 'cpu_usage_pct', 'memory_growth_mb',
        'storage_pressure_pct', 'partition_pressure_pct', 'query_latency_ms',
        'graph_traversal_latency_ms', 'replay_latency_ms',
        'pressure_within_bounds', 'is_advisory', 'pressure_snapshot',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'cpu_usage_pct'               => 'float',
        'memory_growth_mb'            => 'float',
        'storage_pressure_pct'        => 'float',
        'partition_pressure_pct'      => 'float',
        'query_latency_ms'            => 'float',
        'graph_traversal_latency_ms'  => 'float',
        'replay_latency_ms'           => 'float',
        'pressure_within_bounds'      => 'boolean',
        'is_advisory'                 => 'boolean',
        'pressure_snapshot'           => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('InfrastructurePressureRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
