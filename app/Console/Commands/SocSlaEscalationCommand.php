<?php

namespace App\Console\Commands;

use App\Services\SocNotifier;
use App\Services\TenantNotificationResolver;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SocSlaEscalationCommand extends Command
{
    protected $signature = 'soc:sla-escalate {--notify=1}';

    protected $description = 'Detect overdue incidents, escalate them, and send configured SOC notifications.';

    public function handle(SocNotifier $notifier, TenantNotificationResolver $notificationResolver): int
    {
        $openStatuses = ['open', 'triaged', 'investigating'];
        $incidents = DB::table('security_incidents')
            ->whereIn('status', $openStatuses)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->orderBy('sla_due_at')
            ->limit(200)
            ->get();

        $escalated = 0;
        foreach ($incidents as $incident) {
            $before = $incident;
            $newLevel = ((int) $incident->escalation_level) + 1;
            DB::table('security_incidents')->where('incident_id', $incident->incident_id)->update([
                'escalation_level' => $newLevel,
                'status' => $incident->status === 'open' ? 'triaged' : $incident->status,
                'updated_at' => now(),
            ]);

            DB::table('security_incident_activities')->insert([
                'incident_id' => $incident->incident_id,
                'actor' => 'automation',
                'activity_type' => 'sla_escalation',
                'before_state' => json_encode($before),
                'after_state' => json_encode(['escalation_level' => $newLevel]),
                'metadata' => json_encode(['sla_due_at' => $incident->sla_due_at, 'source' => 'soc:sla-escalate']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLogger::log('automation', 'incident.sla_escalated', 'incident', $incident->incident_id, $before, ['escalation_level' => $newLevel]);

            if ((int) $this->option('notify') === 1) {
                // NOTIFY-TENANCY-GAP: route to the incident's tenant-specific
                // targets; a null tenant_id or unconfigured tenant falls back to
                // the global config (backward-compatible demo behavior).
                $tenantId = $incident->tenant_id ?? null;
                $targets = $notificationResolver->resolve($tenantId);

                $payload = [
                    'message' => "SLA breach: {$incident->incident_id} ({$incident->severity})",
                    'incident_id' => $incident->incident_id,
                    'severity' => $incident->severity,
                    'sla_due_at' => $incident->sla_due_at,
                    'escalation_level' => $newLevel,
                ];
                $notifier->send('webhook', $targets['webhook'], 'sla_breach', $incident->incident_id, $payload, $tenantId);
                $notifier->send('slack', $targets['slack'], 'sla_breach', $incident->incident_id, $payload, $tenantId);
                $notifier->send('discord', $targets['discord'], 'sla_breach', $incident->incident_id, $payload, $tenantId);
            }
            $escalated++;
        }

        $this->info("overdue={$incidents->count()}");
        $this->info("escalated={$escalated}");

        return self::SUCCESS;
    }
}
