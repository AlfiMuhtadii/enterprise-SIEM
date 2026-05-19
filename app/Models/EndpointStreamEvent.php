<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EndpointStreamEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    // Streaming event types
    public const TYPE_PROCESS_STARTED            = 'process_started';
    public const TYPE_PROCESS_TERMINATED         = 'process_terminated';
    public const TYPE_OUTBOUND_CONNECTION_OPENED = 'outbound_connection_opened';
    public const TYPE_PERSISTENCE_ITEM_CREATED   = 'persistence_item_created';
    public const TYPE_PERSISTENCE_ITEM_MODIFIED  = 'persistence_item_modified';
    public const TYPE_SHELL_EXECUTION_DETECTED   = 'shell_execution_detected';
    public const TYPE_RESPONSE_EXECUTION_ACK     = 'response_execution_ack';
    public const TYPE_RESPONSE_EXECUTION_ROLLBACK= 'response_execution_rollback';

    public const EVENT_TYPES = [
        self::TYPE_PROCESS_STARTED,
        self::TYPE_PROCESS_TERMINATED,
        self::TYPE_OUTBOUND_CONNECTION_OPENED,
        self::TYPE_PERSISTENCE_ITEM_CREATED,
        self::TYPE_PERSISTENCE_ITEM_MODIFIED,
        self::TYPE_SHELL_EXECUTION_DETECTED,
        self::TYPE_RESPONSE_EXECUTION_ACK,
        self::TYPE_RESPONSE_EXECUTION_ROLLBACK,
    ];

    public const STREAM_WINDOW_SECONDS = 300;  // 5-minute rapid detection window
    public const BURST_THRESHOLD_COUNT = 10;   // events in window = burst

    protected $fillable = [
        'event_id', 'agent_id', 'host_id', 'event_type', 'sequence_id', 'batch_id',
        'occurred_at', 'process_pid', 'process_name', 'process_path', 'process_cmdline',
        'parent_pid', 'parent_name', 'process_uid',
        'connection_dest', 'connection_port', 'connection_proto',
        'persistence_key', 'persistence_type', 'persistence_path',
        'shell_hint', 'response_exec_id', 'trace_id', 'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata'    => 'array',
    ];

    public static function generateEventId(): string
    {
        return 'sev-' . Str::uuid();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id', 'agent_id');
    }
}
