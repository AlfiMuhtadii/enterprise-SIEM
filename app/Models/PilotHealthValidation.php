<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotHealthValidation extends Model
{
    public const CHECK_TYPES = [
        'telemetry', 'replay', 'queue', 'worker', 'endpoint',
        'isolation', 'dashboard', 'hunt', 'storage', 'drift',
    ];

    protected $fillable = [
        'validation_id', 'run_id', 'tenant_id', 'check_type', 'check_passed',
        'verdict', 'failure_reason', 'metric_value', 'threshold_value', 'is_advisory',
    ];

    protected $casts = [
        'check_passed' => 'boolean',
        'is_advisory'  => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotHealthValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
