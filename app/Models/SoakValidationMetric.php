<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SoakValidationMetric extends Model
{
    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'metric_id', 'run_id', 'metric_name', 'metric_value', 'unit',
        'sample_offset_minutes', 'drift_detected', 'baseline_value',
        'drift_delta', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'drift_detected' => 'boolean',
        'is_advisory'    => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('SoakValidationMetric is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
