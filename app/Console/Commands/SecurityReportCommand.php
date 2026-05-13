<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityReportCommand extends Command
{
    protected $signature = 'security:report {--minutes=15 : Time window in minutes}';

    protected $description = 'Show top IPs, failed logins per IP, and 404 spikes';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $since = now()->subMinutes($minutes);

        $this->info("Window: last {$minutes} minutes");

        $topIps = DB::table('security_events')
            ->select('ip', DB::raw('count(*) as total'))
            ->where('ts', '>=', $since)
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $failedByIp = DB::table('security_events')
            ->select('ip', DB::raw('count(*) as failed_logins'))
            ->where('ts', '>=', $since)
            ->where('event_type', '=', 'auth_login_failed')
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->orderByDesc('failed_logins')
            ->limit(10)
            ->get();

        $notFoundByMinute = DB::table('security_events')
            ->selectRaw("date_trunc('minute', ts) as minute, count(*) as count_404")
            ->where('ts', '>=', $since)
            ->where('status', '=', 404)
            ->groupByRaw("date_trunc('minute', ts)")
            ->orderByRaw("date_trunc('minute', ts) desc")
            ->limit(20)
            ->get();

        $this->newLine();
        $this->line('Top IPs (all events):');
        $this->table(['ip', 'total'], $topIps->map(fn ($r) => [(string) $r->ip, (int) $r->total])->all());

        $this->newLine();
        $this->line('Failed logins per IP:');
        $this->table(['ip', 'failed_logins'], $failedByIp->map(fn ($r) => [(string) $r->ip, (int) $r->failed_logins])->all());

        $this->newLine();
        $this->line('404 spikes by minute:');
        $this->table(
            ['minute', 'count_404'],
            $notFoundByMinute->map(fn ($r) => [(string) $r->minute, (int) $r->count_404])->all()
        );

        return self::SUCCESS;
    }
}
