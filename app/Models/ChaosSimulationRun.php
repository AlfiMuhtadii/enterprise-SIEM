<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ChaosSimulationRun extends Model
{
    public const SCENARIOS = [
        'worker_restart', 'queue_disconnect', 'storage_unavailable',
        'replay_throttle', 'delayed_telemetry', 'dependency_timeout',
        'degraded_index', 'endpoint_disconnect',
    ];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'simulation_id', 'scenario', 'duration_seconds', 'recovery_verified',
        'failures_injected', 'recoveries_observed', 'verdict', 'replay_safe',
        'isolation_preserved', 'is_advisory', 'failure_sequence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'recovery_verified'   => 'boolean',
        'replay_safe'         => 'boolean',
        'isolation_preserved' => 'boolean',
        'is_advisory'         => 'boolean',
        'failure_sequence'    => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ChaosSimulationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
