<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityProviderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'integration_id', 'provider', 'event_type', 'user_id',
        'user_email', 'source_ip', 'geo_country', 'user_agent', 'mfa_used',
        'is_failed', 'failed_attempt_count', 'is_suspicious', 'risk_score',
        'raw_attributes', 'trace_id', 'occurred_at',
    ];

    protected $casts = [
        'raw_attributes'      => 'array',
        'mfa_used'            => 'boolean',
        'is_failed'           => 'boolean',
        'is_suspicious'       => 'boolean',
        'risk_score'          => 'float',
        'failed_attempt_count'=> 'integer',
        'occurred_at'         => 'datetime',
    ];

    public const PROVIDERS = ['okta', 'azure_ad', 'ping', 'auth0'];

    public const EVENT_TYPES = [
        'login_success', 'login_failure', 'mfa_challenge', 'mfa_failure',
        'password_reset', 'account_locked', 'account_unlocked',
        'session_revoked', 'privilege_escalation', 'token_issued',
    ];

    public const BRUTE_FORCE_THRESHOLD = 5;
    public const IMPOSSIBLE_TRAVEL_FLAG = 'impossible_travel';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class, 'integration_id');
    }
}
