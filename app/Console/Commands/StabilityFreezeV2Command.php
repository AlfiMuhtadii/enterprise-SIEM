<?php

namespace App\Console\Commands;

use App\Services\StabilityEvidenceFreezeV2Service;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-049: Stability Evidence Freeze v2
 *
 * Usage:
 *   php artisan stability:freeze-v2
 *   php artisan stability:freeze-v2 --dry-run
 *
 * Evaluates 12 gates across E045-E048 and produces a freeze snapshot.
 * freeze_approved = false always; human sign-off required.
 */
class StabilityFreezeV2Command extends Command
{
    protected $signature = 'stability:freeze-v2
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Stability Evidence Freeze v2 — aggregates E045-E048 evidence; advisory-only';

    public function handle(StabilityEvidenceFreezeV2Service $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $mode = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[FREEZE]</>';
        $this->line('');
        $this->line("{$mode} Stability Evidence Freeze v2 — phases E045-E048");
        $this->line('  ADVISORY-ONLY. freeze_approved = false always.');
        $this->line('');

        $result  = $service->freeze($dryRun);
        $summary = $result['summary'];
        $gates   = $result['gates'];
        $phases  = $result['phases'];

        // Gate table
        $this->table(
            ['Gate ID', 'Status', 'Gate Name', 'Evidence'],
            array_map(fn ($g) => [
                $g['gate_id'],
                strtoupper($g['status']),
                $g['gate_name'],
                $g['evidence'],
            ], $gates),
        );

        $this->line('');

        // Phase summary
        $this->line('── Phase Summaries ──────────────────────────────────────');
        foreach ($phases as $phase) {
            $metrics = is_string($phase['metrics']) ? json_decode($phase['metrics'], true) : $phase['metrics'];
            $metricsStr = implode(', ', array_map(
                fn ($k, $v) => "{$k}=" . (is_bool($v) ? ($v ? 'true' : 'false') : $v),
                array_keys($metrics ?? []),
                array_values($metrics ?? [])
            ));
            $this->line("  [{$phase['enterprise_id']}] {$phase['phase_name']}");
            $this->line("       metrics: {$metricsStr}");
        }

        $this->line('');
        $this->line('── Freeze Result ────────────────────────────────────────');
        $this->line("  total_gates    : {$summary['total_gates']}");
        $this->line("  gates_passed   : {$summary['gates_passed']}");
        $this->line("  gates_failed   : {$summary['gates_failed']}");
        $this->line("  gates_warn     : {$summary['gates_warn']}");
        $scoreStr = number_format((float) $summary['pass_score'], 3);
        $this->line("  pass_score     : {$scoreStr} (threshold: " . StabilityEvidenceFreezeV2Service::STABLE_SCORE_THRESHOLD . ")");
        $stability = $summary['stability'];
        $color = $stability === 'STABLE' ? 'fg=green' : 'fg=yellow';
        $this->line("  stability      : <{$color}>{$stability}</>");
        $this->line("  freeze_approved: false (always)");
        $this->line('');

        if (!$dryRun) {
            $this->info("Freeze persisted (freeze_run_id: {$summary['freeze_run_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
