<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvironmentDriftReport extends Model
{
    protected $fillable = [
        'report_id', 'release_id', 'drift_type', 'component',
        'expected_value', 'observed_value', 'severity', 'is_blocking', 'detected_by',
    ];

    protected $casts = [
        'is_blocking' => 'boolean',
    ];

    public const DRIFT_TYPES = [
        'config_version', 'schema_mismatch', 'dependency_mismatch',
        'migration_drift', 'validator_drift', 'dashboard_drift',
        'route_drift', 'policy_drift',
    ];

    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('EnvironmentDriftReport is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
