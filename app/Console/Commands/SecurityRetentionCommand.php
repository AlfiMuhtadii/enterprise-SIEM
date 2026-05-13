<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityRetentionCommand extends Command
{
    protected $signature = 'security:retention
                            {--events-days=30 : Keep raw security_events for N days}
                            {--alerts-days=90 : Keep security_alerts for N days}';

    protected $description = 'Apply TTL-style retention to security_events';

    public function handle(): int
    {
        $eventsDays = max(1, (int) $this->option('events-days'));
        $alertsDays = max(1, (int) $this->option('alerts-days'));
        $eventsCutoff = now()->subDays($eventsDays);
        $alertsCutoff = now()->subDays($alertsDays);

        $deletedEvents = DB::table('security_events')
            ->where('ts', '<', $eventsCutoff)
            ->delete();

        $deletedAlerts = DB::table('security_alerts')
            ->where('detected_at', '<', $alertsCutoff)
            ->delete();

        $this->info("Deleted security_events: {$deletedEvents} rows older than {$eventsCutoff->toIso8601String()}");
        $this->info("Deleted security_alerts: {$deletedAlerts} rows older than {$alertsCutoff->toIso8601String()}");

        return self::SUCCESS;
    }
}
