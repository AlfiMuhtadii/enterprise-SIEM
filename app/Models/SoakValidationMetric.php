<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SoakValidationMetric extends Model
{
    protected $fillable = [
        'metric_id', 'run_id', 'metric_name', 'metric_value', 'unit',
        'sample_offset_minutes', 'drift_detected', 'baseline_value',
        'drift_delta', 'is_advisory',
    ];

    protected $casts = [
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
