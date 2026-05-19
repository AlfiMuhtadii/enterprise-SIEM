<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NetworkBehavioralFinding extends Model
{
    public const UPDATED_AT = null; // append-only

    public const SOURCE_DNS      = 'dns';
    public const SOURCE_PROXY    = 'proxy';
    public const SOURCE_FIREWALL = 'firewall';

    public const FINDING_TYPES = [
        'suspicious_dns_tld',
        'dns_txt_query_pattern',
        'high_entropy_domain',
        'repeated_nxdomain',
        'rare_domain_contact',
        'suspicious_user_agent',
        'unusual_http_method',
        'repeated_403_401',
        'high_bytes_out',
        'rare_domain_proxy_access',
        'repeated_denied_outbound',
        'unusual_destination_port',
        'rare_external_destination',
        'high_volume_egress',
        'suspicious_allow_after_deny',
    ];

    protected $fillable = [
        'finding_id', 'finding_type', 'source_domain', 'host_id', 'agent_id',
        'user', 'target_domain', 'target_ip', 'target_port',
        'severity', 'confidence', 'advisory_only', 'evidence', 'trace_id',
    ];

    protected $casts = [
        'advisory_only' => 'boolean',
        'evidence'      => 'array',
        'confidence'    => 'float',
    ];

    public static function generateFindingId(): string
    {
        return 'nfnd-' . Str::uuid();
    }
}
