<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalIntegration extends Model
{
    protected $fillable = [
        'integration_id', 'integration_type', 'provider', 'name', 'status',
        'config', 'field_mappings', 'inbound_enabled', 'outbound_enabled',
        'sync_advisory_only', 'last_sync_at', 'last_sync_status',
        'sync_error_count', 'last_error',
    ];

    protected $casts = [
        'config'           => 'array',
        'field_mappings'   => 'array',
        'inbound_enabled'  => 'boolean',
        'outbound_enabled' => 'boolean',
        'sync_advisory_only'=> 'boolean',
        'last_sync_at'     => 'datetime',
        'sync_error_count' => 'integer',
    ];

    public const INTEGRATION_TYPES = ['idp', 'saas', 'ticketing', 'notification'];

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ERROR    = 'error';
    public const STATUS_DISABLED = 'disabled';

    public const PROVIDERS_IDP         = ['okta', 'azure_ad', 'ping', 'auth0'];
    public const PROVIDERS_SAAS        = ['office365', 'gsuite', 'salesforce', 'github'];
    public const PROVIDERS_TICKETING   = ['jira', 'servicenow', 'freshservice'];
    public const PROVIDERS_NOTIFICATION= ['slack', 'pagerduty', 'teams', 'webhook'];

    public function syncEvents(): HasMany
    {
        return $this->hasMany(IntegrationSyncEvent::class, 'integration_id');
    }

    public function caseLinks(): HasMany
    {
        return $this->hasMany(ExternalCaseLink::class, 'integration_id');
    }

    public function notificationEvents(): HasMany
    {
        return $this->hasMany(NotificationEvent::class, 'integration_id');
    }

    public function saasAuditEvents(): HasMany
    {
        return $this->hasMany(SaasAuditEvent::class, 'integration_id');
    }

    public function identityProviderEvents(): HasMany
    {
        return $this->hasMany(IdentityProviderEvent::class, 'integration_id');
    }
}
