<?php

namespace App\Console\Commands;

use App\Services\DetectionReplayFixtureService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-056: Detection Replay Fixture Batch 1
 *
 * Usage:
 *   php artisan rule:run-fixtures
 *   php artisan rule:run-fixtures --dry-run
 */
class RunDetectionFixturesCommand extends Command
{
    protected $signature = 'rule:run-fixtures
                            {--tier=tier_1_immediate : Fixture tier to run}
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Run detection replay fixture batch — E056, tier_1_immediate; advisory-only';

    public function handle(DetectionReplayFixtureService $service): int
    {
        $tier   = (string) $this->option('tier');
        $dryRun = (bool)   $this->option('dry-run');
        $mode   = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[RUN]</>';

        $this->line('');
        $this->line("{$mode} Detection Replay Fixture Batch — tier={$tier}");
        $this->line('  ADVISORY-ONLY. promotion_blocked = true always.');
        $this->line('');

        $result = $service->runBatch($tier, $dryRun);

        if (isset($result['error'])) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $batch   = $result['batch'];
        $results = $result['results'];

        $this->table(
            ['Rule ID', 'Domain', 'Valid', 'Event Type', 'Notes'],
            array_map(fn ($r) => [
                $r['rule_id'],
                $r['domain'],
                $r['fixture_valid'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                $r['event_type_matched'] ?? '-',
                substr($r['validation_notes'] ?? '', 0, 60),
            ], $results),
        );

        $this->line('');
        $this->line("  rules_total    : {$batch['rules_total']}");
        $this->line("  fixtures_valid : {$batch['fixtures_valid']}");
        $this->line("  fixtures_invalid: {$batch['fixtures_invalid']}");
        $this->line('  promotion_blocked: true (always)');

        if (!$dryRun) {
            $this->info("Batch persisted (batch_id: {$batch['batch_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
