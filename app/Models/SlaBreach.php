<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlaBreach extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'breach_id', 'investigation_id', 'sla_policy_id', 'breach_type',
        'severity', 'overdue_seconds', 'analyst_id',
        'acknowledged', 'acknowledged_at', 'trace_id',
    ];

    protected $casts = [
        'acknowledged'     => 'boolean',
        'acknowledged_at'  => 'datetime',
    ];

    public static function generateBreachId(): string
    {
        return 'breach-' . Str::uuid();
    }
}
