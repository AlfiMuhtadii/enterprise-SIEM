<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryGrowthDriftReport extends Model
{
    public const DRIFT_DIMENSIONS = [
        'replay_amplification', 'telemetry_growth', 'queue_lag',
        'analyst_overload', 'storage_growth', 'query_latency', 'graph_traversal',
    ];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'drift_id', 'run_id', 'tenant_id', 'drift_dimension',
        'current_value', 'baseline_value', 'drift_magnitude',
        'drift_severity', 'drift_bounded', 'is_advisory', 'drift_evidence',
    ];

    protected $casts = [
        'current_value'  => 'float',
        'baseline_value' => 'float',
        'drift_magnitude'=> 'float',
        'drift_bounded'  => 'boolean',
        'is_advisory'    => 'boolean',
        'drift_evidence' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryGrowthDriftReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
