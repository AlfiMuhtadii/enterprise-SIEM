<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProxyEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const SUSPICIOUS_UA_PATTERNS = [
        'curl/', 'python-requests/', 'go-http-client/', 'wget/', 'libwww-perl/',
        'masscan', 'sqlmap', 'nikto', 'nmap', 'zgrab',
    ];
    public const UNUSUAL_HTTP_METHODS    = ['TRACE', 'TRACK', 'OPTIONS', 'CONNECT', 'PROPFIND'];
    public const DENY_THRESHOLD          = 5;   // repeated 403/401 in window
    public const HIGH_BYTES_OUT_THRESHOLD = 50 * 1024 * 1024; // 50 MiB

    protected $fillable = [
        'event_id', 'agent_id', 'host_id', 'source_ip', 'user',
        'url', 'domain', 'http_method', 'status_code', 'user_agent',
        'bytes_out', 'bytes_in', 'is_denied', 'occurred_at', 'trace_id',
    ];

    protected $casts = [
        'is_denied'  => 'boolean',
        'occurred_at'=> 'datetime',
    ];

    public static function generateEventId(): string
    {
        return 'prx-' . Str::uuid();
    }
}
