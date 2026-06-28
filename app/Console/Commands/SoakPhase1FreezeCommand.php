<?php

namespace App\Console\Commands;

use App\Services\Phase1SoakEvidenceFreezeService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-064: Phase 1 Soak Evidence Freeze
 *
 * Creates an immutable freeze record of the P1G-01..P1G-08 evidence chain.
 *
 * Usage:
 *   php artisan soak:phase1-freeze
 *   php artisan soak:phase1-freeze --dry-run
 */
class SoakPhase1FreezeCommand extends Command
{
    protected $signature = 'soak:phase1-freeze
                            {--dry-run : Evaluate gates without persisting to DB}';

    protected $description = 'ENTERPRISE-064: Freeze Phase 1 soak evidence — immutable snapshot of P1G-01..P1G-08 PASS state';

    public function handle(Phase1SoakEvidenceFreezeService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode   = $dryRun ? '[DRY-RUN]' : '[FREEZE]';

        $this->line('');
        $this->line("{$mode} ENTERPRISE-064 Phase 1 Soak Evidence Freeze");
        $this->line('  ADVISORY_ONLY  = true');
        $this->line('  NO_PROMOTION   = true');
        $this->line('  FREEZE_APPROVED = false (always)');
        $this->line('');

        $result  = $service->freeze($dryRun);
        $summary = $result['summary'];
        $gates   = $result['gates'];

        $this->table(
            ['Gate ID', 'Status', 'Adv', 'Name', 'Evidence'],
            array_map(fn ($g) => [
                $g['gate_id'],
                strtoupper($g['status']),
                $g['is_advisory'] ? 'yes' : 'no',
                $g['gate_name'],
                $g['evidence'],
            ], $gates),
        );

        $this->line('');
        $scoreStr = number_format((float) $summary['pass_score'], 3);
        $this->line("  pass_score      : {$scoreStr}");
        $this->line("  gates_total     : {$summary['gates_total']}");
        $this->line("  gates_passed    : {$summary['gates_passed']}");
        $this->line("  gates_warned    : {$summary['gates_warned']}");
        $this->line("  gates_failed    : {$summary['gates_failed']}");
        $this->line("  verdict         : {$summary['verdict']}");
        $this->line('  freeze_approved : false (always)');
        $this->line('  no_promotion    : true (always)');
        $this->line('');

        if ($dryRun) {
            $this->warn('Dry-run: no rows written to phase1_soak_freeze_runs.');
        } else {
            $this->info("Freeze persisted (freeze_run_id: {$summary['freeze_run_id']}).");
        }

        return self::SUCCESS;
    }
}
