<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvestigationWatcher extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'watcher_id', 'investigation_id', 'analyst_id',
        'watch_reason', 'added_by', 'trace_id',
    ];

    public static function generateWatcherId(): string
    {
        return 'watch-' . Str::uuid();
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }
}
