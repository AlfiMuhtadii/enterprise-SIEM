<?php

namespace App\Console\Commands;

use App\Services\SocNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SocNotifyCriticalCommand extends Command
{
    protected $signature = 'soc:notify-critical {--minutes=60}';

    protected $description = 'Send notifications for recent critical incidents.';

    public function handle(SocNotifier $notifier): int
    {
        $since = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $incidents = DB::table('security_incidents')
            ->where('severity', 'critical')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        foreach ($incidents as $incident) {
            $payload = [
                'message' => "Critical incident: {$incident->incident_id} - {$incident->title}",
                'incident_id' => $incident->incident_id,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'last_seen_at' => $incident->last_seen_at,
            ];
            $notifier->send('webhook', config('notifications_soc.webhook_url'), 'critical_incident', $incident->incident_id, $payload);
            $notifier->send('slack', config('notifications_soc.slack_url'), 'critical_incident', $incident->incident_id, $payload);
            $notifier->send('discord', config('notifications_soc.discord_url'), 'critical_incident', $incident->incident_id, $payload);
        }

        $this->info("critical_incidents={$incidents->count()}");

        return self::SUCCESS;
    }
}
