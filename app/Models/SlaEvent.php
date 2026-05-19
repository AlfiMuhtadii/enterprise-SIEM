<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlaEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_STARTED   = 'started';
    public const TYPE_PAUSED    = 'paused';
    public const TYPE_RESUMED   = 'resumed';
    public const TYPE_COMPLETED = 'completed';
    public const TYPE_BREACHED  = 'breached';

    protected $fillable = [
        'event_id', 'investigation_id', 'sla_policy_id',
        'event_type', 'analyst_id', 'trace_id',
    ];

    public static function generateEventId(): string
    {
        return 'slaev-' . Str::uuid();
    }
}
