<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointAgentMetric extends Model
{
    protected $fillable = [
        'agent_id', 'events_per_sec', 'dropped_events', 'retry_count',
        'buffer_depth', 'total_sent', 'collection_cycles',
        'last_successful_send', 'recorded_at',
    ];

    protected $casts = [
        'events_per_sec'      => 'float',
        'last_successful_send'=> 'datetime',
        'recorded_at'         => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
