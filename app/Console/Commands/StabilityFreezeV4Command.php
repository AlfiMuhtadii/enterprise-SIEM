<?php

namespace App\Console\Commands;

use App\Services\StabilityEvidenceFreezeV4Service;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-059: Stability Evidence Freeze v4
 *
 * Usage:
 *   php artisan stability:freeze-v4
 *   php artisan stability:freeze-v4 --dry-run
 */
class StabilityFreezeV4Command extends Command
{
    protected $signature = 'stability:freeze-v4
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Stability Evidence Freeze v4 — covers E055-E058 delta; advisory-only';

    public function handle(StabilityEvidenceFreezeV4Service $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode   = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[FREEZE]</>';

        $this->line('');
        $this->line("{$mode} Stability Evidence Freeze v4 — phases E055-E058");
        $this->line('  ADVISORY-ONLY. freeze_approved = false always.');
        $this->line('');

        $result  = $service->freeze($dryRun);
        $summary = $result['summary'];
        $gates   = $result['gates'];
        $phases  = $result['phases'];
        $claims  = $result['claims'];
        $gaps    = $result['gaps'];

        $this->table(
            ['Gate ID', 'Status', 'Name', 'Evidence'],
            array_map(fn ($g) => [
                $g['gate_id'], strtoupper($g['status']), $g['gate_name'], $g['evidence'],
            ], $gates),
        );

        $this->line('');
        $this->line('── Phase Summaries (E055-E058) ──────────────────────────');
        foreach ($phases as $phase) {
            $m = is_string($phase['metrics']) ? json_decode($phase['metrics'], true) : $phase['metrics'];
            $mStr = implode(', ', array_map(
                fn ($k, $v) => "{$k}=" . (is_bool($v) ? ($v ? 'true' : 'false') : $v),
                array_keys($m ?? []), array_values($m ?? [])
            ));
            $this->line("  [{$phase['enterprise_id']}] {$mStr}");
        }

        $this->line('');
        $this->line('── Allowed Claims ───────────────────────────────────────');
        foreach (array_filter($claims, fn ($c) => $c['claim_type'] === 'allowed') as $c) {
            $this->line("  [OK] {$c['claim_text']}");
        }

        $this->line('');
        $this->line('── Remaining Gaps ───────────────────────────────────────');
        foreach ($gaps as $gap) {
            $sev = strtoupper($gap['severity']);
            $this->line("  [{$sev}] {$gap['gap_id']}: {$gap['description']}");
        }

        $this->line('');
        $scoreStr = number_format((float) $summary['pass_score'], 3);
        $this->line("  pass_score    : {$scoreStr}  stability: {$summary['stability']}");
        $this->line("  total_gates   : {$summary['total_gates']}  passed: {$summary['gates_passed']}  failed: {$summary['gates_failed']}");
        $this->line('  freeze_approved: false (always)');
        $this->line('');

        if (!$dryRun) {
            $this->info("Freeze persisted (freeze_run_id: {$summary['freeze_run_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
