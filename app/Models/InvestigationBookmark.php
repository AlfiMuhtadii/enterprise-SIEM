<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationBookmark extends Model
{
    protected $fillable = [
        'bookmark_id', 'session_id', 'investigation_id', 'label', 'notes',
        'pivot_type', 'pivot_value', 'pivot_domain', 'is_pinned', 'created_by',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InvestigationSession::class, 'session_id', 'session_id');
    }
}
