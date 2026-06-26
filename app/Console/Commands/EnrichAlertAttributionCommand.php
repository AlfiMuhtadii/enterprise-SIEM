<?php

namespace App\Console\Commands;

use App\Services\AlertAttributionService;
use Illuminate\Console\Command;

class EnrichAlertAttributionCommand extends Command
{
    protected $signature = 'alerts:enrich-attribution
                            {--minutes=60 : Enrich alerts detected within this many minutes}
                            {--dry-run : Report count without writing}';

    protected $description = 'Enrich recent security_alerts with offline OSINT attribution context (ATTR-002, advisory-only)';

    public function handle(AlertAttributionService $attribution): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun  = $this->option('dry-run');

        if ($dryRun) {
            $count = \Illuminate\Support\Facades\DB::table('security_alerts')
                ->where('detected_at', '>=', now()->subMinutes($minutes))
                ->whereNotIn('alert_id', function ($q) {
                    $q->select('alert_id')->from('alert_attribution_context');
                })
                ->count();
            $this->info("alerts:enrich-attribution would enrich {$count} alerts (dry-run).");
            return self::SUCCESS;
        }

        $created = $attribution->enrichRecentAlerts($minutes);
        $this->info("alerts:enrich-attribution enriched {$created} alerts.");

        return self::SUCCESS;
    }
}
