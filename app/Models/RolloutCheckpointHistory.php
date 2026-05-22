<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RolloutCheckpointHistory extends Model
{
    protected $table = 'rollout_checkpoint_history';

    public const CHECKPOINT_TYPES = [
        'pre_rollout', 'post_canary', 'post_staged',
        'post_full', 'rollback_verification',
    ];

    protected $fillable = [
        'checkpoint_id', 'rollout_id', 'checkpoint_type',
        'passed', 'replay_safe', 'is_advisory', 'validation_details',
    ];

    protected $casts = [
        'passed'             => 'boolean',
        'replay_safe'        => 'boolean',
        'is_advisory'        => 'boolean',
        'validation_details' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RolloutCheckpointHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
