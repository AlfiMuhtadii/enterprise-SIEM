<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NOTIFY-TENANCY-GAP: per-tenant notification target configuration.
 *
 * Mutable, tenant-isolated. One row per tenant_id. When no row exists for a
 * tenant (or enabled = false), the resolver falls back to the global config
 * in config/notifications_soc.php — preserving backward-compatible behavior.
 */
class TenantNotificationSetting extends Model
{
    protected $table = 'tenant_notification_settings';

    protected $fillable = [
        'tenant_id',
        'webhook_url',
        'slack_url',
        'discord_url',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
