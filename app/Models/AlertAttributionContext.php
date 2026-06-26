<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: no UPDATE/DELETE permitted per CLAUDE.md forbidden changes.
 * Advisory OSINT enrichment per security alert.
 */
class AlertAttributionContext extends Model
{
    public $timestamps = false;

    protected $table = 'alert_attribution_context';

    protected $fillable = [
        'attribution_id',
        'alert_id',
        'alert_type',
        'ip',
        'country_code',
        'country_name',
        'asn',
        'asn_org',
        'ip_type',
        'ip_reputation_hint',
        'threat_actor_hint',
        'confidence',
        'enrichment_source',
        'is_advisory',
        'tenant_id',
    ];

    protected $casts = [
        'confidence'   => 'float',
        'is_advisory'  => 'boolean',
        'created_at'   => 'datetime',
    ];
}
