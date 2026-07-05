<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SoakValidationRun extends Model
{
    public const SOAK_TYPES = [
        '6h', '12h', 'replay', 'worker_restart', 'telemetry', 'queue', 'degraded',
    ];

    public const STATUSES = ['running', 'completed', 'aborted'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'run_id', 'soak_type', 'duration_minutes', 'status', 'passed',
        'memory_growth_mb', 'queue_lag_growth', 'replay_backlog',
        'duplicate_event_rate', 'worker_restart_count', 'telemetry_gap_rate',
        'retry_amplification_factor', 'is_advisory', 'summary',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'passed'       => 'boolean',
        'is_advisory'  => 'boolean',
        'summary'      => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('SoakValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
