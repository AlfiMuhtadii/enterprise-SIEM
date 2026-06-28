<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-067 — RAG Knowledge Base Seeding
 *
 * Loads fixture documents into soc_knowledge_base so the RAG pipeline
 * has retrieval content on fresh deployments.
 *
 * Idempotent: re-running does not duplicate entries (keyed by fixture_id).
 */
class AiKnowledgeSeedService
{
    public const FIXTURE_PATH = 'database/seeders/data/rag_knowledge_fixtures.json';
    public const MAX_FIXTURES = 500;

    public function loadFixtures(): array
    {
        $path = base_path(self::FIXTURE_PATH);
        if (!file_exists($path)) {
            throw new \RuntimeException('Fixture file not found: ' . self::FIXTURE_PATH);
        }

        $fixtures = json_decode(file_get_contents($path), true);
        if (!is_array($fixtures)) {
            throw new \RuntimeException('Invalid fixture JSON.');
        }

        return array_slice($fixtures, 0, self::MAX_FIXTURES);
    }

    public function seed(string $initiatedBy, bool $dryRun = false): array
    {
        $runId    = 'rsr-' . Str::uuid();
        $fixtures = $this->loadFixtures();
        $log      = [];
        $seeded   = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($fixtures as $fixture) {
            $fixtureId = $fixture['fixture_id'];

            try {
                // Persist fixture definition (mutable — upsert on fixture_id)
                DB::table('rag_seed_fixtures')->updateOrInsert(
                    ['fixture_id' => $fixtureId],
                    [
                        'title'      => $fixture['title'],
                        'category'   => $fixture['category'],
                        'content'    => $fixture['content'],
                        'source'     => $fixture['source'] ?? null,
                        'active'     => true,
                        'updated_at' => now()->format('Y-m-d H:i:sP'),
                        'created_at' => now()->format('Y-m-d H:i:sP'),
                    ],
                );

                if ($dryRun) {
                    $log[] = ['fixture_id' => $fixtureId, 'action' => 'SKIPPED', 'detail' => 'dry-run'];
                    $skipped++;
                    continue;
                }

                // Check if already seeded in soc_knowledge_base
                $existing = DB::table('soc_knowledge_base')
                    ->where('related_rule_id', 'rag-seed-' . $fixtureId)
                    ->first();

                if ($existing) {
                    $log[] = ['fixture_id' => $fixtureId, 'action' => 'SKIPPED', 'detail' => 'already exists in soc_knowledge_base', 'kb_entry_id' => $existing->id ?? null];
                    $skipped++;
                    continue;
                }

                // Seed into soc_knowledge_base (using actual schema columns)
                $kbId = DB::table('soc_knowledge_base')->insertGetId([
                    'kb_id'           => 'rag-' . substr($fixtureId, 0, 60),
                    'title'           => $fixture['title'],
                    'entry_type'      => $fixture['category'],
                    'content_markdown' => $fixture['content'],
                    'related_rule_id' => 'rag-seed-' . $fixtureId,
                    'created_by'      => 'rag-seeder',
                    'created_at'      => now()->format('Y-m-d H:i:sP'),
                    'updated_at'      => now()->format('Y-m-d H:i:sP'),
                ]);

                $log[]  = ['fixture_id' => $fixtureId, 'action' => 'SEEDED', 'detail' => 'inserted', 'kb_entry_id' => $kbId];
                $seeded++;
            } catch (\Throwable $e) {
                $log[]  = ['fixture_id' => $fixtureId, 'action' => 'FAILED', 'detail' => $e->getMessage()];
                $failed++;
            }
        }

        $outcome = $dryRun ? 'DRY_RUN' : ($failed > 0 ? 'PARTIAL' : 'DONE');

        $this->persistRun($runId, $initiatedBy, $dryRun, count($fixtures), $seeded, $skipped, $failed, $outcome, $log, $runId);

        return [
            'run_id'           => $runId,
            'dry_run'          => $dryRun,
            'fixtures_total'   => count($fixtures),
            'fixtures_seeded'  => $seeded,
            'fixtures_skipped' => $skipped,
            'fixtures_failed'  => $failed,
            'outcome'          => $outcome,
            'log'              => $log,
        ];
    }

    public function getSeededCount(): int
    {
        if (!Schema::hasTable('soc_knowledge_base')) {
            return 0;
        }
        return DB::table('soc_knowledge_base')
            ->where('related_rule_id', 'like', 'rag-seed-%')
            ->count();
    }

    public function getSeedHistory(int $limit = 20): Collection
    {
        return DB::table('rag_seed_runs')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getFixtures(int $limit = 100): Collection
    {
        return DB::table('rag_seed_fixtures')
            ->where('active', true)
            ->orderBy('category')
            ->limit($limit)
            ->get();
    }

    // -------------------------------------------------------------------------

    private function persistRun(
        string $runId, string $initiatedBy, bool $dryRun,
        int $total, int $seeded, int $skipped, int $failed, string $outcome,
        array $log, string $actualRunId
    ): void {
        $now = now()->format('Y-m-d H:i:sP');

        DB::table('rag_seed_runs')->insertOrIgnore([
            'run_id'           => $runId,
            'initiated_by'     => $initiatedBy,
            'dry_run'          => $dryRun,
            'fixtures_total'   => $total,
            'fixtures_seeded'  => $seeded,
            'fixtures_skipped' => $skipped,
            'fixtures_failed'  => $failed,
            'outcome'          => $outcome,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        foreach ($log as $entry) {
            DB::table('rag_seed_document_log')->insertOrIgnore([
                'run_id'      => $runId,
                'fixture_id'  => $entry['fixture_id'],
                'kb_entry_id' => $entry['kb_entry_id'] ?? null,
                'action'      => $entry['action'],
                'detail'      => $entry['detail'] ?? null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }
}
