<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentReadinessRun extends Model
{
    protected $fillable = [
        'run_id', 'release_id', 'validator_status', 'migration_ready',
        'schema_compatible', 'replay_validator_healthy', 'queue_healthy',
        'storage_pressure_ok', 'degraded_mode_clear', 'worker_health_ok',
        'rollback_ready', 'overall_verdict', 'validation_details', 'triggered_by',
    ];

    protected $casts = [
        'migration_ready'          => 'boolean',
        'schema_compatible'        => 'boolean',
        'replay_validator_healthy' => 'boolean',
        'queue_healthy'            => 'boolean',
        'storage_pressure_ok'      => 'boolean',
        'degraded_mode_clear'      => 'boolean',
        'worker_health_ok'         => 'boolean',
        'rollback_ready'           => 'boolean',
        'validation_details'       => 'array',
    ];

    public const VERDICT_GO       = 'go';
    public const VERDICT_NO_GO    = 'no_go';
    public const VERDICT_NOT_READY = 'not_ready';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('DeploymentReadinessRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
