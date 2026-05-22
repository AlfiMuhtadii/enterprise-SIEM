<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotRollbackValidation extends Model
{
    public const TRIGGERS = [
        'manual', 'health_failure', 'metric_breach', 'operator_request', 'timeout',
    ];

    protected $fillable = [
        'validation_id', 'run_id', 'tenant_id', 'trigger',
        'checkpoint_valid', 'approval_obtained', 'rollback_safe',
        'audit_complete', 'approved_by', 'verdict', 'is_advisory',
    ];

    protected $casts = [
        'checkpoint_valid'  => 'boolean',
        'approval_obtained' => 'boolean',
        'rollback_safe'     => 'boolean',
        'audit_complete'    => 'boolean',
        'is_advisory'       => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotRollbackValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
