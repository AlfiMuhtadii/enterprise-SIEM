<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotSuccessMetric extends Model
{
    public const METRIC_NAMES = [
        'telemetry_continuity_pct', 'replay_success_pct',
        'queue_recovery_latency_ms', 'isolation_pass_rate',
        'endpoint_stability_pct', 'fp_ratio',
        'drift_stability_pct', 'operator_ack_latency_s',
    ];

    protected $fillable = [
        'metric_id', 'run_id', 'tenant_id', 'metric_name',
        'metric_value', 'target_value', 'target_met', 'window_hours', 'is_advisory',
    ];

    protected $casts = [
        'target_met' => 'boolean',
        'is_advisory'=> 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotSuccessMetric is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
