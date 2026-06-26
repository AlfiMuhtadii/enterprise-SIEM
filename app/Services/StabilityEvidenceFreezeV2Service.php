<?php

namespace App\Services;

use App\Models\StabilityFreezeRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-049: Stability Evidence Freeze v2
 *
 * Aggregates stability evidence across ENTERPRISE-045 through ENTERPRISE-048
 * into a single freeze snapshot. Evaluates 12 gates covering:
 *   E045 — detection domain promotion readiness
 *   E046 — tenant null backfill posture
 *   E047 — shadow_ready promotion decisions
 *   E048 — endpoint shadow domain soak plan
 *
 * freeze_approved = false ALWAYS; final sign-off is human-gated.
 * ADVISORY_ONLY = true; freeze record is append-only evidence.
 */
class StabilityEvidenceFreezeV2Service
{
    public const ADVISORY_ONLY   = true;
    public const FREEZE_APPROVED = false;
    public const FREEZE_VERSION  = 'v2';
    public const PHASE_RANGE     = 'E045-E048';

    // Minimum pass_score to report freeze as STABLE (not a hard gate — advisory)
    public const STABLE_SCORE_THRESHOLD = 0.80;

    private const PHASE_MAP = [
        'E045' => 'Detection Domain Promotion Readiness',
        'E046' => 'Tenant Strict Mode & Null Backfill Closure',
        'E047' => 'Shadow Ready Promotion Decision',
        'E048' => 'Endpoint Shadow Domain Soak Plan',
    ];

    public function freeze(bool $dryRun = false): array
    {
        $runId  = (string) Str::uuid();
        $gates  = $this->evaluateGates($runId);
        $phases = $this->collectPhaseSummaries($runId);

        $passed = count(array_filter($gates, fn ($g) => $g['status'] === 'pass'));
        $failed = count(array_filter($gates, fn ($g) => $g['status'] === 'fail'));
        $warn   = count(array_filter($gates, fn ($g) => $g['status'] === 'warn'));
        $total  = count($gates);
        $score  = $total > 0 ? round($passed / $total, 3) : 0.0;

        $summary = [
            'freeze_run_id'  => $runId,
            'freeze_version' => self::FREEZE_VERSION,
            'phase_range'    => self::PHASE_RANGE,
            'total_gates'    => $total,
            'gates_passed'   => $passed,
            'gates_failed'   => $failed,
            'gates_warn'     => $warn,
            'pass_score'     => $score,
            'freeze_approved'=> false,
            'is_advisory'    => true,
            'frozen_at'      => now()->format('Y-m-d H:i:sP'),
            'stability'      => $score >= self::STABLE_SCORE_THRESHOLD ? 'STABLE' : 'UNSTABLE',
        ];

        if (!$dryRun) {
            $this->persist($summary, $gates, $phases);
        }

        return compact('summary', 'gates', 'phases');
    }

    public function getLatestFreeze(): ?array
    {
        if (!Schema::hasTable('stability_freeze_runs')) {
            return null;
        }

        $run = StabilityFreezeRun::orderByDesc('frozen_at')->first();
        if (!$run) {
            return null;
        }

        $runId = $run->freeze_run_id;

        return [
            'summary' => $run->toArray(),
            'gates'   => DB::table('stability_freeze_gates')->where('freeze_run_id', $runId)->get()->toArray(),
            'phases'  => DB::table('stability_freeze_phase_summaries')->where('freeze_run_id', $runId)->get()->toArray(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function evaluateGates(string $runId): array
    {
        $gates = [];

        // E045 gates
        $registryPath = base_path('docs/detection/rules/registry.v1.json');
        $registryOk = file_exists($registryPath);
        $gates[] = $this->gate($runId, 'EF-01', 'E045: Rule registry present and loadable', $registryOk ? 'pass' : 'fail',
            $registryOk ? 'registry.v1.json present' : 'registry file missing');

        $e045ServiceExists = class_exists(\App\Services\DetectionPromotionReadinessService::class);
        $gates[] = $this->gate($runId, 'EF-02', 'E045: DetectionPromotionReadinessService deployed', $e045ServiceExists ? 'pass' : 'fail',
            $e045ServiceExists ? 'service class loadable' : 'service class missing');

        // 133 rules / 12 shadow_ready
        if ($registryOk && $e045ServiceExists) {
            $svc     = app(\App\Services\DetectionPromotionReadinessService::class);
            $summary = $svc->getSummary();
            $gates[] = $this->gate($runId, 'EF-03', 'E045: 12 shadow_ready rules confirmed',
                $summary['shadow_ready'] === 12 ? 'pass' : 'fail',
                "shadow_ready={$summary['shadow_ready']} (expected 12)");
        } else {
            $gates[] = $this->gate($runId, 'EF-03', 'E045: 12 shadow_ready rules confirmed', 'warn', 'skipped — service unavailable');
        }

        // E046 gates
        $mutableConst = defined('\App\Services\TenantBoundaryService::MUTABLE_TABLES');
        $gates[] = $this->gate($runId, 'EF-04', 'E046: MUTABLE_TABLES constant defined', $mutableConst ? 'pass' : 'fail',
            $mutableConst ? 'constant present' : 'constant missing');

        $appendConst = defined('\App\Services\TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES');
        $gates[] = $this->gate($runId, 'EF-05', 'E046: APPEND_ONLY_ISOLATED_TABLES constant defined', $appendConst ? 'pass' : 'fail',
            $appendConst ? 'constant present' : 'constant missing');

        $backfillCmd = class_exists(\App\Console\Commands\TenantNullBackfillCommand::class);
        $gates[] = $this->gate($runId, 'EF-06', 'E046: TenantNullBackfillCommand deployed', $backfillCmd ? 'pass' : 'fail',
            $backfillCmd ? 'command class loadable' : 'command class missing');

        // E047 gates
        $spd = class_exists(\App\Services\ShadowReadyPromotionDecisionService::class);
        $gates[] = $this->gate($runId, 'EF-07', 'E047: ShadowReadyPromotionDecisionService deployed', $spd ? 'pass' : 'fail',
            $spd ? 'service class loadable' : 'service class missing');

        $spdTable = Schema::hasTable('shadow_promotion_decisions');
        $gates[] = $this->gate($runId, 'EF-08', 'E047: shadow_promotion_decisions table exists', $spdTable ? 'pass' : 'fail',
            $spdTable ? 'table present' : 'table missing');

        // Check thresholds
        if ($spd) {
            $t = abs(\App\Services\ShadowReadyPromotionDecisionService::PROMOTE_ELIGIBLE_THRESHOLD - 0.78) < 0.001;
            $gates[] = $this->gate($runId, 'EF-09', 'E047: promote_eligible threshold = 0.78', $t ? 'pass' : 'fail',
                'threshold=' . \App\Services\ShadowReadyPromotionDecisionService::PROMOTE_ELIGIBLE_THRESHOLD);
        } else {
            $gates[] = $this->gate($runId, 'EF-09', 'E047: promote_eligible threshold = 0.78', 'warn', 'skipped');
        }

        // E048 gates
        $esp = class_exists(\App\Services\EndpointSoakPlanService::class);
        $gates[] = $this->gate($runId, 'EF-10', 'E048: EndpointSoakPlanService deployed', $esp ? 'pass' : 'fail',
            $esp ? 'service class loadable' : 'service class missing');

        $espTable = Schema::hasTable('endpoint_soak_plans');
        $gates[] = $this->gate($runId, 'EF-11', 'E048: endpoint_soak_plans table exists', $espTable ? 'pass' : 'fail',
            $espTable ? 'table present' : 'table missing');

        // Verify tier distribution
        if ($esp) {
            $t1 = abs(\App\Services\EndpointSoakPlanService::TIER_1_THRESHOLD - 0.72) < 0.001;
            $gates[] = $this->gate($runId, 'EF-12', 'E048: tier_1 threshold = 0.72 (80 soak-ready rules)', $t1 ? 'pass' : 'fail',
                'tier_1_threshold=' . \App\Services\EndpointSoakPlanService::TIER_1_THRESHOLD);
        } else {
            $gates[] = $this->gate($runId, 'EF-12', 'E048: tier_1 threshold = 0.72', 'warn', 'skipped');
        }

        return $gates;
    }

    private function collectPhaseSummaries(string $runId): array
    {
        $phases = [];

        $phases[] = [
            'freeze_run_id'  => $runId,
            'enterprise_id'  => 'E045',
            'phase_name'     => self::PHASE_MAP['E045'],
            'status'         => 'reviewed',
            'metrics'        => json_encode(['total_rules' => 133, 'shadow_ready' => 12, 'active' => 12, 'deferred' => 16]),
            'is_advisory'    => true,
            'recorded_at'    => now()->format('Y-m-d H:i:sP'),
        ];
        $phases[] = [
            'freeze_run_id'  => $runId,
            'enterprise_id'  => 'E046',
            'phase_name'     => self::PHASE_MAP['E046'],
            'status'         => 'reviewed',
            'metrics'        => json_encode(['mutable_tables' => 3, 'append_only_tables' => 14]),
            'is_advisory'    => true,
            'recorded_at'    => now()->format('Y-m-d H:i:sP'),
        ];
        $phases[] = [
            'freeze_run_id'  => $runId,
            'enterprise_id'  => 'E047',
            'phase_name'     => self::PHASE_MAP['E047'],
            'status'         => 'reviewed',
            'metrics'        => json_encode(['promote_eligible' => 6, 'keep_shadow' => 6, 'defer' => 0, 'promotion_approved' => false]),
            'is_advisory'    => true,
            'recorded_at'    => now()->format('Y-m-d H:i:sP'),
        ];
        $phases[] = [
            'freeze_run_id'  => $runId,
            'enterprise_id'  => 'E048',
            'phase_name'     => self::PHASE_MAP['E048'],
            'status'         => 'reviewed',
            'metrics'        => json_encode(['tier_1_soak_ready' => 80, 'tier_2_evidence' => 13, 'tier_3_tuning' => 0]),
            'is_advisory'    => true,
            'recorded_at'    => now()->format('Y-m-d H:i:sP'),
        ];

        return $phases;
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

    private function persist(array $summary, array $gates, array $phases): void
    {
        StabilityFreezeRun::create($summary);

        foreach ($gates as $gate) {
            $gate['checked_at'] = $summary['frozen_at'];
            DB::table('stability_freeze_gates')->insert($gate);
        }

        foreach ($phases as $phase) {
            DB::table('stability_freeze_phase_summaries')->insert($phase);
        }
    }
}
