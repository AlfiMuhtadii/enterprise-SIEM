<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'integration_id', 'provider', 'actor_id', 'actor_email',
        'actor_ip', 'action', 'resource_type', 'resource_id', 'target_id',
        'target_email', 'additional_details', 'is_suspicious', 'risk_score',
        'source_country', 'user_agent', 'trace_id', 'occurred_at',
    ];

    protected $casts = [
        'additional_details' => 'array',
        'is_suspicious'      => 'boolean',
        'risk_score'         => 'float',
        'occurred_at'        => 'datetime',
    ];

    public const PROVIDERS = ['office365', 'gsuite', 'salesforce', 'github'];

    public const SUSPICIOUS_ACTIONS = [
        'bulk_download', 'permission_escalation', 'admin_account_created',
        'mfa_disabled', 'audit_log_cleared', 'mass_delete',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class, 'integration_id');
    }
}
