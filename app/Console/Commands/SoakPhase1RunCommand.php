<?php

namespace App\Console\Commands;

use App\Services\Phase1SoakExecutionService;
use Illuminate\Console\Command;

class SoakPhase1RunCommand extends Command
{
    protected $signature = 'soak:phase1-run
                            {--duration=30 : Planned soak duration in minutes (30–60)}
                            {--dry-run     : Check gates without persisting evidence}
                            {--warm-up     : Run rule:run-fixtures and rule:refresh-confidence before gate check}';

    protected $description = 'ENTERPRISE-061: Run Phase 1 pre-soak gate checks for staged-active empirical rules';

    public function handle(Phase1SoakExecutionService $service): int
    {
        $duration = (int) $this->option('duration');
        $dryRun   = (bool) $this->option('dry-run');
        $warmUp   = (bool) $this->option('warm-up');

        $this->info('ENTERPRISE-061 Phase 1 Soak Gate Check');
        $this->line('Scope    : ' . Phase1SoakExecutionService::SCOPE);
        $this->line('Duration : ' . $duration . ' min');
        $this->line('Dry-run  : ' . ($dryRun ? 'yes (no persistence)' : 'no (evidence persisted)'));
        $this->line('');

        if ($warmUp) {
            $this->line('[WARM-UP] Seeding fixture confidence evidence...');
            $this->call('rule:run-fixtures');
            $this->call('rule:refresh-confidence');
            $this->line('');
        }

        $result   = $service->buildRun($dryRun, $duration);
        $decision = $result['plan']['decision'];

        foreach ($result['gates'] as $gate) {
            $mark = match ($gate['status']) {
                'pass'  => '[PASS]',
                'warn'  => '[WARN]',
                default => '[FAIL]',
            };
            $this->line("  {$mark} [{$gate['gate_id']}] {$gate['gate_name']}");
            $this->line("         {$gate['evidence']}");
        }

        $this->line('');
        $this->line("Decision      : {$decision}");
        $this->line('NO_PROMOTION  : true — no promotion authorized from this run');

        if (!$warmUp && !$dryRun) {
            $this->line('Tip: re-run with --warm-up to auto-seed fixture confidence evidence (P1G-04).');
        }

        if ($dryRun) {
            $this->warn('Dry-run: results not persisted.');
        } else {
            $this->info('Evidence persisted to phase1_soak_runs.');
        }

        return $decision === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }
}
