<?php

namespace App\Services;

use App\Models\Phase1SoakFreezeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-064: Phase 1 Soak Evidence Freeze
 *
 * Creates an immutable snapshot of the full P1G-01..P1G-08 evidence chain,
 * capturing the state at the point when Decision: PASS was achieved (2026-06-27).
 *
 * 12 gates (EV064-01 through EV064-12).
 *
 * Safety invariants (never change):
 *   NO_PROMOTION   = true   — no freeze run authorizes rule promotion
 *   FREEZE_APPROVED = false  — documentation record only
 *   ADVISORY_ONLY  = true   — all outputs are advisory
 */
class Phase1SoakEvidenceFreezeService
{
    public const ADVISORY_ONLY    = true;
    public const NO_PROMOTION     = true;
    public const FREEZE_APPROVED  = false;
    public const FREEZE_VERSION   = 'E064';
    public const GATES_TOTAL      = 12;
    public const PASS_THRESHOLD   = 0.80;

    public const SOAK_REPORT_PATH = 'reports/xdr_correlation_soak_6h.json';

    // ── Public API ────────────────────────────────────────────────────────────

    public function freeze(bool $dryRun = false): array
    {
        $runId    = (string) Str::uuid();
        $gates    = $this->evaluateGates($runId);
        $evidence = $this->collectEvidence($runId);

        $passed  = count(array_filter($gates, fn ($g) => $g['status'] === 'pass'));
        $warned  = count(array_filter($gates, fn ($g) => $g['status'] === 'warn'));
        $failed  = count(array_filter($gates, fn ($g) => $g['status'] === 'fail'));
        $total   = count($gates);
        $score   = $total > 0 ? round($passed / $total, 3) : 0.0;
        $verdict = $failed > 0 ? 'FAIL' : ($warned > 0 ? 'WARN' : 'PASS');

        $summary = [
            'freeze_run_id'  => $runId,
            'freeze_version' => self::FREEZE_VERSION,
            'gates_total'    => $total,
            'gates_passed'   => $passed,
            'gates_warned'   => $warned,
            'gates_failed'   => $failed,
            'pass_score'     => $score,
            'verdict'        => $verdict,
            'no_promotion'   => true,
            'freeze_approved'=> false,
            'is_advisory'    => true,
            'is_dry_run'     => $dryRun,
            'frozen_at'      => now()->format('Y-m-d H:i:sP'),
        ];

        if (!$dryRun) {
            $this->persist($summary, $gates, $evidence);
        }

        return compact('summary', 'gates', 'evidence');
    }

    public function getLatestFreeze(): ?array
    {
        if (!Schema::hasTable('phase1_soak_freeze_runs')) {
            return null;
        }
        $run = Phase1SoakFreezeRun::orderByDesc('frozen_at')->first();
        if (!$run) {
            return null;
        }
        $id = $run->freeze_run_id;
        return [
            'summary'  => $run->toArray(),
            'gates'    => DB::table('phase1_soak_freeze_gates')->where('freeze_run_id', $id)->get()->toArray(),
            'evidence' => DB::table('phase1_soak_freeze_evidence')->where('freeze_run_id', $id)->get()->toArray(),
        ];
    }

    // ── Gates EV064-01 through EV064-12 ──────────────────────────────────────

    private function evaluateGates(string $runId): array
    {
        $gates = [];

        // ── Structural: services and tables ───────────────────────────────────

        $svc1 = class_exists(\App\Services\Phase1SoakExecutionService::class);
        $gates[] = $this->gate($runId, 'EV064-01', 'Phase1SoakExecutionService deployed',
            $svc1 ? 'pass' : 'fail',
            $svc1 ? 'class loadable' : 'class missing');

        $noPromo = $svc1 && \App\Services\Phase1SoakExecutionService::NO_PROMOTION === true;
        $gates[] = $this->gate($runId, 'EV064-02', 'Phase1SoakExecutionService::NO_PROMOTION = true',
            $noPromo ? 'pass' : 'fail',
            $noPromo ? 'safety constant confirmed' : 'NO_PROMOTION changed — safety risk');

        $advisory = self::ADVISORY_ONLY === true;
        $gates[] = $this->gate($runId, 'EV064-03', 'Phase1SoakEvidenceFreezeService::ADVISORY_ONLY = true',
            $advisory ? 'pass' : 'fail',
            $advisory ? 'safety constant confirmed' : 'ADVISORY_ONLY changed — safety risk');

        $tblRuns = Schema::hasTable('phase1_soak_runs');
        $gates[] = $this->gate($runId, 'EV064-04', 'phase1_soak_runs table exists',
            $tblRuns ? 'pass' : 'fail',
            $tblRuns ? 'table present' : 'table missing — run migrations');

        $tblGates = Schema::hasTable('phase1_soak_gate_results');
        $gates[] = $this->gate($runId, 'EV064-05', 'phase1_soak_gate_results table exists',
            $tblGates ? 'pass' : 'fail',
            $tblGates ? 'table present' : 'table missing — run migrations');

        // ── Structural: fixture and confidence chain (E063 fix verified) ──────

        $fixtureDir   = base_path('tests/fixtures/detection/tier1_batch1');
        $fixtureCount = is_dir($fixtureDir) ? count(glob($fixtureDir . '/*.json')) : 0;
        $gates[] = $this->gate($runId, 'EV064-06', '12 tier-1 fixture files present on disk',
            $fixtureCount >= 12 ? 'pass' : 'fail',
            "fixture_files_found={$fixtureCount} (expected 12, path=tests/fixtures/detection/tier1_batch1)");

        $dfs          = class_exists(\App\Services\DetectionReplayFixtureService::class);
        $evidenceRows = $dfs && Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('has_validation_evidence', true)->count()
            : 0;
        $gates[] = $this->gate($runId, 'EV064-07', 'rule_fixture_backlogs has has_validation_evidence=true rows (E063 fix)',
            $evidenceRows >= 12 ? 'pass' : 'warn',
            "rows_with_evidence={$evidenceRows} (expected 12; WARN if warm-up not yet run)");

        $empiricalCount = Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count()
            : 0;
        $gates[] = $this->gate($runId, 'EV064-08', 'rule_fixture_backlogs has 12 empirical rules (E058 + E063)',
            $empiricalCount >= 12 ? 'pass' : 'warn',
            "empirical_count={$empiricalCount} (expected 12; WARN if rule:refresh-confidence not run)");

        $auditCount = Schema::hasTable('confidence_source_audit_events')
            ? DB::table('confidence_source_audit_events')
                ->where('new_confidence_source', 'empirical')
                ->count()
            : 0;
        $gates[] = $this->gate($runId, 'EV064-09', 'confidence_source_audit_events has empirical entries',
            $auditCount >= 1 ? 'pass' : 'warn',
            "empirical_audit_events={$auditCount} (WARN if rule:refresh-confidence not run)",
            true);

        // ── Live-DB: latest soak run Decision = PASS ──────────────────────────

        $latestDecision = $tblRuns
            ? DB::table('phase1_soak_runs')
                ->where('is_dry_run', false)
                ->orderByDesc('started_at')
                ->value('decision')
            : null;
        $gates[] = $this->gate($runId, 'EV064-10', 'Latest phase1_soak_run Decision = PASS',
            $latestDecision === 'PASS' ? 'pass' : 'warn',
            $latestDecision !== null
                ? "latest_decision={$latestDecision}"
                : 'no_live_run_found — run soak:phase1-run --warm-up',
            true);

        // ── Live-DB: all 8 P1G gates passed ──────────────────────────────────

        $latestRunId = $tblRuns
            ? DB::table('phase1_soak_runs')
                ->where('is_dry_run', false)
                ->orderByDesc('started_at')
                ->value('soak_run_id')
            : null;

        $allP1gPassed = false;
        $p1gPassedCount = 0;
        if ($latestRunId && $tblGates) {
            $p1gPassedCount = DB::table('phase1_soak_gate_results')
                ->where('soak_run_id', $latestRunId)
                ->where('status', 'pass')
                ->count();
            $allP1gPassed = $p1gPassedCount >= 8;
        }
        $gates[] = $this->gate($runId, 'EV064-11', 'All 8 P1G gates passed in latest soak run',
            $allP1gPassed ? 'pass' : 'warn',
            $latestRunId
                ? "p1g_gates_passed={$p1gPassedCount}/8 (latest run={$latestRunId})"
                : 'no_live_run_found',
            true);

        // ── Soak report file present (P1G-07/P1G-08 source) ─────────────────

        $reportPath   = base_path(self::SOAK_REPORT_PATH);
        $reportExists = file_exists($reportPath);
        $gates[] = $this->gate($runId, 'EV064-12', '6h soak report file present (P1G-07/P1G-08 source)',
            $reportExists ? 'pass' : 'warn',
            $reportExists
                ? "file_found={$reportPath}"
                : 'soak_report_missing — run run_xdr_correlation_soak_6h.ps1 to generate',
            !$reportExists);

        return $gates;
    }

    // ── Evidence snapshot ─────────────────────────────────────────────────────

    private function collectEvidence(string $runId): array
    {
        $ts   = now()->format('Y-m-d H:i:sP');
        $evs  = [];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'soak_run_decision',
            'evidence_value' => DB::table('phase1_soak_runs')
                ->where('is_dry_run', false)
                ->orderByDesc('started_at')
                ->value('decision') ?? 'NO_RUN',
            'source_table'   => 'phase1_soak_runs',
            'is_advisory'    => true,
            'captured_at'    => $ts,
        ];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'empirical_rules_count',
            'evidence_value' => (string) (Schema::hasTable('rule_fixture_backlogs')
                ? DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count()
                : 0),
            'source_table'   => 'rule_fixture_backlogs',
            'is_advisory'    => true,
            'captured_at'    => $ts,
        ];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'fixture_files_on_disk',
            'evidence_value' => (string) (is_dir(base_path('tests/fixtures/detection/tier1_batch1'))
                ? count(glob(base_path('tests/fixtures/detection/tier1_batch1') . '/*.json'))
                : 0),
            'source_table'   => 'filesystem',
            'is_advisory'    => false,
            'captured_at'    => $ts,
        ];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'p1g_gates_passed_in_latest_run',
            'evidence_value' => (string) (Schema::hasTable('phase1_soak_gate_results') && Schema::hasTable('phase1_soak_runs')
                ? DB::table('phase1_soak_gate_results')
                    ->whereIn('soak_run_id', function ($q) {
                        $q->select('soak_run_id')
                          ->from('phase1_soak_runs')
                          ->where('is_dry_run', false)
                          ->orderByDesc('started_at')
                          ->limit(1);
                    })
                    ->where('status', 'pass')
                    ->count()
                : 0),
            'source_table'   => 'phase1_soak_gate_results',
            'is_advisory'    => true,
            'captured_at'    => $ts,
        ];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'soak_report_present',
            'evidence_value' => file_exists(base_path(self::SOAK_REPORT_PATH)) ? 'true' : 'false',
            'source_table'   => 'filesystem',
            'is_advisory'    => false,
            'captured_at'    => $ts,
        ];

        $evs[] = [
            'freeze_run_id'  => $runId,
            'evidence_type'  => 'no_promotion_confirmed',
            'evidence_value' => 'true',
            'source_table'   => 'Phase1SoakEvidenceFreezeService::NO_PROMOTION',
            'is_advisory'    => false,
            'captured_at'    => $ts,
        ];

        return $evs;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    private function persist(array $summary, array $gates, array $evidence): void
    {
        DB::table('phase1_soak_freeze_runs')->insert([
            'freeze_run_id'  => $summary['freeze_run_id'],
            'freeze_version' => $summary['freeze_version'],
            'gates_total'    => $summary['gates_total'],
            'gates_passed'   => $summary['gates_passed'],
            'gates_warned'   => $summary['gates_warned'],
            'gates_failed'   => $summary['gates_failed'],
            'pass_score'     => $summary['pass_score'],
            'verdict'        => $summary['verdict'],
            'no_promotion'   => true,
            'freeze_approved'=> false,
            'is_advisory'    => true,
            'is_dry_run'     => false,
            'frozen_at'      => $summary['frozen_at'],
        ]);

        foreach ($gates as $g) {
            DB::table('phase1_soak_freeze_gates')->insert([
                'freeze_run_id' => $g['freeze_run_id'],
                'gate_id'       => $g['gate_id'],
                'gate_name'     => $g['gate_name'],
                'status'        => $g['status'],
                'evidence'      => $g['evidence'],
                'is_advisory'   => $g['is_advisory'],
                'evaluated_at'  => $g['evaluated_at'],
            ]);
        }

        foreach ($evidence as $ev) {
            DB::table('phase1_soak_freeze_evidence')->insert($ev);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function gate(
        string $runId,
        string $id,
        string $name,
        string $status,
        string $evidence,
        bool $isAdvisory = false,
    ): array {
        return [
            'freeze_run_id' => $runId,
            'gate_id'       => $id,
            'gate_name'     => $name,
            'status'        => $status,
            'evidence'      => $evidence,
            'is_advisory'   => $isAdvisory,
            'evaluated_at'  => now()->format('Y-m-d H:i:sP'),
        ];
    }
}
