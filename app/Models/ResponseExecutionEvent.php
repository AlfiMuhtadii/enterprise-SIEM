<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseExecutionEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const EVENT_SUBMITTED         = 'submitted';
    public const EVENT_APPROVED          = 'approved';
    public const EVENT_REJECTED          = 'rejected';
    public const EVENT_SIMULATION_STARTED= 'simulation_started';
    public const EVENT_SIMULATION_DONE   = 'simulation_completed';
    public const EVENT_EXECUTION_REQUESTED = 'execution_requested';
    public const EVENT_EXECUTION_STARTED = 'execution_started';
    public const EVENT_EXECUTION_DONE    = 'execution_completed';
    public const EVENT_ROLLBACK_INITIATED= 'rollback_initiated';
    public const EVENT_ROLLBACK_DONE     = 'rollback_completed';
    public const EVENT_TIMEOUT           = 'timeout';
    public const EVENT_CANCELLED         = 'cancelled';
    public const EVENT_FAILED            = 'failed';

    protected $fillable = [
        'execution_id', 'event_type', 'from_state', 'to_state',
        'actor_id', 'actor_name', 'details', 'trace_id',
    ];

    protected $casts = [
        'details'    => 'array',
        'created_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ResponseExecution::class, 'execution_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
