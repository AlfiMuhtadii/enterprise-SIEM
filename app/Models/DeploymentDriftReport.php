<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentDriftReport extends Model
{
    protected $table = 'deployment_drift_reports';

    public const SEVERITY_LEVELS = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'drift_id', 'service', 'expected_version', 'actual_version',
        'drift_detected', 'drift_severity', 'drift_score',
        'is_advisory', 'drift_evidence',
    ];

    protected $casts = [
        'drift_detected' => 'boolean',
        'is_advisory'    => 'boolean',
        'drift_evidence' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DeploymentDriftReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
