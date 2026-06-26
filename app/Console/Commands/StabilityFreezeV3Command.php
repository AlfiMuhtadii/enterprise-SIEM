<?php

namespace App\Console\Commands;

use App\Services\StabilityEvidenceFreezeV3Service;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-055: Stability Evidence Freeze v3
 *
 * Aggregates evidence across E045-E054 into a consolidated freeze snapshot.
 * Evaluates 22 gates, 10 phase summaries, allowed/forbidden claims, gap registry.
 * freeze_approved = false always; human sign-off required.
 *
 * Usage:
 *   php artisan stability:freeze-v3
 *   php artisan stability:freeze-v3 --dry-run
 */
class StabilityFreezeV3Command extends Command
{
    protected $signature = 'stability:freeze-v3
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Stability Evidence Freeze v3 — consolidates E045-E054; advisory-only';

    public function handle(StabilityEvidenceFreezeV3Service $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode   = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[FREEZE]</>';

        $this->line('');
        $this->line("{$mode} Stability Evidence Freeze v3 — phases E045-E054");
        $this->line('  ADVISORY-ONLY. freeze_approved = false always.');
        $this->line('');

        $result  = $service->freeze($dryRun);
        $summary = $result['summary'];
        $gates   = $result['gates'];
        $phases  = $result['phases'];
        $claims  = $result['claims'];
        $gaps    = $result['gaps'];

        // Gate table
        $this->table(
            ['Gate ID', 'Status', 'Name', 'Evidence'],
            array_map(fn ($g) => [
                $g['gate_id'],
                strtoupper($g['status']),
                $g['gate_name'],
                $g['evidence'],
            ], $gates),
        );

        $this->line('');

        // Phase summaries
        $this->line('── Phase Summaries (E045-E054) ──────────────────────────');
        foreach ($phases as $phase) {
            $metrics    = is_string($phase['metrics']) ? json_decode($phase['metrics'], true) : $phase['metrics'];
            $metricsStr = implode(', ', array_map(
                fn ($k, $v) => "{$k}=" . (is_bool($v) ? ($v ? 'true' : 'false') : $v),
                array_keys($metrics ?? []),
                array_values($metrics ?? [])
            ));
            $this->line("  [{$phase['enterprise_id']}] {$phase['phase_name']}");
            $this->line("       {$metricsStr}");
        }

        $this->line('');

        // Allowed claims
        $allowed = array_filter($claims, fn ($c) => $c['claim_type'] === 'allowed');
        $this->line('── Allowed Claims ───────────────────────────────────────');
        foreach ($allowed as $c) {
            $this->line("  [OK] {$c['claim_text']}");
        }

        $this->line('');

        // Forbidden claims
        $forbidden = array_filter($claims, fn ($c) => $c['claim_type'] === 'forbidden');
        $this->line('── Forbidden Claims ─────────────────────────────────────');
        foreach ($forbidden as $c) {
            $this->line("  [NO] {$c['claim_text']}");
        }

        $this->line('');

        // Remaining gaps
        $this->line('── Remaining Gaps ───────────────────────────────────────');
        foreach ($gaps as $gap) {
            $sev = strtoupper($gap['severity']);
            $this->line("  [{$sev}] {$gap['gap_id']}: {$gap['description']}");
        }

        $this->line('');

        // Final result
        $this->line('── Freeze Result ────────────────────────────────────────');
        $this->line("  total_gates      : {$summary['total_gates']}");
        $this->line("  gates_passed     : {$summary['gates_passed']}");
        $this->line("  gates_failed     : {$summary['gates_failed']}");
        $this->line("  gates_warn       : {$summary['gates_warn']}");
        $scoreStr = number_format((float) $summary['pass_score'], 3);
        $this->line("  pass_score       : {$scoreStr} (threshold: " . StabilityEvidenceFreezeV3Service::STABLE_SCORE_THRESHOLD . ')');
        $this->line("  total_phases     : {$summary['total_phases']}");
        $this->line("  allowed_claims   : {$summary['allowed_claim_count']}");
        $this->line("  forbidden_claims : {$summary['forbidden_claim_count']}");
        $this->line("  remaining_gaps   : {$summary['gap_count']}");

        $stability = $summary['stability'];
        $color     = $stability === 'STABLE' ? 'fg=green' : 'fg=yellow';
        $this->line("  stability        : <{$color}>{$stability}</>");
        $this->line('  freeze_approved  : false (always)');
        $this->line('');

        if (!$dryRun) {
            $this->info("Freeze persisted (freeze_run_id: {$summary['freeze_run_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
