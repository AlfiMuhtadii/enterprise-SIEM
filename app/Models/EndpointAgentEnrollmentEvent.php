<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: enrollment lifecycle events for each agent.
 * Never updated after insert.
 */
class EndpointAgentEnrollmentEvent extends Model
{
    public $timestamps = false;

    public const EVENT_ENROLLED        = 'enrolled';
    public const EVENT_RE_ENROLLED     = 're_enrolled';
    public const EVENT_REVOKED         = 'revoked';
    public const EVENT_TOKEN_REFRESHED = 'token_refreshed';
    public const EVENT_VERSION_UPDATED = 'version_updated';
    public const EVENT_FAILED          = 'failed';

    public const EVENT_TYPES = [
        self::EVENT_ENROLLED,
        self::EVENT_RE_ENROLLED,
        self::EVENT_REVOKED,
        self::EVENT_TOKEN_REFRESHED,
        self::EVENT_VERSION_UPDATED,
        self::EVENT_FAILED,
    ];

    protected $table = 'endpoint_agent_enrollment_events';

    protected $fillable = [
        'event_id', 'agent_id', 'event_type', 'agent_version',
        'platform', 'ip_address', 'enrollment_token_hash',
        'metadata', 'failure_reason', 'trace_id', 'successful',
        'occurred_at', 'triggered_by', 'created_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'successful'  => 'boolean',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
