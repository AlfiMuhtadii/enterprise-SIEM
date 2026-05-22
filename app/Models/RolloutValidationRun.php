<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RolloutValidationRun extends Model
{
    protected $table = 'rollout_validation_runs';

    public const STAGES = ['canary', 'staged', 'full'];

    protected $fillable = [
        'run_id', 'rollout_id', 'stage', 'canary_endpoints',
        'rollout_success', 'checkpoint_passed', 'bounded_concurrency',
        'rollout_duration_s', 'replay_safe', 'is_advisory', 'rollout_evidence',
    ];

    protected $casts = [
        'rollout_success'    => 'boolean',
        'checkpoint_passed'  => 'boolean',
        'bounded_concurrency'=> 'boolean',
        'replay_safe'        => 'boolean',
        'is_advisory'        => 'boolean',
        'rollout_evidence'   => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RolloutValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
