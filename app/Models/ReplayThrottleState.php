<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReplayThrottleState extends Model
{
    protected $fillable = [
        'throttle_id', 'consumer_group', 'topic', 'max_events_per_second',
        'retry_budget_limit', 'current_retry_count', 'state', 'is_active',
        'throttle_started_at', 'throttle_cleared_at',
    ];

    protected $casts = [
        'max_events_per_second'=> 'integer',
        'retry_budget_limit'   => 'integer',
        'current_retry_count'  => 'integer',
        'is_active'            => 'boolean',
        'throttle_started_at'  => 'datetime',
        'throttle_cleared_at'  => 'datetime',
    ];

    public const STATE_NORMAL    = 'normal';
    public const STATE_THROTTLED = 'throttled';
    public const STATE_EXHAUSTED = 'exhausted';

    public const STATES = [self::STATE_NORMAL, self::STATE_THROTTLED, self::STATE_EXHAUSTED];
}
