<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotRollbackAudit extends Model
{
    protected $table = 'pilot_rollback_audit';

    public const TRIGGER_REASONS = ['threshold_breach', 'operator_request', 'drift_critical', 'health_fail'];
    public const STATUSES        = ['pending_approval', 'approved', 'executed', 'verified', 'rejected'];

    protected $fillable = [
        'rollback_id', 'run_id', 'tenant_id', 'trigger_reason', 'triggered_by',
        'rollback_approved', 'approved_by', 'status', 'destructive_action',
        'replay_reconstructed', 'isolation_preserved', 'is_advisory', 'audit_trail',
    ];

    protected $casts = [
        'rollback_approved'    => 'boolean',
        'destructive_action'   => 'boolean',
        'replay_reconstructed' => 'boolean',
        'isolation_preserved'  => 'boolean',
        'is_advisory'          => 'boolean',
        'audit_trail'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotRollbackAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
