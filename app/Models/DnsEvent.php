<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DnsEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const QUERY_TYPES_SUSPICIOUS = ['TXT', 'NULL', 'ANY'];
    public const TLDS_SUSPICIOUS = ['.ru', '.cn', '.tk', '.ml', '.ga', '.cf', '.gq', '.pw', '.xyz', '.top', '.click', '.loan', '.bid', '.win', '.review', '.science', '.work'];
    public const ENTROPY_THRESHOLD_HIGH = 3.5;
    public const NXDOMAIN_BURST_THRESHOLD = 5;

    protected $fillable = [
        'event_id', 'agent_id', 'host_id', 'source_ip', 'user',
        'queried_domain', 'tld', 'query_type', 'response_code',
        'is_nxdomain', 'resolved_ips', 'domain_entropy', 'occurred_at', 'trace_id',
    ];

    protected $casts = [
        'is_nxdomain'     => 'boolean',
        'resolved_ips'    => 'array',
        'domain_entropy'  => 'float',
        'occurred_at'     => 'datetime',
    ];

    public static function generateEventId(): string
    {
        return 'dns-' . Str::uuid();
    }

    public static function calculateEntropy(string $domain): float
    {
        $label   = explode('.', $domain)[0] ?? $domain;
        $len     = strlen($label);
        if ($len === 0) {
            return 0.0;
        }
        $freq = array_count_values(str_split(strtolower($label)));
        $entropy = 0.0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        return round($entropy, 3);
    }
}
