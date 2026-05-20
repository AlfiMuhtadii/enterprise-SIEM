<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationSession extends Model
{
    protected $fillable = [
        'session_id', 'investigation_id', 'name', 'description', 'status',
        'focus_entity_id', 'focus_node_type', 'hop_depth', 'include_advisory',
        'created_by', 'metadata',
    ];

    protected $casts = [
        'hop_depth'       => 'integer',
        'include_advisory'=> 'boolean',
        'metadata'        => 'array',
    ];

    public const STATUSES = ['active', 'paused', 'completed', 'abandoned'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
