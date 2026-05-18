<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationAssignment extends Model
{
    protected $fillable = [
        'investigation_id', 'assigned_to_user_id', 'assigned_by_user_id',
        'role', 'assigned_at', 'unassigned_at', 'is_active',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'assigned_at'   => 'datetime',
        'unassigned_at' => 'datetime',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
