<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Watchlist extends Model
{
    public const TYPE_USER          = 'user';
    public const TYPE_HOST          = 'host';
    public const TYPE_DOMAIN        = 'domain';
    public const TYPE_IP            = 'ip';
    public const TYPE_PROCESS       = 'process';
    public const TYPE_TRACE         = 'trace';
    public const TYPE_INVESTIGATION = 'investigation';

    public const WATCH_TYPES = [
        self::TYPE_USER, self::TYPE_HOST, self::TYPE_DOMAIN,
        self::TYPE_IP, self::TYPE_PROCESS, self::TYPE_TRACE, self::TYPE_INVESTIGATION,
    ];

    protected $fillable = [
        'watchlist_id', 'analyst_id', 'watch_type', 'watch_key',
        'watch_reason', 'active', 'expires_at', 'trace_id',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'expires_at' => 'datetime',
    ];

    public static function generateWatchlistId(): string
    {
        return 'wl-' . Str::uuid();
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WatchlistEvent::class, 'watchlist_id', 'watchlist_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
