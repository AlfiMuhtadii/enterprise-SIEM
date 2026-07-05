<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryContinuityReport extends Model
{
    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'report_id', 'soak_run_id', 'observation_window_minutes',
        'expected_events', 'observed_events', 'continuity_pct',
        'gap_count', 'total_gap_seconds', 'continuity_ok', 'verdict', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'continuity_ok' => 'boolean',
        'is_advisory'   => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryContinuityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
