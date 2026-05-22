<?php

namespace App\Services;

use App\Models\OperationalIntelligenceSnapshot;
use App\Models\AnalystInvestigationSummary;
use App\Models\DetectionConfidenceHistory;
use App\Models\FalsePositiveDriftReport;
use App\Models\AttackProgressionScore;
use App\Models\ChainedInvestigationView;
use App\Models\ReplayConfidenceValidation;
use App\Models\SuppressionEffectivenessReport;
use App\Models\AnalystAcknowledgmentPattern;
use Illuminate\Support\Str;

class OperationalIntelligenceService
{
    public const ADVISORY_ONLY = true;

    // Confidence thresholds
    public const MIN_CONFIDENCE          = 0.0;
    public const MAX_CONFIDENCE          = 1.0;
    public const DRIFT_ALERT_THRESHOLD   = 0.10;  // 10% drift triggers advisory
    public const MAX_SUPPRESSION_WINDOW  = 30;    // days

    // Graph traversal bounds
    public const MAX_TRAVERSAL_DEPTH     = 10;
    public const MAX_CHAIN_NODES         = 50;

    // Attack progression thresholds
    public const MIN_TACTIC_COUNT_FOR_CHAIN = 2;
    public const MAX_TACTIC_SEQUENCE_LENGTH = 10;

    // =========================================================================
    // Operational intelligence snapshots
    // =========================================================================

    public function recordSnapshot(
        string $tenantId,
        string $snapshotType,
        array  $metrics = []
    ): OperationalIntelligenceSnapshot {
        if (!in_array($snapshotType, OperationalIntelligenceSnapshot::SNAPSHOT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid snapshot type: {$snapshotType}");
        }

        $activeRules  = $metrics['active_rules']         ?? 0;
        $shadowRules  = $metrics['shadow_rules']         ?? 0;
        $avgConf      = $metrics['avg_confidence']       ?? 0.0;
        $alertCount   = $metrics['alert_count']          ?? 0;
        $fpCount      = $metrics['false_positive_count'] ?? 0;
        $chainedDet   = $metrics['chained_detections']   ?? 0;

        $fpRate       = $alertCount > 0 ? ($fpCount / $alertCount) : 0.0;
        $coverageScore= min(1.0, ($activeRules + $shadowRules * 0.3) / 150.0);

        return OperationalIntelligenceSnapshot::create([
            'snapshot_id'         => 'ois-' . Str::uuid(),
            'tenant_id'           => $tenantId,
            'snapshot_type'       => $snapshotType,
            'active_rules'        => $activeRules,
            'shadow_rules'        => $shadowRules,
            'avg_confidence'      => $avgConf,
            'alert_count'         => $alertCount,
            'false_positive_count'=> $fpCount,
            'false_positive_rate' => $fpRate,
            'chained_detections'  => $chainedDet,
            'coverage_score'      => $coverageScore,
            'is_advisory'         => true,
            'summary'             => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Analyst investigation summaries
    // =========================================================================

    public function recordInvestigationSummary(
        string $tenantId,
        string $analystId,
        string $investigationId,
        string $verdict,
        array  $params = []
    ): AnalystInvestigationSummary {
        if (!in_array($verdict, AnalystInvestigationSummary::VERDICTS, true)) {
            throw new \InvalidArgumentException("Invalid verdict: {$verdict}");
        }

        $confidenceScore = min(self::MAX_CONFIDENCE, max(self::MIN_CONFIDENCE,
            $params['confidence_score'] ?? 0.0
        ));

        return AnalystInvestigationSummary::create([
            'summary_id'       => 'ais-' . Str::uuid(),
            'tenant_id'        => $tenantId,
            'analyst_id'       => $analystId,
            'investigation_id' => $investigationId,
            'attack_tactic'    => $params['attack_tactic']    ?? null,
            'attack_technique' => $params['attack_technique'] ?? null,
            'evidence_count'   => $params['evidence_count']   ?? 0,
            'chained_count'    => $params['chained_count']    ?? 0,
            'confidence_score' => $confidenceScore,
            'verdict'          => $verdict,
            'replay_safe'      => true,
            'is_advisory'      => true,
            'evidence_links'   => $params['evidence_links']   ?? null,
        ]);
    }

    // =========================================================================
    // Detection confidence history
    // =========================================================================

    public function recordConfidenceHistory(
        string $ruleId,
        string $tenantId,
        float  $confidenceValue,
        string $source,
        bool   $replayConsistent = true,
        float  $driftDelta = 0.0,
        array  $metadata = []
    ): DetectionConfidenceHistory {
        if (!in_array($source, DetectionConfidenceHistory::SOURCES, true)) {
            throw new \InvalidArgumentException("Invalid confidence source: {$source}");
        }

        $clamped = min(self::MAX_CONFIDENCE, max(self::MIN_CONFIDENCE, $confidenceValue));

        return DetectionConfidenceHistory::create([
            'history_id'        => 'dch-' . Str::uuid(),
            'rule_id'           => $ruleId,
            'tenant_id'         => $tenantId,
            'confidence_value'  => $clamped,
            'confidence_source' => $source,
            'replay_consistent' => $replayConsistent,
            'drift_delta'       => $driftDelta,
            'is_advisory'       => true,
            'metadata'          => $metadata ?: null,
        ]);
    }

    // =========================================================================
    // False-positive drift reports
    // =========================================================================

    public function recordFpDriftReport(
        string $ruleId,
        string $tenantId,
        float  $fpRateCurrent,
        float  $fpRateBaseline,
        array  $params = []
    ): FalsePositiveDriftReport {
        $driftMagnitude = abs($fpRateCurrent - $fpRateBaseline);
        $driftDirection = $fpRateCurrent > $fpRateBaseline ? 'increasing'
                        : ($fpRateCurrent < $fpRateBaseline ? 'decreasing' : 'stable');

        return FalsePositiveDriftReport::create([
            'report_id'               => 'fpd-' . Str::uuid(),
            'rule_id'                 => $ruleId,
            'tenant_id'               => $tenantId,
            'fp_rate_current'         => $fpRateCurrent,
            'fp_rate_baseline'        => $fpRateBaseline,
            'drift_magnitude'         => $driftMagnitude,
            'drift_direction'         => $driftDirection,
            'probable_cause'          => $params['probable_cause'] ?? null,
            'suppression_recommended' => $driftMagnitude >= self::DRIFT_ALERT_THRESHOLD,
            'is_advisory'             => true,
            'evidence'                => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Attack progression scoring
    // =========================================================================

    public function scoreAttackProgression(
        string $tenantId,
        string $attackChainId,
        array  $tactics,
        array  $params = []
    ): AttackProgressionScore {
        $tacticCount = count($tactics);

        if ($tacticCount > self::MAX_TACTIC_SEQUENCE_LENGTH) {
            throw new \OverflowException(
                "Tactic sequence length {$tacticCount} exceeds maximum " . self::MAX_TACTIC_SEQUENCE_LENGTH . '.'
            );
        }

        $tacticSequence  = implode('→', $tactics);
        $progressionScore= min(1.0, $tacticCount / 5.0);
        $confidenceScore = $params['confidence_score'] ?? min(1.0, $progressionScore * 0.85);
        $chainedConfirmed= $tacticCount >= self::MIN_TACTIC_COUNT_FOR_CHAIN;

        return AttackProgressionScore::create([
            'score_id'          => 'aps-' . Str::uuid(),
            'tenant_id'         => $tenantId,
            'attack_chain_id'   => $attackChainId,
            'tactic_sequence'   => $tacticSequence,
            'tactic_count'      => $tacticCount,
            'progression_score' => $progressionScore,
            'confidence_score'  => $confidenceScore,
            'chained_confirmed' => $chainedConfirmed,
            'replay_validated'  => $params['replay_validated'] ?? false,
            'is_advisory'       => true,
            'chain_evidence'    => $params['chain_evidence']   ?? null,
        ]);
    }

    // =========================================================================
    // Chained investigation views (mutable)
    // =========================================================================

    public function createChainedView(
        string $tenantId,
        string $investigationId,
        array  $params = []
    ): ChainedInvestigationView {
        $depth = min(ChainedInvestigationView::MAX_DEPTH, $params['depth'] ?? 1);

        return ChainedInvestigationView::create([
            'view_id'           => 'civ-' . Str::uuid(),
            'tenant_id'         => $tenantId,
            'investigation_id'  => $investigationId,
            'status'            => 'active',
            'depth'             => $depth,
            'node_count'        => $params['node_count'] ?? 0,
            'edge_count'        => $params['edge_count'] ?? 0,
            'bounded_traversal' => true,
            'is_advisory'       => true,
            'view_state'        => $params['view_state'] ?? null,
        ]);
    }

    public function archiveChainedView(ChainedInvestigationView $view): ChainedInvestigationView
    {
        $view->update(['status' => 'archived']);
        return $view->fresh();
    }

    // =========================================================================
    // Replay confidence validation
    // =========================================================================

    public function recordReplayConfidenceValidation(
        string $ruleId,
        string $tenantId,
        float  $originalConfidence,
        float  $replayConfidence,
        array  $params = []
    ): ReplayConfidenceValidation {
        $delta          = $replayConfidence - $originalConfidence;
        $replayConsistent = abs($delta) <= self::DRIFT_ALERT_THRESHOLD;
        $verdict        = $replayConsistent ? 'consistent' : (abs($delta) > 0.25 ? 'drifted' : 'inconclusive');

        return ReplayConfidenceValidation::create([
            'validation_id'     => 'rcv-' . Str::uuid(),
            'rule_id'           => $ruleId,
            'tenant_id'         => $tenantId,
            'original_confidence'=> $originalConfidence,
            'replay_confidence' => $replayConfidence,
            'confidence_delta'  => $delta,
            'replay_consistent' => $replayConsistent,
            'verdict'           => $verdict,
            'is_advisory'       => true,
            'replay_evidence'   => $params['replay_evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Suppression effectiveness
    // =========================================================================

    public function recordSuppressionEffectiveness(
        string $ruleId,
        string $tenantId,
        string $suppressionKey,
        int    $suppressedCount,
        int    $fpPrevented,
        int    $tpSuppressed,
        array  $params = []
    ): SuppressionEffectivenessReport {
        $effectivenessScore = $suppressedCount > 0
            ? min(1.0, $fpPrevented / $suppressedCount)
            : 0.0;

        $suppressionSafe = $tpSuppressed === 0;

        return SuppressionEffectivenessReport::create([
            'report_id'           => 'ser-' . Str::uuid(),
            'rule_id'             => $ruleId,
            'tenant_id'           => $tenantId,
            'suppression_key'     => $suppressionKey,
            'suppressed_count'    => $suppressedCount,
            'fp_prevented'        => $fpPrevented,
            'tp_suppressed'       => $tpSuppressed,
            'effectiveness_score' => $effectivenessScore,
            'suppression_safe'    => $suppressionSafe,
            'is_advisory'         => true,
            'evidence'            => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Analyst acknowledgment patterns
    // =========================================================================

    public function recordAcknowledgmentPattern(
        string $analystId,
        string $tenantId,
        string $ruleId,
        string $ackType,
        float  $responseLatencySeconds,
        array  $params = []
    ): AnalystAcknowledgmentPattern {
        if (!in_array($ackType, AnalystAcknowledgmentPattern::ACK_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid acknowledgment type: {$ackType}");
        }

        return AnalystAcknowledgmentPattern::create([
            'pattern_id'               => 'aap-' . Str::uuid(),
            'analyst_id'               => $analystId,
            'tenant_id'                => $tenantId,
            'rule_id'                  => $ruleId,
            'acknowledgment_type'      => $ackType,
            'response_latency_seconds' => $responseLatencySeconds,
            'replay_consistent'        => true,
            'is_advisory'              => true,
            'context'                  => $params['context'] ?? null,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'snapshots'            => OperationalIntelligenceSnapshot::count(),
            'investigations'       => AnalystInvestigationSummary::count(),
            'confirmed_tp'         => AnalystInvestigationSummary::where('verdict', 'confirmed')->count(),
            'dismissed_fp'         => AnalystInvestigationSummary::where('verdict', 'dismissed')->count(),
            'drift_reports'        => FalsePositiveDriftReport::count(),
            'suppression_recs'     => FalsePositiveDriftReport::where('suppression_recommended', true)->count(),
            'attack_chains'        => AttackProgressionScore::where('chained_confirmed', true)->count(),
            'replay_consistent'    => ReplayConfidenceValidation::where('verdict', 'consistent')->count(),
            'replay_drifted'       => ReplayConfidenceValidation::where('verdict', 'drifted')->count(),
            'active_views'         => ChainedInvestigationView::where('status', 'active')->count(),
        ];
    }
}
