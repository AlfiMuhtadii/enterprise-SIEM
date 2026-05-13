<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityAlertsReportCommand extends Command
{
    protected $signature = 'security:alerts-report {--minutes=15 : Lookback window}';

    protected $description = 'Show near-real-time alerts summary';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $since = now()->subMinutes($minutes);

        $summary = DB::table('security_alerts')
            ->select('alert_type', DB::raw('count(*) as total'))
            ->where('detected_at', '>=', $since)
            ->groupBy('alert_type')
            ->orderByDesc('total')
            ->get();

        $latest = DB::table('security_alerts')
            ->select('detected_at', 'alert_type', 'severity', 'ip', 'score')
            ->where('detected_at', '>=', $since)
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();

        $this->info("Window: last {$minutes} minutes");
        $this->newLine();
        $this->line('Alert summary:');
        $this->table(['alert_type', 'total'], $summary->map(fn ($r) => [$r->alert_type, (int) $r->total])->all());

        $this->newLine();
        $this->line('Latest alerts:');
        $this->table(
            ['detected_at', 'alert_type', 'severity', 'ip', 'score'],
            $latest->map(fn ($r) => [$r->detected_at, $r->alert_type, $r->severity, $r->ip, $r->score])->all()
        );

        return self::SUCCESS;
    }
}
