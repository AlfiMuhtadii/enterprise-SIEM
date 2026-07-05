<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryScaleValidationRun extends Model
{
    public const SCALE_PROFILES = ['scale_50', 'scale_75', 'scale_100'];
    public const STATUSES        = ['pending', 'running', 'completed', 'aborted'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'run_id', 'tenant_id', 'endpoint_count', 'scale_profile', 'status',
        'avg_events_per_second', 'telemetry_continuity_pct', 'duplicate_rate',
        'replay_backlog', 'validation_passed', 'is_advisory', 'summary',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'avg_events_per_second'    => 'float',
        'telemetry_continuity_pct' => 'float',
        'duplicate_rate'           => 'float',
        'validation_passed'        => 'boolean',
        'is_advisory'              => 'boolean',
        'summary'                  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryScaleValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
