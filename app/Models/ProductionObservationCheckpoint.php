<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionObservationCheckpoint extends Model
{
    public const WINDOW_TYPES = ['24h', '48h', '72h'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'checkpoint_id', 'run_id', 'tenant_id', 'window_type',
        'telemetry_continuity_pct', 'replay_recovery_success_pct',
        'drift_stability_pct', 'rollback_readiness_score',
        'criteria_met', 'is_advisory', 'summary',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'telemetry_continuity_pct'    => 'float',
        'replay_recovery_success_pct' => 'float',
        'drift_stability_pct'         => 'float',
        'rollback_readiness_score'    => 'float',
        'criteria_met'                => 'boolean',
        'is_advisory'                 => 'boolean',
        'summary'                     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ProductionObservationCheckpoint is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
