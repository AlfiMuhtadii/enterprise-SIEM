<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalDriftHistory extends Model
{
    protected $table = 'operational_drift_history';

    public const DRIFT_VERDICTS = ['stable', 'monitoring', 'escalated', 'critical'];

    protected $fillable = [
        'drift_id', 'tenant_id', 'window_type',
        'replay_amplification_drift', 'queue_growth_drift', 'telemetry_growth_drift',
        'analyst_overload_drift', 'storage_pressure_drift', 'infrastructure_degradation_drift',
        'graph_traversal_latency_drift', 'replay_latency_drift',
        'composite_drift_score', 'drift_verdict', 'is_advisory', 'drift_breakdown',
    ];

    protected $casts = [
        'replay_amplification_drift'       => 'float',
        'queue_growth_drift'               => 'float',
        'telemetry_growth_drift'           => 'float',
        'analyst_overload_drift'           => 'float',
        'storage_pressure_drift'           => 'float',
        'infrastructure_degradation_drift' => 'float',
        'graph_traversal_latency_drift'    => 'float',
        'replay_latency_drift'             => 'float',
        'composite_drift_score'            => 'float',
        'is_advisory'                      => 'boolean',
        'drift_breakdown'                  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalDriftHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
