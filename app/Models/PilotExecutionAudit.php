<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotExecutionAudit extends Model
{
    protected $table = 'pilot_execution_audit';

    public const EVENT_TYPES = ['activation', 'enrollment', 'checkpoint', 'review', 'drift', 'rollback', 'completion'];
    public const OUTCOMES    = ['success', 'failure', 'pending', 'escalated'];

    protected $fillable = [
        'audit_id', 'run_id', 'tenant_id', 'event_type', 'actor',
        'outcome', 'description', 'is_advisory', 'payload',
    ];

    protected $casts = [
        'is_advisory' => 'boolean',
        'payload'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotExecutionAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
