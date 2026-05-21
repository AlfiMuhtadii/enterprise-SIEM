<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollbackValidationRun extends Model
{
    protected $fillable = [
        'run_id', 'release_id', 'rollback_target_version',
        'migration_reversible', 'schema_rollback_safe', 'replay_safe',
        'data_integrity_ok', 'readiness_score', 'verdict',
        'simulation_results', 'validated_by',
    ];

    protected $casts = [
        'migration_reversible'  => 'boolean',
        'schema_rollback_safe'  => 'boolean',
        'replay_safe'           => 'boolean',
        'data_integrity_ok'     => 'boolean',
        'readiness_score'       => 'float',
        'simulation_results'    => 'array',
    ];

    public const VERDICT_READY     = 'ready';
    public const VERDICT_PARTIAL   = 'partial';
    public const VERDICT_NOT_READY = 'not_ready';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('RollbackValidationRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
