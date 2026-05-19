<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WatchlistEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_CREATED     = 'created';
    public const TYPE_TRIGGERED   = 'triggered';
    public const TYPE_EXPIRED     = 'expired';
    public const TYPE_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'event_id', 'watchlist_id', 'event_type',
        'analyst_id', 'details', 'trace_id',
    ];

    protected $casts = ['details' => 'array'];

    public static function generateEventId(): string
    {
        return 'wlev-' . Str::uuid();
    }
}
