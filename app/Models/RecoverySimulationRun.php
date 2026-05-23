<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecoverySimulationRun extends Model
{
    protected $table = 'recovery_simulation_runs';

    public const SIMULATION_TYPES = [
        'dependency_failure', 'replay_recovery', 'queue_reconnect',
        'service_restart', 'storage_reconnect',
    ];

    protected $fillable = [
        'simulation_id', 'simulation_type', 'target_service',
        'duration_s', 'simulation_passed', 'destructive_action_taken',
        'replay_safe', 'is_advisory', 'simulation_evidence',
    ];

    protected $casts = [
        'simulation_passed'       => 'boolean',
        'destructive_action_taken'=> 'boolean',
        'replay_safe'             => 'boolean',
        'is_advisory'             => 'boolean',
        'simulation_evidence'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RecoverySimulationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
