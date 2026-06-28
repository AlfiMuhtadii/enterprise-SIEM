<?php

namespace App\Console\Commands;

use App\Services\AiKnowledgeSeedService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-067 — RAG Knowledge Base Seeder
 *
 * Usage:
 *   php artisan ai:seed-knowledge
 *   php artisan ai:seed-knowledge --dry-run
 *   php artisan ai:seed-knowledge --output=reports/rag_seed.json
 */
class AiSeedKnowledgeCommand extends Command
{
    protected $signature = 'ai:seed-knowledge
                            {--dry-run   : Show what would be seeded without writing}
                            {--output=   : Optional path to write JSON report}';

    protected $description = 'Seed RAG knowledge base from fixture file (ENTERPRISE-067)';

    public function __construct(private readonly AiKnowledgeSeedService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $mode = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[WRITE]</>';
        $this->line("{$mode} RAG Knowledge Base Seeding (ENTERPRISE-067)");
        $this->line('  Fixture: ' . AiKnowledgeSeedService::FIXTURE_PATH);
        $this->line(str_repeat('-', 60));

        $result = $this->service->seed('cli', $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Fixtures total', $result['fixtures_total']],
                ['Seeded', $result['fixtures_seeded']],
                ['Skipped (already exists)', $result['fixtures_skipped']],
                ['Failed', $result['fixtures_failed']],
            ],
        );

        $outcomeColor = match ($result['outcome']) {
            'DONE'    => 'green',
            'DRY_RUN' => 'yellow',
            'PARTIAL' => 'yellow',
            default   => 'red',
        };
        $this->line("Outcome: <fg={$outcomeColor}>{$result['outcome']}</>");
        $this->line('');

        if ($path = $this->option('output')) {
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$path}");
        }

        return $result['fixtures_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
