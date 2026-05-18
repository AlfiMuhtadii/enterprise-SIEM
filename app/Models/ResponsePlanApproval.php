<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsePlanApproval extends Model
{
    protected $fillable = [
        'response_plan_id', 'approver_user_id', 'decision',
        'decision_at', 'notes', 'from_state', 'to_state',
    ];

    protected $casts = [
        'decision_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ResponsePlan::class, 'response_plan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
