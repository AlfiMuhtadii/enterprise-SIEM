<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecoveryCheckpointHistory extends Model
{
    protected $table = 'recovery_checkpoint_history';

    public const CHECKPOINT_TYPES = [
        'pre_recovery', 'post_restart', 'post_reconnect',
        'post_replay', 'post_failover', 'continuity_verified',
    ];

    protected $fillable = [
        'ops_checkpoint_id', 'recovery_run_id', 'checkpoint_type',
        'passed', 'replay_safe', 'is_advisory', 'checkpoint_details',
    ];

    protected $casts = [
        'passed'              => 'boolean',
        'replay_safe'         => 'boolean',
        'is_advisory'         => 'boolean',
        'checkpoint_details'  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RecoveryCheckpointHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
