<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AnalystHandoff extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'handoff_id', 'from_analyst_id', 'to_analyst_id',
        'shift_summary', 'unresolved_count', 'pending_escalation_count',
        'notes', 'active_investigations', 'trace_id',
    ];

    protected $casts = ['active_investigations' => 'array'];

    public static function generateHandoffId(): string
    {
        return 'hoff-' . Str::uuid();
    }

    public function fromAnalyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_analyst_id');
    }

    public function toAnalyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_analyst_id');
    }
}
