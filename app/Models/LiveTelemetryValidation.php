<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LiveTelemetryValidation extends Model
{
    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'validation_id', 'run_id', 'tenant_id', 'events_per_second',
        'telemetry_continuity_pct', 'queue_lag', 'replay_continuity_pct',
        'duplicate_event_rate', 'telemetry_gap_rate', 'collector_reconnect_count',
        'storage_pressure_pct', 'worker_healthy', 'validation_passed',
        'is_advisory', 'raw_metrics',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'events_per_second'        => 'float',
        'telemetry_continuity_pct' => 'float',
        'replay_continuity_pct'    => 'float',
        'duplicate_event_rate'     => 'float',
        'telemetry_gap_rate'       => 'float',
        'storage_pressure_pct'     => 'float',
        'worker_healthy'           => 'boolean',
        'validation_passed'        => 'boolean',
        'is_advisory'              => 'boolean',
        'raw_metrics'              => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('LiveTelemetryValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
