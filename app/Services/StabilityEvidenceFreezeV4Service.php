<?php

namespace App\Services;

use App\Models\StabilityFreezeV4Run;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-059: Stability Evidence Freeze v4
 *
 * Covers the delta E055-E058:
 *   E055 — Stability Freeze v3 reference
 *   E056 — Replay Fixture Batch 1 (12 tier_1 rules)
 *   E057 — Domain Soak Simulation (endpoint/network/threat-intel)
 *   E058 — Confidence Source Refresh
 *
 * 16 gates (EV4-01 through EV4-16).
 * Updates vs v3:
 *   GAP-01: CRITICAL → HIGH (12 fixtures added; 101 rules still missing)
 *   GAP-02: HIGH    → MEDIUM (12 rules now empirical after E058)
 *   GAP-03: HIGH    → HIGH (simulation complete, real soak still required)
 *
 * freeze_approved = false ALWAYS.
 * ADVISORY_ONLY = true.
 */
class StabilityEvidenceFreezeV4Service
{
    public const ADVISORY_ONLY   = true;
    public const FREEZE_APPROVED = false;
    public const FREEZE_VERSION  = 'v4';
    public const PHASE_RANGE     = 'E055-E058';

    public const STABLE_SCORE_THRESHOLD = 0.80;

    private const PHASE_MAP = [
        'E055' => 'Stability Evidence Freeze v3 Reference',
        'E056' => 'Replay Fixture Batch 1 (tier_1_immediate — identity/cloud/saas)',
        'E057' => 'Domain Soak Simulation (endpoint / network / threat-intel)',
        'E058' => 'Confidence Source Refresh (manual → fixture_tested / empirical)',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public function freeze(bool $dryRun = false): array
    {
        $runId  = (string) Str::uuid();
        $gates  = $this->evaluateGates($runId);
        $phases = $this->collectPhaseSummaries($runId);
        $claims = $this->buildReadinessClaims($runId);
        $gaps   = $this->buildGapRegistry($runId);

        $passed = count(array_filter($gates, fn ($g) => $g['status'] === 'pass'));
        $failed = count(array_filter($gates, fn ($g) => $g['status'] === 'fail'));
        $warn   = count(array_filter($gates, fn ($g) => $g['status'] === 'warn'));
        $total  = count($gates);
        $score  = $total > 0 ? round($passed / $total, 3) : 0.0;

        $allowedCount   = count(array_filter($claims, fn ($c) => $c['claim_type'] === 'allowed'));
        $forbiddenCount = count(array_filter($claims, fn ($c) => $c['claim_type'] === 'forbidden'));

        $summary = [
            'freeze_run_id'        => $runId,
            'freeze_version'       => self::FREEZE_VERSION,
            'phase_range'          => self::PHASE_RANGE,
            'total_gates'          => $total,
            'gates_passed'         => $passed,
            'gates_failed'         => $failed,
            'gates_warn'           => $warn,
            'pass_score'           => $score,
            'total_phases'         => count($phases),
            'allowed_claim_count'  => $allowedCount,
            'forbidden_claim_count'=> $forbiddenCount,
            'gap_count'            => count($gaps),
            'stability'            => $score >= self::STABLE_SCORE_THRESHOLD ? 'STABLE' : 'UNSTABLE',
            'freeze_approved'      => false,
            'is_advisory'          => true,
            'frozen_at'            => now()->format('Y-m-d H:i:sP'),
        ];

        if (!$dryRun) {
            $this->persist($summary, $gates, $phases, $claims, $gaps);
        }

        return compact('summary', 'gates', 'phases', 'claims', 'gaps');
    }

    public function getLatestFreeze(): ?array
    {
        if (!Schema::hasTable('stability_v4_freeze_runs')) {
            return null;
        }
        $run = StabilityFreezeV4Run::orderByDesc('frozen_at')->first();
        if (!$run) {
            return null;
        }
        $runId = $run->freeze_run_id;
        return [
            'summary' => $run->toArray(),
            'gates'   => DB::table('stability_v4_freeze_gates')->where('freeze_run_id', $runId)->get()->toArray(),
            'phases'  => DB::table('stability_v4_phase_summaries')->where('freeze_run_id', $runId)->get()->toArray(),
            'claims'  => DB::table('stability_v4_readiness_claims')->where('freeze_run_id', $runId)->get()->toArray(),
            'gaps'    => DB::table('stability_v4_gap_registry')->where('freeze_run_id', $runId)->get()->toArray(),
        ];
    }

    // ── Gates EV4-01 through EV4-16 ──────────────────────────────────────────

    private function evaluateGates(string $runId): array
    {
        $gates = [];

        // ── E055 reference ────────────────────────────────────────────────────
        $sfv3 = class_exists(\App\Services\StabilityEvidenceFreezeV3Service::class);
        $gates[] = $this->gate($runId, 'EV4-01', 'E055: StabilityEvidenceFreezeV3Service deployed',
            $sfv3 ? 'pass' : 'fail', $sfv3 ? 'v3 freeze service loadable' : 'v3 service missing');

        $v3Table = Schema::hasTable('stability_v3_freeze_runs');
        $gates[] = $this->gate($runId, 'EV4-02', 'E055: stability_v3_freeze_runs table exists',
            $v3Table ? 'pass' : 'fail', $v3Table ? 'table present' : 'table missing');

        // ── E056 fixture batch ────────────────────────────────────────────────
        $dfs = class_exists(\App\Services\DetectionReplayFixtureService::class);
        $gates[] = $this->gate($runId, 'EV4-03', 'E056: DetectionReplayFixtureService deployed',
            $dfs ? 'pass' : 'fail', $dfs ? 'class loadable' : 'class missing');

        $batchTable = Schema::hasTable('detection_fixture_batches');
        $gates[] = $this->gate($runId, 'EV4-04', 'E056: detection_fixture_batches table exists',
            $batchTable ? 'pass' : 'fail', $batchTable ? 'table present' : 'table missing');

        $resultsTable = Schema::hasTable('detection_fixture_results');
        $gates[] = $this->gate($runId, 'EV4-05', 'E056: detection_fixture_results table exists',
            $resultsTable ? 'pass' : 'fail', $resultsTable ? 'table present' : 'table missing');

        // Check that 12 fixture files are present on disk
        $fixtureDir  = base_path('tests/fixtures/detection/tier1_batch1');
        $fixtureCount = is_dir($fixtureDir) ? count(glob($fixtureDir . '/*.json')) : 0;
        $gates[] = $this->gate($runId, 'EV4-06', 'E056: 12 tier_1 fixture files present on disk',
            $fixtureCount >= 12 ? 'pass' : 'warn',
            "fixture_files_found={$fixtureCount} (expected 12)");

        // ── E057 domain soak simulation ───────────────────────────────────────
        $dss = class_exists(\App\Services\DomainSoakSimulationService::class);
        $gates[] = $this->gate($runId, 'EV4-07', 'E057: DomainSoakSimulationService deployed',
            $dss ? 'pass' : 'fail', $dss ? 'class loadable' : 'class missing');

        $soakTable = Schema::hasTable('domain_soak_simulations');
        $gates[] = $this->gate($runId, 'EV4-08', 'E057: domain_soak_simulations table exists',
            $soakTable ? 'pass' : 'fail', $soakTable ? 'table present' : 'table missing');

        if ($dss) {
            $domains = \App\Services\DomainSoakSimulationService::SUPPORTED_DOMAINS;
            $expectedDomains = ['endpoint', 'network', 'threat-intel'];
            $allSupported = empty(array_diff($expectedDomains, $domains));
            $gates[] = $this->gate($runId, 'EV4-09', 'E057: endpoint/network/threat-intel all in SUPPORTED_DOMAINS',
                $allSupported ? 'pass' : 'fail',
                'supported=' . implode(', ', $domains));
        } else {
            $gates[] = $this->gate($runId, 'EV4-09', 'E057: endpoint/network/threat-intel all in SUPPORTED_DOMAINS',
                'warn', 'skipped — service unavailable');
        }

        $promoBlocked = $dss && \App\Services\DomainSoakSimulationService::PROMOTION_RECOMMENDED === false;
        $gates[] = $this->gate($runId, 'EV4-10', 'E057: DomainSoakSimulationService::PROMOTION_RECOMMENDED = false',
            $promoBlocked ? 'pass' : 'fail',
            $promoBlocked ? 'safety constant confirmed' : 'PROMOTION_RECOMMENDED changed — safety risk');

        // ── E058 confidence source refresh ────────────────────────────────────
        $csr = class_exists(\App\Services\ConfidenceSourceRefreshService::class);
        $gates[] = $this->gate($runId, 'EV4-11', 'E058: ConfidenceSourceRefreshService deployed',
            $csr ? 'pass' : 'fail', $csr ? 'class loadable' : 'class missing');

        $auditTable = Schema::hasTable('confidence_source_audit_events');
        $gates[] = $this->gate($runId, 'EV4-12', 'E058: confidence_source_audit_events table exists',
            $auditTable ? 'pass' : 'fail', $auditTable ? 'table present' : 'table missing');

        $distTable = Schema::hasTable('confidence_source_distribution_runs');
        $gates[] = $this->gate($runId, 'EV4-13', 'E058: confidence_source_distribution_runs table exists',
            $distTable ? 'pass' : 'fail', $distTable ? 'table present' : 'table missing');

        // ── Gap closure gates ─────────────────────────────────────────────────
        // GAP-01 partial closure: backlog has at least 1 rule with fixture (after E056)
        $fixtureCount2 = Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('has_replay_fixture', true)->count()
            : 0;
        $gates[] = $this->gate($runId, 'EV4-14', 'GAP-01 partial closure: rule_fixture_backlogs has has_replay_fixture=true rows',
            $fixtureCount2 > 0 ? 'pass' : 'warn',
            "rules_with_fixture={$fixtureCount2} (0 = no fixtures registered yet)");

        // GAP-02 partial closure: at least 1 empirical rule (after E058)
        $empiricalCount = Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count()
            : 0;
        $gates[] = $this->gate($runId, 'EV4-15', 'GAP-02 partial closure: at least 1 empirical rule in backlog',
            $empiricalCount > 0 ? 'pass' : 'warn',
            "empirical_rules={$empiricalCount} (0 = refresh not yet run)");

        // Safety: SIMULATED_BY_DEFAULT still true
        $simByDefault = \App\Services\NotificationService::SIMULATED_BY_DEFAULT === true;
        $gates[] = $this->gate($runId, 'EV4-16', 'Safety: NotificationService::SIMULATED_BY_DEFAULT = true',
            $simByDefault ? 'pass' : 'fail',
            $simByDefault ? 'safety constant confirmed' : 'SIMULATED_BY_DEFAULT changed — safety risk');

        return $gates;
    }

    // ── Phase summaries (E055-E058) ───────────────────────────────────────────

    private function collectPhaseSummaries(string $runId): array
    {
        $phases = [];
        $ts     = now()->format('Y-m-d H:i:sP');

        $phases[] = $this->phase($runId, 'E055', [
            'freeze_version' => 'v3', 'gates' => 22, 'phase_range' => 'E045-E054', 'pass_score' => 1.0,
        ], $ts);

        $phases[] = $this->phase($runId, 'E056', $this->liveE056Metrics(), $ts);

        $phases[] = $this->phase($runId, 'E057', $this->liveE057Metrics(), $ts);

        $phases[] = $this->phase($runId, 'E058', $this->liveE058Metrics(), $ts);

        return $phases;
    }

    private function liveE056Metrics(): array
    {
        $fixtureDir    = base_path('tests/fixtures/detection/tier1_batch1');
        $fixtureCount  = is_dir($fixtureDir) ? count(glob($fixtureDir . '/*.json')) : 0;
        $batchCount    = Schema::hasTable('detection_fixture_batches') ? DB::table('detection_fixture_batches')->count() : 0;
        $validCount    = Schema::hasTable('detection_fixture_results') ? DB::table('detection_fixture_results')->where('fixture_valid', true)->count() : 0;
        return [
            'tier' => 'tier_1_immediate', 'fixture_files_on_disk' => $fixtureCount,
            'batches_run' => $batchCount, 'valid_results' => $validCount, 'promotion_blocked' => true,
        ];
    }

    private function liveE057Metrics(): array
    {
        if (!Schema::hasTable('domain_soak_simulations')) {
            return ['domains_simulated' => 0, 'promotion_recommended' => false, 'real_soak_required' => true];
        }
        $sims = DB::table('domain_soak_simulations')->select('domain')->get();
        return [
            'domains_simulated'    => $sims->unique('domain')->count(),
            'promotion_recommended'=> false,
            'real_soak_required'   => true,
        ];
    }

    private function liveE058Metrics(): array
    {
        if (!Schema::hasTable('rule_fixture_backlogs')) {
            return ['empirical' => 0, 'fixture_tested' => 0, 'manual' => 0, 'total' => 0];
        }
        $rows = DB::table('rule_fixture_backlogs')->select('confidence_source')->get();
        return [
            'empirical'      => $rows->where('confidence_source', 'empirical')->count(),
            'fixture_tested' => $rows->where('confidence_source', 'fixture_tested')->count(),
            'manual'         => $rows->where('confidence_source', 'manual')->count(),
            'total'          => $rows->count(),
        ];
    }

    // ── Allowed / Forbidden claims ────────────────────────────────────────────

    private function buildReadinessClaims(string $runId): array
    {
        $ts = now()->format('Y-m-d H:i:sP');

        $allowed = [
            '12 tier_1 replay fixtures created for all staged_active identity/cloud/saas rules (E056)',
            'Domain soak simulation completed for endpoint/network/threat-intel (structural validation, E057)',
            'Confidence source refreshed: 12 staged_active rules now labelled empirical (E058)',
            'GAP-01 partial closure: 101/133 rules still missing fixture (was 113/133)',
            'GAP-02 partial closure: 12/133 rules now empirical (was 0/133)',
        ];

        $forbidden = [
            'Promotion of endpoint/network/threat-intel: simulation ≠ real 6h soak',
            'Claiming all rules are empirically validated: only 12/133 are empirical',
            'Removing promotion_recommended=false from DomainSoakSimulationService',
        ];

        $claims = [];
        foreach ($allowed as $text) {
            $claims[] = ['freeze_run_id' => $runId, 'claim_type' => 'allowed', 'claim_text' => $text, 'is_advisory' => true, 'recorded_at' => $ts];
        }
        foreach ($forbidden as $text) {
            $claims[] = ['freeze_run_id' => $runId, 'claim_type' => 'forbidden', 'claim_text' => $text, 'is_advisory' => true, 'recorded_at' => $ts];
        }
        return $claims;
    }

    // ── Gap registry (updated vs v3) ──────────────────────────────────────────

    private function buildGapRegistry(string $runId): array
    {
        $ts = now()->format('Y-m-d H:i:sP');
        return [
            ['freeze_run_id' => $runId, 'gap_id' => 'GAP-01', 'severity' => 'high',
             'description'     => '101/133 rules still have no replay fixture (was 113/133; 12 added in E056)',
             'resolution_path' => 'Build tier_2 fixtures next; run rule:run-fixtures --tier=tier_2_next_batch',
             'is_advisory' => true, 'recorded_at' => $ts],
            ['freeze_run_id' => $runId, 'gap_id' => 'GAP-02', 'severity' => 'medium',
             'description'     => '121/133 rules still manual or fixture_tested (12/133 now empirical after E058)',
             'resolution_path' => 'Build fixtures for tier_2 + tier_3 rules; run rule:refresh-confidence after each batch',
             'is_advisory' => true, 'recorded_at' => $ts],
            ['freeze_run_id' => $runId, 'gap_id' => 'GAP-03', 'severity' => 'high',
             'description'     => 'endpoint/network/threat-intel real 6h soak not completed (simulation run in E057)',
             'resolution_path' => 'Run real 6h soak per run_xdr_correlation_soak_6h.ps1 for each domain; promotion blocked until PASS',
             'is_advisory' => true, 'recorded_at' => $ts],
            ['freeze_run_id' => $runId, 'gap_id' => 'GAP-04', 'severity' => 'medium',
             'description'     => 'RLS_ENABLED=false (application-layer isolation only)',
             'resolution_path' => 'See RLS_DECISION_RECORD.md ADR',
             'is_advisory' => true, 'recorded_at' => $ts],
            ['freeze_run_id' => $runId, 'gap_id' => 'GAP-05', 'severity' => 'medium',
             'description'     => 'XDR_TENANT_STRICT_MODE=false by default',
             'resolution_path' => 'Enable per-route; run TenantNullAuditCommand to confirm zero null records first',
             'is_advisory' => true, 'recorded_at' => $ts],
        ];
    }

    // ── Persistence + helpers ─────────────────────────────────────────────────

    private function persist(array $summary, array $gates, array $phases, array $claims, array $gaps): void
    {
        StabilityFreezeV4Run::create($summary);
        foreach ($gates as $gate) {
            $gate['checked_at'] = $summary['frozen_at'];
            DB::table('stability_v4_freeze_gates')->insert($gate);
        }
        foreach ($phases as $phase) {
            DB::table('stability_v4_phase_summaries')->insert($phase);
        }
        foreach ($claims as $claim) {
            DB::table('stability_v4_readiness_claims')->insert($claim);
        }
        foreach ($gaps as $gap) {
            DB::table('stability_v4_gap_registry')->insert($gap);
        }
    }

    private function gate(string $runId, string $id, string $name, string $status, string $evidence): array
    {
        return [
            'freeze_run_id' => $runId,
            'gate_id'       => $id,
            'gate_name'     => $name,
            'status'        => $status,
            'passed'        => $status === 'pass',
            'evidence'      => $evidence,
            'is_advisory'   => true,
        ];
    }

    private function phase(string $runId, string $eid, array $metrics, string $ts): array
    {
        return [
            'freeze_run_id' => $runId,
            'enterprise_id' => $eid,
            'phase_name'    => self::PHASE_MAP[$eid],
            'status'        => 'reviewed',
            'metrics'       => json_encode($metrics),
            'is_advisory'   => true,
            'recorded_at'   => $ts,
        ];
    }
}
