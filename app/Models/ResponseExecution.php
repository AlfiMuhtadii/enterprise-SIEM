<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResponseExecution extends Model
{
    // 11-state machine
    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_SIMULATION_READY = 'simulation_ready';
    public const STATUS_SIMULATED        = 'simulated';
    public const STATUS_EXECUTION_READY  = 'execution_ready';
    public const STATUS_EXECUTED         = 'executed';
    public const STATUS_ROLLBACK_READY   = 'rollback_ready';
    public const STATUS_ROLLED_BACK      = 'rolled_back';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_CANCELLED        = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_SIMULATION_READY,
        self::STATUS_SIMULATED,
        self::STATUS_EXECUTION_READY,
        self::STATUS_EXECUTED,
        self::STATUS_ROLLBACK_READY,
        self::STATUS_ROLLED_BACK,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_EXECUTED,
        self::STATUS_ROLLED_BACK,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    // Valid transitions: from → [allowed tos]
    public const STATE_TRANSITIONS = [
        self::STATUS_DRAFT            => [self::STATUS_PENDING_APPROVAL, self::STATUS_CANCELLED],
        self::STATUS_PENDING_APPROVAL => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED         => [self::STATUS_SIMULATION_READY, self::STATUS_CANCELLED],
        self::STATUS_SIMULATION_READY => [self::STATUS_SIMULATED, self::STATUS_FAILED],
        self::STATUS_SIMULATED        => [self::STATUS_EXECUTION_READY, self::STATUS_CANCELLED],
        self::STATUS_EXECUTION_READY  => [self::STATUS_EXECUTED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_EXECUTED         => [self::STATUS_ROLLBACK_READY],
        self::STATUS_ROLLBACK_READY   => [self::STATUS_ROLLED_BACK, self::STATUS_FAILED],
        self::STATUS_ROLLED_BACK      => [],
        self::STATUS_FAILED           => [],
        self::STATUS_CANCELLED        => [],
    ];

    // Allowed Phase 2 action types
    public const ACTION_REVOKE_SESSION = 'revoke_session';
    public const ACTION_DISABLE_USER   = 'disable_user';
    public const ACTION_ISOLATE_HOST   = 'isolate_host';
    public const ACTION_BLOCK_IP       = 'block_ip';
    public const ACTION_BLOCK_DOMAIN   = 'block_domain';

    public const ALLOWED_ACTIONS = [
        self::ACTION_REVOKE_SESSION,
        self::ACTION_DISABLE_USER,
        self::ACTION_ISOLATE_HOST,
        self::ACTION_BLOCK_IP,
        self::ACTION_BLOCK_DOMAIN,
    ];

    // Actions that require a second approver (creator still cannot self-approve)
    public const DUAL_APPROVAL_REQUIRED = [
        self::ACTION_DISABLE_USER,
        self::ACTION_ISOLATE_HOST,
        self::ACTION_BLOCK_IP,
        self::ACTION_BLOCK_DOMAIN,
    ];

    // All actions require simulation before execution
    public const SIMULATION_REQUIRED_ACTIONS = [
        self::ACTION_REVOKE_SESSION,
        self::ACTION_DISABLE_USER,
        self::ACTION_ISOLATE_HOST,
        self::ACTION_BLOCK_IP,
        self::ACTION_BLOCK_DOMAIN,
    ];

    // Actions that support rollback
    public const ROLLBACK_SUPPORTED_ACTIONS = [
        self::ACTION_REVOKE_SESSION,
        self::ACTION_BLOCK_IP,
        self::ACTION_BLOCK_DOMAIN,
    ];

    public const MAX_TARGETS_PER_EXECUTION = 1;
    public const APPROVAL_EXPIRES_HOURS    = 24;
    public const ROLLBACK_DEADLINE_HOURS   = 48;
    public const EXECUTION_TIMEOUT_SECONDS = 300;

    protected $fillable = [
        'execution_id', 'action_type', 'target_entity_type', 'target_entity_key',
        'target_entity_id', 'created_by', 'status', 'requires_dual_approval',
        'approver_1_id', 'approver_1_approved_at', 'approver_1_rationale',
        'approver_2_id', 'approver_2_approved_at', 'approver_2_rationale',
        'approval_expires_at', 'simulation_required', 'simulation_completed_at',
        'execution_started_at', 'execution_completed_at', 'execution_timeout_seconds',
        'rollback_supported', 'rollback_deadline_at',
        'blast_radius_score', 'execution_safety_score', 'execution_confidence_score',
        'hunt_evidence', 'correlation_ids', 'rationale', 'notes', 'trace_id', 'tenant_id',
    ];

    protected $casts = [
        'requires_dual_approval'    => 'boolean',
        'simulation_required'       => 'boolean',
        'rollback_supported'        => 'boolean',
        'hunt_evidence'             => 'array',
        'correlation_ids'           => 'array',
        'approver_1_approved_at'    => 'datetime',
        'approver_2_approved_at'    => 'datetime',
        'approval_expires_at'       => 'datetime',
        'simulation_completed_at'   => 'datetime',
        'execution_started_at'      => 'datetime',
        'execution_completed_at'    => 'datetime',
        'rollback_deadline_at'      => 'datetime',
        'blast_radius_score'        => 'float',
        'execution_safety_score'    => 'float',
        'execution_confidence_score'=> 'float',
    ];

    public static function generateExecutionId(): string
    {
        $year = (int) date('Y');
        $max  = static::whereYear('created_at', $year)->max('id') ?? 0;
        return sprintf('EXEC-%d-%05d', $year, $max + 1);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::STATE_TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isApprovalExpired(): bool
    {
        return $this->approval_expires_at !== null
            && $this->approval_expires_at->isPast();
    }

    public function isFullyApproved(): bool
    {
        if ($this->requires_dual_approval) {
            return $this->approver_1_id !== null && $this->approver_2_id !== null;
        }
        return $this->approver_1_id !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_1_id');
    }

    public function approver2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_2_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResponseExecutionEvent::class, 'execution_id');
    }

    public function rollbacks(): HasMany
    {
        return $this->hasMany(ResponseExecutionRollback::class, 'execution_id');
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(ResponseExecutionSimulation::class, 'execution_id');
    }

    public function latestSimulation(): HasOne
    {
        return $this->hasOne(ResponseExecutionSimulation::class, 'execution_id')
            ->latestOfMany('id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
