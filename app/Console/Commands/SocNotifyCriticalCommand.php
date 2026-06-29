<?php

namespace App\Console\Commands;

use App\Services\SocNotifier;
use App\Services\TenantNotificationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SocNotifyCriticalCommand extends Command
{
    protected $signature = 'soc:notify-critical {--minutes=60}';

    protected $description = 'Send notifications for recent critical incidents.';

    public function handle(SocNotifier $notifier, TenantNotificationResolver $notificationResolver): int
    {
        $since = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $incidents = DB::table('security_incidents')
            ->where('severity', 'critical')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        foreach ($incidents as $incident) {
            // NOTIFY-TENANCY-GAP: route to the incident's tenant-specific targets;
            // null/unconfigured tenant falls back to global config.
            $tenantId = $incident->tenant_id ?? null;
            $targets = $notificationResolver->resolve($tenantId);

            $payload = [
                'message' => "Critical incident: {$incident->incident_id} - {$incident->title}",
                'incident_id' => $incident->incident_id,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'last_seen_at' => $incident->last_seen_at,
            ];
            $notifier->send('webhook', $targets['webhook'], 'critical_incident', $incident->incident_id, $payload, $tenantId);
            $notifier->send('slack', $targets['slack'], 'critical_incident', $incident->incident_id, $payload, $tenantId);
            $notifier->send('discord', $targets['discord'], 'critical_incident', $incident->incident_id, $payload, $tenantId);
        }

        $this->info("critical_incidents={$incidents->count()}");

        return self::SUCCESS;
    }
}
