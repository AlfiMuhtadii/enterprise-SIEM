<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointResponseCommandEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED              = 'created';
    public const EVENT_SUBMITTED            = 'submitted_for_approval';
    public const EVENT_APPROVED             = 'approved';
    public const EVENT_REJECTED             = 'rejected';
    public const EVENT_DISPATCHED           = 'dispatched';
    public const EVENT_ACKNOWLEDGED         = 'acknowledged';
    public const EVENT_COMPLETED            = 'completed';
    public const EVENT_FAILED               = 'failed';
    public const EVENT_CANCELLED            = 'cancelled';
    public const EVENT_RESULT_RECEIVED      = 'result_received';
    public const EVENT_SIGNATURE_INVALID    = 'result_signature_invalid';

    protected $fillable = [
        'command_id', 'event_type', 'from_state', 'to_state',
        'actor', 'actor_id', 'details', 'trace_id', 'occurred_at',
    ];

    protected $casts = [
        'details'    => 'array',
        'occurred_at'=> 'datetime',
        'created_at' => 'datetime',
    ];

    public function command(): BelongsTo
    {
        return $this->belongsTo(EndpointResponseCommand::class, 'command_id');
    }
}
