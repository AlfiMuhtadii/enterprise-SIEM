<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Planning recommendation — documentation only.
 *
 * IMPORTANT: This model intentionally has NO execute(), executeAt, executedAt,
 * executeCommand, or externalApiEndpoint fields or methods.
 * completed_documented = analyst documented manual external action; zero system execution.
 */
class ResponsePlanAction extends Model
{
    protected $fillable = [
        'response_plan_id', 'action_type',
        'target_entity_type', 'target_entity_key', 'target_entity_id',
        'reasoning', 'evidence_refs', 'advisory_only',
        'status', 'approved_by', 'approved_at', 'rejection_reason', 'created_by',
    ];

    protected $casts = [
        'advisory_only' => 'boolean',
        'evidence_refs' => 'array',
        'approved_at'   => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ResponsePlan::class, 'response_plan_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
