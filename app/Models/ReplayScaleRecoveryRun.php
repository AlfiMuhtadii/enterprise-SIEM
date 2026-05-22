<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReplayScaleRecoveryRun extends Model
{
    protected $fillable = [
        'recovery_id', 'run_id', 'tenant_id', 'backlog_at_start', 'backlog_at_end',
        'recovery_latency_seconds', 'replay_amplification_factor', 'amplification_bounded',
        'duplicate_protected', 'recovery_successful', 'is_advisory', 'recovery_evidence',
    ];

    protected $casts = [
        'recovery_latency_seconds'    => 'float',
        'replay_amplification_factor' => 'float',
        'amplification_bounded'       => 'boolean',
        'duplicate_protected'         => 'boolean',
        'recovery_successful'         => 'boolean',
        'is_advisory'                 => 'boolean',
        'recovery_evidence'           => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ReplayScaleRecoveryRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
