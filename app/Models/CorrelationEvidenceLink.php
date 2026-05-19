<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrelationEvidenceLink extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_SECURITY_ALERT    = 'security_alert';
    public const TYPE_ENDPOINT_FINDING  = 'endpoint_finding';
    public const TYPE_HUNT_RESULT       = 'hunt_result';
    public const TYPE_ENTITY_OBSERVATION= 'entity_observation';
    public const TYPE_PROCESS_ENTRY     = 'process_entry';

    public const DOMAIN_IDENTITY = 'identity';
    public const DOMAIN_CLOUD    = 'cloud';
    public const DOMAIN_SAAS     = 'saas';
    public const DOMAIN_ENDPOINT = 'endpoint';

    protected $fillable = [
        'correlation_id', 'evidence_type', 'evidence_id',
        'evidence_table', 'evidence_snapshot', 'domain',
    ];

    protected $casts = [
        'evidence_snapshot' => 'array',
        'created_at'        => 'datetime',
    ];

    public function correlation(): BelongsTo
    {
        return $this->belongsTo(CrossDomainCorrelation::class, 'correlation_id');
    }
}
