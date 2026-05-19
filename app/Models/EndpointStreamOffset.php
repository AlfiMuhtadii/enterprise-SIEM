<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndpointStreamOffset extends Model
{
    public const UPDATED_AT  = null;              // append-only
    public const CREATED_AT  = 'acknowledged_at'; // uses acknowledged_at as creation timestamp

    protected $fillable = [
        'agent_id', 'sequence_id', 'batch_id', 'event_count', 'trace_id', 'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public static function latestForAgent(string $agentId): ?self
    {
        return static::where('agent_id', $agentId)
            ->orderByDesc('sequence_id')
            ->first();
    }
}
