<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FirewallEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const ACTION_ALLOW = 'allow';
    public const ACTION_DENY  = 'deny';
    public const ACTION_DROP  = 'drop';

    public const UNUSUAL_PORTS    = [4444, 1337, 31337, 6666, 9999, 8888, 4321];
    public const DENY_THRESHOLD   = 5;
    public const HIGH_EGRESS_BYTES = 100 * 1024 * 1024; // 100 MiB

    protected $fillable = [
        'event_id', 'agent_id', 'host_id', 'source_ip', 'destination_ip',
        'destination_port', 'protocol', 'action', 'is_deny',
        'bytes_out', 'bytes_in', 'rule_name', 'user', 'host_id_src',
        'occurred_at', 'trace_id',
    ];

    protected $casts = [
        'is_deny'      => 'boolean',
        'occurred_at'  => 'datetime',
    ];

    public static function generateEventId(): string
    {
        return 'fw-' . Str::uuid();
    }
}
