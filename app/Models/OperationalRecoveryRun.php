<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalRecoveryRun extends Model
{
    protected $table = 'operational_recovery_runs';

    public const RECOVERY_TYPES  = ['restart', 'reconnect', 'replay', 'failover', 'drain'];
    public const RECOVERY_STATES = ['initiated', 'validating', 'checkpoint_passed', 'completed', 'failed'];

    protected $fillable = [
        'recovery_run_id', 'service_name', 'recovery_type', 'recovery_state',
        'sequence_order', 'duration_s', 'replay_continuous',
        'bounded_scope', 'is_advisory', 'recovery_evidence',
    ];

    protected $casts = [
        'replay_continuous' => 'boolean',
        'bounded_scope'     => 'boolean',
        'is_advisory'       => 'boolean',
        'recovery_evidence' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalRecoveryRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
