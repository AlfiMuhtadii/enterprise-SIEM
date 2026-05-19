<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'notification_id', 'integration_id', 'notification_type', 'severity',
        'channel', 'subject', 'body', 'target_metadata', 'source_reference',
        'requires_analyst_approval', 'trace_id', 'created_by',
    ];

    protected $casts = [
        'target_metadata'           => 'array',
        'source_reference'          => 'array',
        'requires_analyst_approval' => 'boolean',
    ];

    public const NOTIFICATION_TYPES = [
        'alert_fired', 'incident_created', 'escalation', 'sla_breach', 'hunt_completed',
    ];
    public const CHANNELS = ['slack', 'webhook', 'pagerduty', 'email', 'teams'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class, 'integration_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_event_id');
    }
}
