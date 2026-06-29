<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENTERPRISE-071 — RAG Knowledge Base Operational Status Check
 *
 * Verifies that the RAG pipeline is seeded and operationally ready
 * for analyst queries. Advisory read-only — never seeds or modifies data.
 *
 * Usage:
 *   php artisan ai:knowledge-check
 *   php artisan ai:knowledge-check --output=reports/rag_operational.json
 */
class RagOperationalCheckCommand extends Command
{
    protected $signature   = 'ai:knowledge-check {--output= : Optional JSON output file}';
    protected $description = 'Check RAG knowledge base operational readiness (advisory, read-only)';

    private const MIN_KB_ENTRIES = 1;

    public function handle(): int
    {
        $checks  = [];
        $overall = 'PASS';

        // CHK-01: soc_knowledge_base table exists
        $tableExists = Schema::hasTable('soc_knowledge_base');
        $checks['CHK-01'] = [
            'name'   => 'soc_knowledge_base table accessible',
            'status' => $tableExists ? 'PASS' : 'FAIL',
            'detail' => $tableExists ? 'Table exists and is accessible' : 'soc_knowledge_base table not found',
        ];
        if (!$tableExists) {
            $overall = 'FAIL';
        }

        // CHK-02: knowledge entries present
        $kbCount = $tableExists ? DB::table('soc_knowledge_base')->count() : 0;
        $checks['CHK-02'] = [
            'name'   => 'Knowledge base has seeded entries',
            'status' => $kbCount >= self::MIN_KB_ENTRIES ? 'PASS' : 'WARN',
            'detail' => $kbCount > 0
                ? "{$kbCount} entries in soc_knowledge_base"
                : 'No entries found — run: php artisan ai:seed-knowledge',
        ];

        // CHK-03: AiSeedKnowledgeCommand registered
        $seedCmd = class_exists(\App\Console\Commands\AiSeedKnowledgeCommand::class);
        $checks['CHK-03'] = [
            'name'   => 'AiSeedKnowledgeCommand registered',
            'status' => $seedCmd ? 'PASS' : 'FAIL',
            'detail' => $seedCmd ? 'ai:seed-knowledge command is available' : 'AiSeedKnowledgeCommand not found',
        ];
        if (!$seedCmd) {
            $overall = 'FAIL';
        }

        // CHK-04: RAG fixture file exists
        $fixturePath = base_path('database/seeders/data/rag_knowledge_fixtures.json');
        $fixtureOk   = file_exists($fixturePath);
        $checks['CHK-04'] = [
            'name'   => 'RAG knowledge fixture file exists',
            'status' => $fixtureOk ? 'PASS' : 'WARN',
            'detail' => $fixtureOk
                ? 'database/seeders/data/rag_knowledge_fixtures.json present'
                : 'Fixture file not found — knowledge base cannot be re-seeded',
        ];

        // CHK-05: AiAnalystManager / RAG provider wired
        $analysisMgr = class_exists(\App\Support\AiAnalystManager::class);
        $checks['CHK-05'] = [
            'name'   => 'AiAnalystManager is in place',
            'status' => $analysisMgr ? 'PASS' : 'WARN',
            'detail' => $analysisMgr ? 'AiAnalystManager class available' : 'AiAnalystManager not found',
        ];

        // CHK-06: SocKnowledgeRetriever wired
        $retriever = class_exists(\App\Support\SocKnowledgeRetriever::class);
        $checks['CHK-06'] = [
            'name'   => 'SocKnowledgeRetriever is in place',
            'status' => $retriever ? 'PASS' : 'WARN',
            'detail' => $retriever ? 'SocKnowledgeRetriever class available' : 'SocKnowledgeRetriever not found',
        ];

        $report = [
            'command'        => 'ai:knowledge-check',
            'overall'        => $overall,
            'kb_entry_count' => $kbCount,
            'checks'         => $checks,
            'recommendation' => $kbCount === 0
                ? 'Run: php artisan ai:seed-knowledge to seed the RAG knowledge base'
                : 'RAG knowledge base is seeded and operational',
            'note'           => 'Advisory only. This command never modifies data.',
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        if ($output = $this->option('output')) {
            @mkdir(dirname($output), 0755, true);
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$output}");
        }

        return self::SUCCESS;
    }
}
