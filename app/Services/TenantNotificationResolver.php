<?php

namespace App\Services;

use App\Models\TenantNotificationSetting;

/**
 * NOTIFY-TENANCY-GAP: resolve the notification target URLs for a given tenant.
 *
 * Resolution rules (advisory-only, backward compatible):
 * - tenantId === null  → global config targets (legacy/demo single-tenant path).
 * - tenant has an enabled settings row → that tenant's configured URLs.
 *   A null URL for a specific channel falls back to the global config for that
 *   channel ONLY when the tenant row does not define it, so a tenant can opt a
 *   single channel in without inheriting the others.
 * - tenant has a row with enabled = false → notifications suppressed for that
 *   tenant (all channels resolve to null); the global channels are NOT used,
 *   because an explicit disabled row is an intentional opt-out.
 * - tenant has no row → global config targets (backward compatible).
 *
 * This service performs NO delivery and NO mutation. It only resolves targets.
 */
class TenantNotificationResolver
{
    public const CHANNELS = ['webhook', 'slack', 'discord'];

    /**
     * @return array{webhook: ?string, slack: ?string, discord: ?string, source: string, enabled: bool}
     */
    public function resolve(?string $tenantId): array
    {
        $global = [
            'webhook' => config('notifications_soc.webhook_url'),
            'slack'   => config('notifications_soc.slack_url'),
            'discord' => config('notifications_soc.discord_url'),
        ];

        if ($tenantId === null) {
            return $global + ['source' => 'global', 'enabled' => true];
        }

        $setting = TenantNotificationSetting::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if ($setting === null) {
            return $global + ['source' => 'global_fallback', 'enabled' => true];
        }

        if (! $setting->enabled) {
            return [
                'webhook' => null,
                'slack'   => null,
                'discord' => null,
                'source'  => 'tenant_disabled',
                'enabled' => false,
            ];
        }

        return [
            'webhook' => $setting->webhook_url ?? $global['webhook'],
            'slack'   => $setting->slack_url   ?? $global['slack'],
            'discord' => $setting->discord_url ?? $global['discord'],
            'source'  => 'tenant',
            'enabled' => true,
        ];
    }

    /**
     * Resolve the URL for a single channel.
     */
    public function urlFor(?string $tenantId, string $channel): ?string
    {
        $resolved = $this->resolve($tenantId);

        return $resolved[$channel] ?? null;
    }
}
