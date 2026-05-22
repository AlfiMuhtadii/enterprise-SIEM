<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotHealthCheckpoint extends Model
{
    public const CHECKPOINT_TYPES = ['24h', '48h', '72h', 'manual', 'escalation'];

    protected $fillable = [
        'checkpoint_id', 'run_id', 'tenant_id', 'checkpoint_type',
        'telemetry_continuity_pct', 'replay_recovery_success_pct',
        'queue_recovery_latency_ms', 'endpoint_stability_pct',
        'tenant_isolation_pass_rate', 'false_positive_ratio',
        'drift_stability_pct', 'rollback_readiness_score',
        'health_ok', 'is_advisory', 'metrics',
    ];

    protected $casts = [
        'telemetry_continuity_pct'   => 'float',
        'replay_recovery_success_pct'=> 'float',
        'queue_recovery_latency_ms'  => 'float',
        'endpoint_stability_pct'     => 'float',
        'tenant_isolation_pass_rate' => 'float',
        'false_positive_ratio'       => 'float',
        'drift_stability_pct'        => 'float',
        'rollback_readiness_score'   => 'float',
        'health_ok'                  => 'boolean',
        'is_advisory'                => 'boolean',
        'metrics'                    => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotHealthCheckpoint is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
