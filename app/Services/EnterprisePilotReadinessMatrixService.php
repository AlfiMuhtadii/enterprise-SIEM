<?php

namespace App\Services;

use App\Models\PilotReadinessMatrixRun;
use App\Models\PilotReadinessGateEvaluation;
use App\Models\PilotReadinessEvidenceLink;
use Illuminate\Support\Str;

class EnterprisePilotReadinessMatrixService
{
    public const ADVISORY_ONLY        = true;
    public const SELF_APPROVE_BLOCKED = true;
    public const AUTONOMOUS_PROMOTION = false;
    public const MAX_GATES            = 20;
    public const PASS_THRESHOLD       = 0.80;

    public const REQUIRED_GATE_IDS = [
        'soak_validation',
        'replay_verification',
        'tenant_isolation',
        'rollback_readiness',
    ];

    public const DIMENSIONS = [
        'technical', 'operational', 'security', 'telemetry', 'easm', 'certification',
    ];

    // =========================================================================
    // Matrix run lifecycle
    // =========================================================================

    public function initiateMatrixRun(
        string $tenantId,
        string $operatorId,
        array  $pilotScope = [],
        array  $params = []
    ): PilotReadinessMatrixRun {
        return PilotReadinessMatrixRun::create([
            'matrix_run_id'       => 'prm-' . Str::uuid(),
            'tenant_id'           => $tenantId,
            'operator_id'         => $operatorId,
            'pilot_scope'         => $pilotScope ?: null,
            'status'              => 'initiated',
            'total_gates'         => 0,
            'gates_passed'        => 0,
            'gates_warned'        => 0,
            'gates_failed'        => 0,
            'required_gates_pass' => false,
            'matrix_score'        => 0.0,
            'is_advisory'         => true,
            'autonomous_promotion' => false,
        ]);
    }

    // =========================================================================
    // Gate evaluations
    // =========================================================================

    public function recordGateEvaluation(
        PilotReadinessMatrixRun $run,
        string $gateId,
        string $dimension,
        string $status,
        string $detail = '',
        ?float $score   = null,
        array  $evidence = []
    ): PilotReadinessGateEvaluation {
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new \InvalidArgumentException("Invalid dimension: {$dimension}");
        }

        if (!in_array($status, PilotReadinessGateEvaluation::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $existing = PilotReadinessGateEvaluation::where('matrix_run_id', $run->matrix_run_id)
            ->count();

        if ($existing >= self::MAX_GATES) {
            throw new \OverflowException(
                "Cannot record more than " . self::MAX_GATES . " gate evaluations per matrix run."
            );
        }

        return PilotReadinessGateEvaluation::create([
            'matrix_run_id' => $run->matrix_run_id,
            'gate_id'       => $gateId,
            'dimension'     => $dimension,
            'status'        => $status,
            'detail'        => $detail ?: null,
            'score'         => $score,
            'evidence'      => $evidence ?: null,
            'evaluated_at'  => now(),
        ]);
    }

    // =========================================================================
    // Evidence links
    // =========================================================================

    public function linkEvidence(
        PilotReadinessMatrixRun    $run,
        PilotReadinessGateEvaluation $gateEval,
        string $sourceType,
        string $sourceId,
        array  $evidenceData = []
    ): PilotReadinessEvidenceLink {
        return PilotReadinessEvidenceLink::create([
            'matrix_run_id' => $run->matrix_run_id,
            'gate_eval_id'  => $gateEval->id,
            'source_type'   => $sourceType,
            'source_id'     => $sourceId,
            'evidence_data' => $evidenceData ?: null,
            'linked_at'     => now(),
        ]);
    }

    // =========================================================================
    // Score computation
    // =========================================================================

    public function computeMatrixScore(PilotReadinessMatrixRun $run): array
    {
        $evals = PilotReadinessGateEvaluation::where('matrix_run_id', $run->matrix_run_id)->get();

        $total   = $evals->count();
        $passed  = $evals->where('status', 'pass')->count();
        $warned  = $evals->where('status', 'warn')->count();
        $failed  = $evals->where('status', 'fail')->count();
        $skipped = $evals->where('status', 'skip')->count();

        $countable = $total - $skipped;
        $score     = $countable > 0 ? round($passed / $countable, 4) : 0.0;

        $requiredGatesPass = true;
        foreach (self::REQUIRED_GATE_IDS as $requiredId) {
            $gate = $evals->firstWhere('gate_id', $requiredId);
            if ($gate && $gate->status === 'fail') {
                $requiredGatesPass = false;
                break;
            }
        }

        return [
            'score'               => $score,
            'total'               => $total,
            'passed'              => $passed,
            'warned'              => $warned,
            'failed'              => $failed,
            'skipped'             => $skipped,
            'required_gates_pass' => $requiredGatesPass,
            'overall_status'      => $this->resolveOverallStatus($score, $requiredGatesPass),
        ];
    }

    // =========================================================================
    // Finalize
    // =========================================================================

    public function finalizeMatrixRun(
        PilotReadinessMatrixRun $run,
        string $approvedBy,
        string $reviewerNotes = ''
    ): PilotReadinessMatrixRun {
        if ($approvedBy === $run->operator_id) {
            throw new \LogicException('Self-approval is blocked: approvedBy cannot match operator_id.');
        }

        $scoreData = $this->computeMatrixScore($run);

        $run->status              = $scoreData['overall_status'] === 'PASS' ? 'passed' : 'failed';
        $run->total_gates         = $scoreData['total'];
        $run->gates_passed        = $scoreData['passed'];
        $run->gates_warned        = $scoreData['warned'];
        $run->gates_failed        = $scoreData['failed'];
        $run->required_gates_pass = $scoreData['required_gates_pass'];
        $run->matrix_score        = $scoreData['score'];
        $run->finalized_at        = now();
        $run->approved_by         = $approvedBy;
        $run->reviewer_notes      = $reviewerNotes ?: null;
        $run->saveQuietly();

        return $run->refresh();
    }

    // =========================================================================
    // Report generation
    // =========================================================================

    public function generateMatrixReport(PilotReadinessMatrixRun $run): array
    {
        $evals = PilotReadinessGateEvaluation::where('matrix_run_id', $run->matrix_run_id)
            ->orderBy('dimension')
            ->get();

        $byDimension = [];
        foreach (self::DIMENSIONS as $dim) {
            $byDimension[$dim] = $evals->where('dimension', $dim)->values()->toArray();
        }

        $scoreData = $this->computeMatrixScore($run);

        return [
            'matrix_run_id'    => $run->matrix_run_id,
            'tenant_id'        => $run->tenant_id,
            'status'           => $run->status,
            'is_advisory'      => true,
            'autonomous_promotion' => false,
            'score'            => $scoreData,
            'by_dimension'     => $byDimension,
            'required_gates'   => self::REQUIRED_GATE_IDS,
            'generated_at'     => now()->toISOString(),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resolveOverallStatus(float $score, bool $requiredGatesPass): string
    {
        if (!$requiredGatesPass) {
            return 'FAIL';
        }

        if ($score >= self::PASS_THRESHOLD) {
            return 'PASS';
        }

        return 'FAIL';
    }
}
