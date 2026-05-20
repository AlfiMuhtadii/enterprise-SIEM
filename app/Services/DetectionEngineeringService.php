<?php

namespace App\Services;

use App\Models\DetectionAttackMapping;
use App\Models\DetectionFalsePositiveReport;
use App\Models\DetectionPromotionRequest;
use App\Models\DetectionQualityMetric;
use App\Models\DetectionReplayPack;
use App\Models\DetectionReplayResult;
use App\Models\DetectionRuleTestCase;
use App\Models\DetectionRuleVersion;
use App\Models\DetectionSuppression;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detection Engineering Lifecycle Service — Phase 1.
 *
 * Governs rule versioning, replay testing, false-positive tracking,
 * suppression management, ATT&CK mapping, and promotion workflow.
 *
 * Hard constraints:
 * - No autonomous rule promotion — every promotion requires operator approval.
 * - No automatic suppression — every suppression requires operator creation + approval.
 * - No destructive mutation of historical rule versions.
 * - No isolateHost, quarantineHost, executeShell, killProcess, autoRemediate.
 * - ACTIVE_ALLOWLIST must remain empty.
 */
class DetectionEngineeringService
{
    public function __construct(private RuleRegistryService $registry) {}

    // -------------------------------------------------------------------------
    // Rule versioning — append-only
    // -------------------------------------------------------------------------

    /**
     * Create an immutable version snapshot of the current rule state.
     * Called when rule metadata changes or promotion state transitions.
     */
    public function snapshotRuleVersion(
        array $rule,
        ?User $author,
        string $changeReason,
        ?string $previousVersionId = null,
    ): DetectionRuleVersion {
        return DetectionRuleVersion::create([
            'version_id'          => DetectionRuleVersion::generateVersionId(),
            'rule_id'             => $rule['rule_id'],
            'version'             => $rule['version'] ?? 'v1',
            'rule_hash'           => DetectionRuleVersion::hashRule($rule),
            'status'              => $rule['status'] ?? 'shadow',
            'stage'               => $rule['db_state']->stage ?? 'shadow',
            'shadow_only'         => (bool) ($rule['shadow_only'] ?? true),
            'output_topic'        => $rule['output_topic'] ?? null,
            'owner'               => $rule['owner'] ?? null,
            'created_by'          => $author?->id,
            'change_reason'       => $changeReason,
            'previous_version_id' => $previousVersionId,
            'rule_snapshot'       => $this->buildSnapshot($rule),
            'created_at'          => now(),
        ]);
    }

    /**
     * Get the version history for a rule, newest first.
     */
    public function getVersionHistory(string $ruleId): Collection
    {
        return DetectionRuleVersion::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Get the latest version snapshot for a rule.
     */
    public function getLatestVersion(string $ruleId): ?DetectionRuleVersion
    {
        return DetectionRuleVersion::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    // -------------------------------------------------------------------------
    // Test cases — mutable
    // -------------------------------------------------------------------------

    public function createTestCase(string $ruleId, array $payload, ?User $author = null): DetectionRuleTestCase
    {
        return DetectionRuleTestCase::create([
            'test_case_id'     => DetectionRuleTestCase::generateTestCaseId(),
            'rule_id'          => $ruleId,
            'name'             => $payload['name'] ?? 'Unnamed test case',
            'description'      => $payload['description'] ?? null,
            'expected_outcome' => $payload['expected_outcome'] ?? DetectionRuleTestCase::OUTCOME_TRUE_POSITIVE,
            'event_fixtures'   => $payload['event_fixtures'] ?? null,
            'fixture_path'     => $payload['fixture_path'] ?? null,
            'expected_fields'  => $payload['expected_fields'] ?? null,
            'expected_severity'=> $payload['expected_severity'] ?? null,
            'expect_trace_id'  => (bool) ($payload['expect_trace_id'] ?? true),
            'is_active'        => true,
            'created_by'       => $author?->id,
        ]);
    }

    public function getTestCases(string $ruleId): Collection
    {
        return DetectionRuleTestCase::where('rule_id', $ruleId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Replay packs — mutable
    // -------------------------------------------------------------------------

    public function createReplayPack(string $ruleId, array $payload, ?User $author = null): DetectionReplayPack
    {
        return DetectionReplayPack::create([
            'pack_id'                  => DetectionReplayPack::generatePackId(),
            'rule_id'                  => $ruleId,
            'name'                     => $payload['name'] ?? 'Unnamed replay pack',
            'description'              => $payload['description'] ?? null,
            'expected_match_count'     => (int) ($payload['expected_match_count'] ?? 0),
            'expected_non_match_count' => (int) ($payload['expected_non_match_count'] ?? 0),
            'pack_version'             => $payload['pack_version'] ?? 'v1',
            'is_active'                => true,
            'created_by'               => $author?->id,
        ]);
    }

    public function getReplayPacks(string $ruleId): Collection
    {
        return DetectionReplayPack::where('rule_id', $ruleId)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Replay results — append-only
    // -------------------------------------------------------------------------

    /**
     * Record the result of running a replay pack.
     * Deterministic: same inputs always produce same pass/fail outcome.
     * Advisory-only — unexpected_enforcement=true is a FAIL condition.
     */
    public function recordReplayResult(
        DetectionReplayPack $pack,
        array $payload,
        ?User $triggeredBy = null,
    ): DetectionReplayResult {
        return DetectionReplayResult::create([
            'result_id'              => DetectionReplayResult::generateResultId(),
            'pack_id'                => $pack->pack_id,
            'rule_id'                => $pack->rule_id,
            'passed'                 => (bool) ($payload['passed'] ?? false),
            'cases_run'              => (int) ($payload['cases_run'] ?? 0),
            'cases_passed'           => (int) ($payload['cases_passed'] ?? 0),
            'cases_failed'           => (int) ($payload['cases_failed'] ?? 0),
            'failure_details'        => $payload['failure_details'] ?? null,
            'evidence_mismatch'      => (bool) ($payload['evidence_mismatch'] ?? false),
            'trace_id_missing'       => (bool) ($payload['trace_id_missing'] ?? false),
            'unexpected_enforcement' => (bool) ($payload['unexpected_enforcement'] ?? false),
            'runner'                 => $payload['runner'] ?? 'manual',
            'triggered_by'           => $triggeredBy?->id,
            'report_path'            => $payload['report_path'] ?? null,
            'created_at'             => now(),
        ]);
    }

    public function getReplayResults(string $ruleId, int $limit = 50): Collection
    {
        return DetectionReplayResult::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get the latest replay result for a rule — used in promotion gate check.
     */
    public function getLatestReplayResult(string $ruleId): ?DetectionReplayResult
    {
        return DetectionReplayResult::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    // -------------------------------------------------------------------------
    // False-positive reports — append-only
    // -------------------------------------------------------------------------

    /**
     * Record an analyst FP report. Does NOT automatically suppress — suppression
     * requires a separate operator-approved action.
     */
    public function reportFalsePositive(string $ruleId, array $payload, ?User $reporter = null): DetectionFalsePositiveReport
    {
        return DetectionFalsePositiveReport::create([
            'report_id'              => DetectionFalsePositiveReport::generateReportId(),
            'rule_id'                => $ruleId,
            'reported_by'            => $reporter?->id,
            'reason_type'            => $payload['reason_type'] ?? DetectionFalsePositiveReport::REASON_OTHER,
            'reason_detail'          => $payload['reason_detail'] ?? '',
            'alert_id'               => $payload['alert_id'] ?? null,
            'trace_id'               => $payload['trace_id'] ?? null,
            'event_evidence'         => $payload['event_evidence'] ?? null,
            'recommends_suppression' => (bool) ($payload['recommends_suppression'] ?? false),
            'suppression_scope'      => $payload['suppression_scope'] ?? null,
            'analyst_verdict'        => DetectionFalsePositiveReport::VERDICT_UNDER_REVIEW,
            'created_at'             => now(),
        ]);
    }

    public function getFalsePositiveReports(string $ruleId, int $days = 30): Collection
    {
        return DetectionFalsePositiveReport::where('rule_id', $ruleId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get();
    }

    public function countFalsePositiveReports(string $ruleId, int $days = 7): int
    {
        return DetectionFalsePositiveReport::where('rule_id', $ruleId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    // -------------------------------------------------------------------------
    // Suppression governance — mutable, approval-gated
    // -------------------------------------------------------------------------

    /**
     * Create a suppression request. Always starts in 'pending' state.
     * No automatic activation — requires operator approval.
     */
    public function createSuppression(string $ruleId, array $payload, ?User $creator = null): DetectionSuppression
    {
        return DetectionSuppression::create([
            'suppression_id' => DetectionSuppression::generateSuppressionId(),
            'rule_id'        => $ruleId,
            'scope'          => $payload['scope'] ?? DetectionSuppression::SCOPE_GLOBAL,
            'scope_value'    => $payload['scope_value'] ?? null,
            'reason'         => $payload['reason'] ?? '',
            'created_by'     => $creator?->id,
            'approval_state' => DetectionSuppression::STATE_PENDING,
            'expires_at'     => $payload['expires_at'] ?? null,
            'is_active'      => false,  // never active until approved
            'fp_report_id'   => $payload['fp_report_id'] ?? null,
        ]);
    }

    /**
     * Approve a suppression. Only then does is_active become true.
     */
    public function approveSuppression(DetectionSuppression $suppression, string $approvedBy): DetectionSuppression
    {
        $suppression->update([
            'approval_state' => DetectionSuppression::STATE_APPROVED,
            'approved_by'    => $approvedBy,
            'approved_at'    => now(),
            'is_active'      => true,
        ]);
        return $suppression;
    }

    public function revokeSuppression(DetectionSuppression $suppression): DetectionSuppression
    {
        $suppression->update([
            'approval_state' => DetectionSuppression::STATE_REVOKED,
            'is_active'      => false,
        ]);
        return $suppression;
    }

    public function getSuppressions(string $ruleId): Collection
    {
        return DetectionSuppression::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Suppressions expiring within $days — for expiry risk dashboard.
     */
    public function getExpiringSuppressions(int $days = 7): Collection
    {
        return DetectionSuppression::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->orderBy('expires_at')
            ->get();
    }

    // -------------------------------------------------------------------------
    // ATT&CK mappings — mutable
    // -------------------------------------------------------------------------

    public function mapAttackTechnique(string $ruleId, array $payload, ?User $creator = null): DetectionAttackMapping
    {
        return DetectionAttackMapping::create([
            'mapping_id'        => DetectionAttackMapping::generateMappingId(),
            'rule_id'           => $ruleId,
            'tactic'            => $payload['tactic'] ?? '',
            'technique_id'      => $payload['technique_id'] ?? '',
            'technique_name'    => $payload['technique_name'] ?? '',
            'sub_technique_id'  => $payload['sub_technique_id'] ?? null,
            'confidence'        => (float) ($payload['confidence'] ?? 0.75),
            'mapping_source'    => $payload['mapping_source'] ?? DetectionAttackMapping::SOURCE_ANALYST,
            'evidence_reference'=> $payload['evidence_reference'] ?? null,
            'created_by'        => $creator?->id,
            'is_active'         => true,
        ]);
    }

    public function getAttackMappings(string $ruleId): Collection
    {
        return DetectionAttackMapping::where('rule_id', $ruleId)
            ->where('is_active', true)
            ->orderBy('technique_id')
            ->get();
    }

    /**
     * Fleet-wide ATT&CK coverage summary grouped by tactic.
     */
    public function getAttackCoverageSummary(): Collection
    {
        return DB::table('detection_attack_mappings')
            ->where('is_active', true)
            ->select('tactic', DB::raw('count(distinct technique_id) as technique_count'), DB::raw('count(distinct rule_id) as rule_count'))
            ->groupBy('tactic')
            ->orderBy('tactic')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Promotion requests — append-only
    // -------------------------------------------------------------------------

    /**
     * Create a formal promotion request. No automatic promotion — operator review required.
     * Validates gates at time of request and stores snapshot.
     */
    public function createPromotionRequest(
        array $rule,
        string $toStage,
        array $payload,
        ?User $requester = null,
    ): DetectionPromotionRequest {
        $gateValidation = $this->registry->validatePromotion($rule, $toStage);
        $latestFpCount  = $this->countFalsePositiveReports($rule['rule_id'], 30);
        $latestReplay   = $this->getLatestReplayResult($rule['rule_id']);

        return DetectionPromotionRequest::create([
            'request_id'       => DetectionPromotionRequest::generateRequestId(),
            'rule_id'          => $rule['rule_id'],
            'from_stage'       => $rule['db_state']->stage,
            'to_stage'         => $toStage,
            'requested_by'     => $requester?->id,
            'rationale'        => $payload['rationale'] ?? null,
            'status'           => DetectionPromotionRequest::STATUS_PENDING,
            'replay_result_id' => $latestReplay?->result_id,
            'soak_report_path' => $payload['soak_report_path'] ?? null,
            'gate_snapshot'    => $gateValidation,
            'fp_summary'       => ['count_30d' => $latestFpCount],
            'created_at'       => now(),
        ]);
    }

    /**
     * Approve a promotion request. Does NOT automatically promote the rule —
     * the operator must still trigger the actual promotion via the existing
     * RuleRegistryService::validatePromotion() → DetectionRuleController::promote() flow.
     */
    public function approvePromotionRequest(DetectionPromotionRequest $request, ?User $reviewer, string $note = ''): DetectionPromotionRequest
    {
        // promotion_requests are append-only; we UPDATE status only (not append semantics)
        // because the request is the tracking record, not the audit log itself
        $request->update([
            'status'      => DetectionPromotionRequest::STATUS_APPROVED,
            'reviewed_by' => $reviewer?->name ?? 'system',
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);
        return $request;
    }

    public function rejectPromotionRequest(DetectionPromotionRequest $request, ?User $reviewer, string $note = ''): DetectionPromotionRequest
    {
        $request->update([
            'status'      => DetectionPromotionRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer?->name ?? 'system',
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);
        return $request;
    }

    public function getPromotionRequests(string $ruleId): Collection
    {
        return DetectionPromotionRequest::where('rule_id', $ruleId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getPendingPromotionRequests(): Collection
    {
        return DetectionPromotionRequest::where('status', DetectionPromotionRequest::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Quality metrics — mutable, recomputed
    // -------------------------------------------------------------------------

    /**
     * Compute and persist the current quality score for a rule.
     * Deterministic — same evidence always produces same score.
     */
    public function computeQualityMetric(string $ruleId): DetectionQualityMetric
    {
        $replayPass = DetectionReplayResult::where('rule_id', $ruleId)->where('passed', true)->count();
        $replayFail = DetectionReplayResult::where('rule_id', $ruleId)->where('passed', false)->count();
        $fpTotal    = DetectionFalsePositiveReport::where('rule_id', $ruleId)->count();
        $fp7d       = DetectionFalsePositiveReport::where('rule_id', $ruleId)->where('created_at', '>=', now()->subDays(7))->count();
        $fp30d      = DetectionFalsePositiveReport::where('rule_id', $ruleId)->where('created_at', '>=', now()->subDays(30))->count();
        $suppCount  = DetectionSuppression::where('rule_id', $ruleId)->where('is_active', true)->count();
        $versionCount = DetectionRuleVersion::where('rule_id', $ruleId)->count();

        // Quality score: starts at 1.0, penalised for each quality signal
        $score = 1.0;
        $score -= min($replayFail * 0.1, 0.4);         // each fail deducts 0.1, max 0.4
        $score -= min($fp7d * 0.05, 0.3);              // each 7d FP deducts 0.05, max 0.3
        $score -= min($suppCount * 0.05, 0.2);         // each active suppression deducts 0.05
        $score  = max(0.0, round($score, 3));

        // Trend: compare to previous score if exists
        $existing = DetectionQualityMetric::where('rule_id', $ruleId)->first();
        $trend = DetectionQualityMetric::TREND_STABLE;
        if ($existing) {
            if ($score > $existing->quality_score + 0.05) {
                $trend = DetectionQualityMetric::TREND_IMPROVING;
            } elseif ($score < $existing->quality_score - 0.05) {
                $trend = DetectionQualityMetric::TREND_DEGRADING;
            }
        }

        return DetectionQualityMetric::updateOrCreate(
            ['rule_id' => $ruleId],
            [
                'quality_score'     => $score,
                'replay_pass_count' => $replayPass,
                'replay_fail_count' => $replayFail,
                'fp_report_count'   => $fpTotal,
                'suppression_count' => $suppCount,
                'version_count'     => $versionCount,
                'fp_rate_7d'        => $fp7d > 0 ? round($fp7d / max($replayPass + $replayFail, 1), 4) : 0.0,
                'fp_rate_30d'       => $fp30d > 0 ? round($fp30d / max($replayPass + $replayFail, 1), 4) : 0.0,
                'quality_trend'     => $trend,
                'computed_at'       => now(),
            ]
        );
    }

    public function getDashboardStats(): array
    {
        return [
            'total_versions'     => DetectionRuleVersion::count(),
            'total_replay_packs' => DetectionReplayPack::where('is_active', true)->count(),
            'replay_pass_rate'   => $this->globalReplayPassRate(),
            'fp_reports_7d'      => DetectionFalsePositiveReport::where('created_at', '>=', now()->subDays(7))->count(),
            'active_suppressions'=> DetectionSuppression::where('is_active', true)->count(),
            'expiring_suppressions' => DetectionSuppression::where('is_active', true)->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(7))->count(),
            'pending_promotions' => DetectionPromotionRequest::where('status', 'pending')->count(),
            'attack_mappings'    => DetectionAttackMapping::where('is_active', true)->count(),
            'advisory_only'      => true,
            'autonomous_promotion'=> false,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildSnapshot(array $rule): array
    {
        return [
            'rule_id'              => $rule['rule_id'] ?? '',
            'version'              => $rule['version'] ?? 'v1',
            'status'               => $rule['status'] ?? 'shadow',
            'domain'               => $rule['domain'] ?? '',
            'severity'             => $rule['severity'] ?? '',
            'confidence'           => $rule['confidence'] ?? 0.0,
            'shadow_only'          => $rule['shadow_only'] ?? true,
            'output_topic'         => $rule['output_topic'] ?? '',
            'mitre_attack'         => $rule['mitre_attack'] ?? [],
            'suppression_key'      => $rule['suppression_key'] ?? '',
            'owner'                => $rule['owner'] ?? '',
            'stage'                => $rule['db_state']->stage ?? '',
            'replay_validated'     => $rule['db_state']->replay_validated ?? false,
        ];
    }

    private function globalReplayPassRate(): float
    {
        $total  = DetectionReplayResult::count();
        $passed = DetectionReplayResult::where('passed', true)->count();
        return $total > 0 ? round($passed / $total, 4) : 0.0;
    }
}
