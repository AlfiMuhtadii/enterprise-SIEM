<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointProcessEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_id', 'agent_id', 'pid', 'ppid',
        'process_name', 'parent_process_name', 'executable_path', 'command_line',
        'user', 'session_id',
        'first_seen_at', 'last_seen_at', 'duration_seconds',
        'is_shell', 'is_long_lived', 'is_suspicious',
        'trace_id',
    ];

    protected $casts = [
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
        'is_shell'       => 'boolean',
        'is_long_lived'  => 'boolean',
        'is_suspicious'  => 'boolean',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EndpointProcessSnapshot::class, 'snapshot_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
