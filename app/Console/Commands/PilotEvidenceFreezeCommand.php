<?php

namespace App\Console\Commands;

use App\Models\PilotReadinessGateEvaluation;
use App\Models\PilotReadinessMatrixRun;
use App\Services\EnterprisePilotReadinessMatrixService;
use Illuminate\Console\Command;

/**
 * PILOT-LIVE-035: Record pilot evidence into the readiness matrix and
 * produce a JSON freeze summary.
 *
 * Advisory-only.  Self-approve is blocked.  No autonomous promotion.
 * Does NOT create alerts or incidents.  All tables are written via
 * EnterprisePilotReadinessMatrixService (append-only gate evals +
 * evidence links; mutable run record only).
 *
 * Usage:
 *   php artisan pilot:evidence-freeze --operator=op-alice --approved-by=op-bob
 *   php artisan pilot:evidence-freeze --operator=op-alice --approved-by=op-bob --dry-run
 *   php artisan pilot:evidence-freeze --list
 */
class PilotEvidenceFreezeCommand extends Command
{
    protected $signature = 'pilot:evidence-freeze
        {--operator=cli-operator    : Operator running this freeze (cannot equal --approved-by)}
        {--approved-by=cli-approver : Approver authorising the freeze (cannot equal --operator)}
        {--tenant=system            : Tenant scope for the matrix run}
        {--dry-run                  : Print planned gates without writing DB records}
        {--output=                  : Optional path to write JSON summary}
        {--list                     : List the most recent matrix runs}';

    protected $description = 'PILOT-LIVE-035: Record pilot evidence freeze into the readiness matrix (advisory-only)';

    /** Gates to record on every freeze run. */
    private const GATES = [
        // --- LIVE-028 offline stages ---
        [
            'gate_id'   => 'soak_validation',
            'dimension' => 'technical',
            'detail'    => '6h soak PASS 2026-05-14; identity/cloud/SaaS staged_active; commit 3329c4 LIVE_CAUSAL_PROOF=PASS',
            'score'     => 1.0,
            'evidence'  => ['commit' => '3329c4', 'task' => 'Topic Bootstrap Phase 1', 'date' => '2026-06-23'],
        ],
        [
            'gate_id'   => 'replay_verification',
            'dimension' => 'technical',
            'detail'    => 'LIVE-028 lineage PASS (NW-1/DB-5/CORR-1); replay-safe event sourcing; commit 880a8d5',
            'score'     => 1.0,
            'evidence'  => ['commit' => '880a8d5', 'task' => 'LIVE-028 full live regression freeze'],
        ],
        [
            'gate_id'   => 'tenant_isolation',
            'dimension' => 'security',
            'detail'    => 'Tenant boundary hardening BACKLOG-019-023 green; 42 tests PASS; commit 4767fd9',
            'score'     => 1.0,
            'evidence'  => ['commit' => '4767fd9', 'task' => 'Tenant Isolation Hardening'],
        ],
        [
            'gate_id'   => 'rollback_readiness',
            'dimension' => 'operational',
            'detail'    => 'DR-027 recovery validate PASS 8/0/0; BACKUP_RESTORE_RECOVERY.md runbook present; commit cae4eea',
            'score'     => 1.0,
            'evidence'  => ['commit' => 'cae4eea', 'task' => 'DR-027 Backup/Restore/Recovery'],
        ],
        // --- PILOT-035 new stages ---
        [
            'gate_id'   => 'easm_posture_readiness',
            'dimension' => 'easm',
            'detail'    => 'EASM-030 passive scan + EASM-031 posture history advisory-only; commit 59b6d75',
            'score'     => 1.0,
            'evidence'  => ['commit' => '59b6d75', 'task' => 'EASM-031 Posture History'],
        ],
        [
            'gate_id'   => 'obs_slo_readiness',
            'dimension' => 'technical',
            'detail'    => 'OBS-029 offline PASS FAIL=0; 17-panel Grafana dashboard; RUNTIME_OBSERVABILITY_SLO.md; commit b960c38',
            'score'     => 1.0,
            'evidence'  => ['commit' => 'b960c38', 'task' => 'OBS-029 Runtime Observability & SLO'],
        ],
        [
            'gate_id'   => 'pilot_matrix_validator',
            'dimension' => 'certification',
            'detail'    => 'PILOT-034 readiness matrix ADVISORY_ONLY SELF_APPROVE_BLOCKED PASS_THRESHOLD=0.80; commit 1b8d8db',
            'score'     => 1.0,
            'evidence'  => ['commit' => '1b8d8db', 'task' => 'PILOT-034 Pilot Readiness Matrix'],
        ],
        [
            'gate_id'   => 'ingestion_hardening',
            'dimension' => 'technical',
            'detail'    => 'INGESTION-025 async metrics poller + per-tenant rate limiter + bounded retry PASS; commit 7fcdd41',
            'score'     => 1.0,
            'evidence'  => ['commit' => '7fcdd41', 'task' => 'INGESTION-025 Backpressure Hardening'],
        ],
        [
            'gate_id'   => 'detection_rule_registry',
            'dimension' => 'technical',
            'detail'    => '133 rules, 12 staged_active, 21/21 registry checks PASS; no ACTIVE_ALLOWLIST changes',
            'score'     => 1.0,
            'evidence'  => ['rules' => 133, 'staged_active' => 12, 'checks' => '21/21'],
        ],
        [
            'gate_id'   => 'advisory_boundary',
            'dimension' => 'security',
            'detail'    => 'endpoint/DNS/proxy/threat-intel remain shadow-only; no autonomous response; no live containment',
            'score'     => 1.0,
            'evidence'  => ['shadow_only' => true, 'autonomous_response' => false],
        ],
    ];

    public function handle(EnterprisePilotReadinessMatrixService $service): int
    {
        if ($this->option('list')) {
            return $this->listRuns();
        }

        $operator   = (string) $this->option('operator');
        $approvedBy = (string) $this->option('approved-by');
        $tenant     = (string) $this->option('tenant');
        $dryRun     = (bool)   $this->option('dry-run');
        $outputPath = (string) $this->option('output');

        if ($operator === $approvedBy) {
            $this->error("Self-approve is blocked: --operator and --approved-by must differ.");
            return 1;
        }

        $this->line("PILOT-LIVE-035: Pilot Evidence Freeze");
        $this->line("  operator   = {$operator}");
        $this->line("  approvedBy = {$approvedBy}");
        $this->line("  tenant     = {$tenant}");
        $this->line("  dry-run    = " . ($dryRun ? 'true' : 'false'));
        $this->line("  gates      = " . count(self::GATES));
        $this->line("  advisory   = true | autonomous_promotion = false");
        $this->line("");

        if ($dryRun) {
            return $this->printDryRun();
        }

        try {
            $run = $service->initiateMatrixRun(
                $tenant,
                $operator,
                ['pilot_live_035', 'evidence_freeze'],
            );
            $this->line("Initiated matrix run: {$run->matrix_run_id}");

            foreach (self::GATES as $gate) {
                $eval = $service->recordGateEvaluation(
                    $run,
                    $gate['gate_id'],
                    $gate['dimension'],
                    'pass',
                    $gate['detail'],
                    $gate['score'] ?? null,
                    $gate['evidence'] ?? [],
                );
                $service->linkEvidence(
                    $run,
                    $eval,
                    'pilot_live_035_freeze',
                    $gate['gate_id'],
                    $gate['evidence'] ?? [],
                );
                $this->line("  [+] {$gate['gate_id']} ({$gate['dimension']})");
            }

            $score = $service->computeMatrixScore($run);
            $this->line("");
            $this->line("Score  : " . round($score['score'] * 100, 1) . "% ({$score['passed']}/{$score['total']})");
            $this->line("Status : {$score['overall_status']}");
            $this->line("Req    : " . ($score['required_gates_pass'] ? 'PASS' : 'FAIL'));

            $summary = [
                'task'               => 'PILOT-LIVE-035',
                'matrix_run_id'      => $run->matrix_run_id,
                'operator_id'        => $operator,
                'approved_by'        => $approvedBy,
                'tenant_id'          => $tenant,
                'gates_recorded'     => count(self::GATES),
                'matrix_score'       => $score['score'],
                'overall_status'     => $score['overall_status'],
                'required_gates_pass' => $score['required_gates_pass'],
                'is_advisory'        => true,
                'autonomous_promotion' => false,
                'generated_at'       => now()->format('Y-m-d H:i:sP'),
            ];

            if ($outputPath) {
                file_put_contents($outputPath, json_encode($summary, JSON_PRETTY_PRINT));
                $this->line("JSON summary written to: {$outputPath}");
            }

            $this->line("");
            $this->line("Evidence freeze complete.  run_id={$run->matrix_run_id}");
            $this->line("Next: finalize the run via EnterprisePilotReadinessMatrixService::finalizeMatrixRun()");
            $this->line("      with a different operator and approver to unblock self-approve guard.");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Evidence freeze failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function printDryRun(): int
    {
        $this->line("[DRY RUN] Gates that would be recorded:");
        foreach (self::GATES as $i => $gate) {
            $n = $i + 1;
            $this->line("  {$n}. [{$gate['dimension']}] {$gate['gate_id']}");
            $this->line("       detail: {$gate['detail']}");
        }
        $this->line("");
        $this->line("[DRY RUN] No database records written.");
        return 0;
    }

    private function listRuns(): int
    {
        $runs = PilotReadinessMatrixRun::orderByDesc('id')->limit(15)->get();
        if ($runs->isEmpty()) {
            $this->line("No matrix runs found.");
            return 0;
        }
        $this->line("Recent pilot readiness matrix runs:");
        foreach ($runs as $run) {
            $score = number_format($run->matrix_score * 100, 1);
            $req   = $run->required_gates_pass ? '[REQ:PASS]' : '[REQ:FAIL]';
            $this->line("  {$req} {$run->matrix_run_id}  status={$run->status}  score={$score}%  op={$run->operator_id}");
        }
        return 0;
    }
}
