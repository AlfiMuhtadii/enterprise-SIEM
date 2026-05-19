<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EndpointStreamCheckpoint extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'checkpoint_id', 'agent_id', 'sequence_id', 'batch_id',
        'event_count', 'stream_lag_seconds', 'dropped_event_count', 'trace_id',
    ];

    public static function generateCheckpointId(): string
    {
        return 'chk-' . Str::uuid();
    }

    public static function latestForAgent(string $agentId): ?self
    {
        return static::where('agent_id', $agentId)
            ->orderByDesc('sequence_id')
            ->first();
    }
}
