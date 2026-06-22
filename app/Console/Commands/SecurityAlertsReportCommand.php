<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityAlertsReportCommand extends Command
{
    protected $signature = 'security:alerts-report
                            {--minutes=15 : Lookback window in minutes}
                            {--demo-run= : Filter to a specific demo_run_id (reads manifest from storage/logs/)}';

    protected $description = 'Show near-real-time alerts summary; use --demo-run=<id> for causal proof';

    public function handle(): int
    {
        $minutes  = max(1, (int) $this->option('minutes'));
        $demoRun  = $this->option('demo-run');

        [$since, $until] = $this->resolveWindow($demoRun, $minutes);

        $query = DB::table('security_alerts')->where('detected_at', '>=', $since);
        if ($until) {
            $query->where('detected_at', '<=', $until);
        }

        // When filtering by demo_run, also try the JSON evidence columns so
        // alerts written by the Go pipeline (which embeds demo_run_id in
        // raw_event / evidence) appear even if they fall slightly outside
        // the manifest time window.
        if ($demoRun) {
            $query->where(function ($q) use ($demoRun, $since, $until) {
                $q->whereBetween('detected_at', [$since, $until ?? now()])
                  ->orWhereRaw("raw_event->>'demo_run_id' = ?", [$demoRun])
                  ->orWhereRaw("evidence->>'demo_run_id' = ?", [$demoRun]);
            });
        }

        $summary = (clone $query)
            ->select('alert_type', DB::raw('count(*) as total'))
            ->groupBy('alert_type')
            ->orderByDesc('total')
            ->get();

        $latest = (clone $query)
            ->select('alert_id', 'detected_at', 'alert_type', 'detector_name', 'severity', 'actor_key', 'score')
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();

        if ($demoRun) {
            $this->info("Demo run   : {$demoRun}");
            $this->line("Window     : {$since} → " . ($until ?? 'now'));
        } else {
            $this->info("Window     : last {$minutes} minutes (since {$since})");
        }

        $this->newLine();
        $this->line('Alert summary:');
        $this->table(
            ['alert_type', 'total'],
            $summary->map(fn($r) => [$r->alert_type, (int) $r->total])->all()
        );

        $this->newLine();
        $this->line('Latest alerts (rule_id = detector_name when using Go engine):');
        $this->table(
            ['alert_id', 'detected_at', 'alert_type', 'rule_id (detector_name)', 'severity', 'actor_key', 'score'],
            $latest->map(fn($r) => [
                $r->alert_id,
                $r->detected_at,
                $r->alert_type,
                $r->detector_name ?? '—',
                $r->severity,
                $r->actor_key ?? '—',
                $r->score ?? '—',
            ])->all()
        );

        if ($demoRun && $latest->isNotEmpty()) {
            $this->newLine();
            $sampleRuleId = $latest->first()->detector_name ?? null;
            if ($sampleRuleId && $sampleRuleId !== 'realtime') {
                $this->info("To inspect the exact rule that fired:");
                $this->line("  python scripts/show_rule.py --rule-id {$sampleRuleId}");
            }
            $this->info("To view the full event trace:");
            $this->line("  http://localhost:8000/traces?demo_run_id={$demoRun}");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the time window.
     *
     * When --demo-run is set, look for storage/logs/<id>-manifest.json.
     * The manifest records started_at/ended_at written by demo_feed.py.
     * Falls back to the --minutes window if no manifest is found.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveWindow(string|null $demoRun, int $minutes): array
    {
        if ($demoRun) {
            $manifestPath = base_path("storage/logs/{$demoRun}-manifest.json");
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
                $startedAt = $manifest['started_at'] ?? null;
                $endedAt   = $manifest['ended_at']   ?? null;
                if ($startedAt) {
                    // Give 3-minute buffer after ended_at for pipeline processing lag.
                    $until = $endedAt
                        ? date('Y-m-d H:i:sP', strtotime($endedAt) + 180)
                        : null;
                    return [$startedAt, $until];
                }
            }
            $this->warn("Manifest not found for '{$demoRun}' — falling back to --minutes={$minutes} window.");
        }

        return [now()->subMinutes($minutes)->toDateTimeString(), null];
    }
}
