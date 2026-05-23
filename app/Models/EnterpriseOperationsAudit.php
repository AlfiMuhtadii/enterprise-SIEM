<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EnterpriseOperationsAudit extends Model
{
    protected $table = 'enterprise_operations_audit';

    public const OPERATION_TYPES = [
        'recovery_initiated', 'lifecycle_event', 'failover_drill',
        'automation_check', 'simulation_run', 'continuity_check',
    ];

    public const OUTCOMES = ['success', 'failure', 'advisory', 'skipped'];

    protected $fillable = [
        'ops_audit_id', 'operation_type', 'service_scope',
        'actor', 'outcome', 'is_advisory', 'ops_audit_payload',
    ];

    protected $casts = [
        'is_advisory'       => 'boolean',
        'ops_audit_payload' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EnterpriseOperationsAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
