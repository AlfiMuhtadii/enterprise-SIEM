<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FailoverValidationRun extends Model
{
    protected $table = 'failover_validation_runs';

    public const FAILOVER_TYPES = [
        'active_passive', 'drain_switch', 'replica_promotion', 'dependency_reroute',
    ];

    protected $fillable = [
        'failover_run_id', 'service_name', 'failover_type',
        'readiness_verified', 'dependency_mapped', 'continuity_verified',
        'rollback_ready', 'is_advisory', 'failover_evidence',
    ];

    protected $casts = [
        'readiness_verified'   => 'boolean',
        'dependency_mapped'    => 'boolean',
        'continuity_verified'  => 'boolean',
        'rollback_ready'       => 'boolean',
        'is_advisory'          => 'boolean',
        'failover_evidence'    => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('FailoverValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
