<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponsePlan extends Model
{
    protected $fillable = [
        'plan_id', 'title', 'description', 'state', 'risk_level',
        'investigation_id', 'trace_id', 'entity_ids', 'related_alert_ids', 'related_incident_ids',
        'created_by', 'assigned_approver', 'approved_at', 'rejected_at',
        'rejection_reason', 'completed_at', 'metadata',
    ];

    protected $casts = [
        'entity_ids'         => 'array',
        'related_alert_ids'  => 'array',
        'related_incident_ids'=> 'array',
        'metadata'           => 'array',
        'approved_at'        => 'datetime',
        'rejected_at'        => 'datetime',
        'completed_at'       => 'datetime',
    ];

    public const VALID_STATES = [
        'draft', 'pending_approval', 'approved',
        'rejected', 'completed_documented', 'cancelled',
    ];

    public const STATE_TRANSITIONS = [
        'draft'                => ['pending_approval', 'cancelled'],
        'pending_approval'     => ['approved', 'rejected', 'cancelled'],
        'approved'             => ['completed_documented', 'cancelled'],
        'rejected'             => ['draft', 'cancelled'],
        'completed_documented' => [],
        'cancelled'            => [],
    ];

    public const ACTION_TYPES = [
        'recommend_reset_password',
        'recommend_revoke_session',
        'recommend_disable_user',
        'recommend_isolate_host',
        'recommend_block_ip',
        'recommend_block_domain',
        'recommend_rotate_secret',
        'recommend_remove_persistence',
        'recommend_collect_forensics',
        'recommend_monitor_only',
    ];

    public const ACTION_STATUSES = [
        'pending', 'approved', 'completed_documented', 'rejected', 'waived',
    ];

    public const NOTE_TYPES = [
        'general', 'approval_rationale', 'rejection_rationale',
        'completion_notes', 'evidence', 'escalation',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_approver');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ResponsePlanAction::class)->orderBy('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ResponsePlanApproval::class)->orderBy('decision_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ResponsePlanNote::class)->orderByDesc('created_at');
    }
}
