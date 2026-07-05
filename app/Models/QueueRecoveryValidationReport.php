<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class QueueRecoveryValidationReport extends Model
{
    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'report_id', 'run_id', 'tenant_id', 'queue_lag_at_start', 'queue_lag_at_end',
        'recovery_latency_seconds', 'duplicate_protected', 'replay_amplification_safe',
        'continuity_after_reconnect', 'recovery_successful', 'is_advisory', 'recovery_evidence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'recovery_latency_seconds'   => 'float',
        'duplicate_protected'        => 'boolean',
        'replay_amplification_safe'  => 'boolean',
        'continuity_after_reconnect' => 'boolean',
        'recovery_successful'        => 'boolean',
        'is_advisory'                => 'boolean',
        'recovery_evidence'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('QueueRecoveryValidationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
