<?php

namespace App\Console\Commands;

use App\Services\ConfidenceSourceRefreshService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-058: Confidence Source Refresh
 *
 * Usage:
 *   php artisan rule:refresh-confidence
 *   php artisan rule:refresh-confidence --dry-run
 */
class RefreshConfidenceSourceCommand extends Command
{
    protected $signature = 'rule:refresh-confidence
                            {--dry-run : Re-derive without persisting}';

    protected $description = 'Refresh confidence_source labels across all rules — E058; advisory-only';

    public function handle(ConfidenceSourceRefreshService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode   = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[REFRESH]</>';

        $this->line('');
        $this->line("{$mode} Confidence Source Refresh — all rules");
        $this->line('  ADVISORY-ONLY. Updates rule_fixture_backlogs.confidence_source.');
        $this->line('');

        $result = $service->refresh($dryRun);
        $run    = $result['run'];

        $this->line("  total_rules    : {$run['total_rules']}");
        $this->line("  empirical      : {$run['empirical_count']}");
        $this->line("  fixture_tested : {$run['fixture_tested_count']}");
        $this->line("  manual         : {$run['manual_count']}");
        $this->line("  changed        : {$run['changed_count']}");
        $empiricalPct = number_format((float) $run['empirical_rate'] * 100, 1);
        $this->line("  empirical_rate : {$empiricalPct}%");
        $this->line('');

        if ($run['empirical_count'] > 0) {
            $this->info("{$run['empirical_count']} rule(s) now labelled empirical.");
        }

        if (!$dryRun) {
            $this->info("Refresh persisted (refresh_run_id: {$run['refresh_run_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
