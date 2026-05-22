<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class QueueRecoveryValidationReport extends Model
{
    protected $fillable = [
        'report_id', 'run_id', 'tenant_id', 'queue_lag_at_start', 'queue_lag_at_end',
        'recovery_latency_seconds', 'duplicate_protected', 'replay_amplification_safe',
        'continuity_after_reconnect', 'recovery_successful', 'is_advisory', 'recovery_evidence',
    ];

    protected $casts = [
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
