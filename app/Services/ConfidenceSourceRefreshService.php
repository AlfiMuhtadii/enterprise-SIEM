<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-058: Confidence Source Refresh
 *
 * Re-derives confidence_source for every rule in rule_fixture_backlogs
 * based on current has_replay_fixture + has_validation_evidence state.
 *
 * Derivation logic (same as E050):
 *   empirical      — has_replay_fixture = true  AND has_validation_evidence = true
 *   fixture_tested — has_replay_fixture = true  AND has_validation_evidence = false
 *   manual         — has_replay_fixture = false (regardless of evidence)
 *
 * Updates rule_fixture_backlogs.confidence_source (mutable column).
 * Appends audit rows to confidence_source_audit_events (append-only).
 * ADVISORY_ONLY = true.
 */
class ConfidenceSourceRefreshService
{
    public const ADVISORY_ONLY = true;

    private const REGISTRY_PATH = 'docs/detection/rules/registry.v1.json';

    // ── Public API ────────────────────────────────────────────────────────────

    public function refresh(bool $dryRun = false): array
    {
        $runId   = (string) Str::uuid();
        $backlog = $this->loadBacklog();
        $events  = [];
        $dist    = ['empirical' => 0, 'fixture_tested' => 0, 'manual' => 0, 'changed' => 0];

        foreach ($backlog as $row) {
            $newSource = $this->deriveSource((bool) $row->has_replay_fixture, (bool) $row->has_validation_evidence);
            $oldSource = $row->confidence_source ?? 'manual';
            $changed   = $newSource !== $oldSource;

            $events[] = [
                'refresh_run_id'      => $runId,
                'rule_id'             => $row->rule_id,
                'domain'              => $row->domain,
                'old_confidence_source' => $oldSource,
                'new_confidence_source' => $newSource,
                'changed'             => $changed,
                'has_fixture'         => (bool) $row->has_replay_fixture,
                'has_evidence'        => (bool) $row->has_validation_evidence,
                'is_advisory'         => true,
                'recorded_at'         => now()->format('Y-m-d H:i:sP'),
            ];

            $dist[$newSource]++;
            if ($changed) {
                $dist['changed']++;
            }
        }

        $total       = count($events);
        $empiricalRate = $total > 0 ? round($dist['empirical'] / $total, 3) : 0.0;

        $run = [
            'refresh_run_id'      => $runId,
            'total_rules'         => $total,
            'empirical_count'     => $dist['empirical'],
            'fixture_tested_count'=> $dist['fixture_tested'],
            'manual_count'        => $dist['manual'],
            'changed_count'       => $dist['changed'],
            'empirical_rate'      => $empiricalRate,
            'is_advisory'         => true,
            'run_at'              => now()->format('Y-m-d H:i:sP'),
        ];

        if (!$dryRun) {
            $this->persist($run, $events, $backlog);
        }

        return ['run' => $run, 'events' => $events];
    }

    public function getDistribution(): array
    {
        if (!Schema::hasTable('rule_fixture_backlogs')) {
            return ['empirical' => 0, 'fixture_tested' => 0, 'manual' => 0, 'total' => 0];
        }
        $rows = DB::table('rule_fixture_backlogs')->select('confidence_source')->get();
        return [
            'empirical'     => $rows->where('confidence_source', 'empirical')->count(),
            'fixture_tested'=> $rows->where('confidence_source', 'fixture_tested')->count(),
            'manual'        => $rows->where('confidence_source', 'manual')->count(),
            'total'         => $rows->count(),
        ];
    }

    public function getLatestRun(): ?object
    {
        if (!Schema::hasTable('confidence_source_distribution_runs')) {
            return null;
        }
        return DB::table('confidence_source_distribution_runs')->orderByDesc('run_at')->first();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function deriveSource(bool $hasFixture, bool $hasEvidence): string
    {
        if ($hasFixture && $hasEvidence) {
            return 'empirical';
        }
        if ($hasFixture) {
            return 'fixture_tested';
        }
        return 'manual';
    }

    private function loadBacklog(): Collection
    {
        if (!Schema::hasTable('rule_fixture_backlogs')) {
            return collect();
        }
        return DB::table('rule_fixture_backlogs')
            ->select('rule_id', 'domain', 'confidence_source', 'has_replay_fixture', 'has_validation_evidence')
            ->get();
    }

    private function persist(array $run, array $events, Collection $backlog): void
    {
        DB::table('confidence_source_distribution_runs')->insert($run);

        foreach ($events as $event) {
            DB::table('confidence_source_audit_events')->insert($event);
        }

        // Update confidence_source in rule_fixture_backlogs (mutable column)
        if (Schema::hasTable('rule_fixture_backlogs')) {
            foreach ($events as $event) {
                if ($event['changed']) {
                    DB::table('rule_fixture_backlogs')
                        ->where('rule_id', $event['rule_id'])
                        ->update(['confidence_source' => $event['new_confidence_source']]);
                }
            }
        }
    }
}
