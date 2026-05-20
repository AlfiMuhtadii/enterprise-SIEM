<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarExecutionAudit extends Model
{
    protected $table = 'soar_execution_audit';

    public $timestamps = false;

    protected $fillable = [
        'audit_id', 'plan_id', 'playbook_id', 'event_type', 'from_status', 'to_status',
        'description', 'is_advisory', 'metadata', 'actor_id', 'trace_id', 'created_at',
    ];

    protected $casts = [
        'is_advisory' => 'boolean',
        'metadata'    => 'array',
        'created_at'  => 'datetime',
    ];

    public const EVENT_PLAN_CREATED        = 'plan_created';
    public const EVENT_SIMULATION_STARTED  = 'simulation_started';
    public const EVENT_SIMULATION_COMPLETED= 'simulation_completed';
    public const EVENT_APPROVAL_REQUESTED  = 'approval_requested';
    public const EVENT_APPROVAL_GRANTED    = 'approval_granted';
    public const EVENT_APPROVAL_REJECTED   = 'approval_rejected';
    public const EVENT_STEP_COMPLETED      = 'step_completed';
    public const EVENT_PLAN_COMPLETED      = 'plan_completed';
    public const EVENT_ROLLBACK_INITIATED  = 'rollback_initiated';
    public const EVENT_PLAN_CANCELLED      = 'plan_cancelled';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('soar_execution_audit is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
