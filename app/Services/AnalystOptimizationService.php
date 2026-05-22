<?php

namespace App\Services;

use App\Models\AnalystWorkloadSnapshot;
use App\Models\AlertPrioritizationScore;
use App\Models\FalsePositiveTuningReport;
use App\Models\AnalystAcknowledgmentAudit;
use App\Models\EscalationQualityReview;
use App\Models\InvestigationErgonomicView;
use App\Models\AlertRecurrenceReport;
use App\Models\OperationalFatigueIndicator;
use App\Models\ShiftHandoffValidation;
use Illuminate\Support\Str;

class AnalystOptimizationService
{
    public const ADVISORY_ONLY = true;

    // Workload thresholds
    public const OVERLOAD_WORKLOAD_SCORE       = 0.85;
    public const MAX_INVESTIGATIONS_PER_ANALYST = 20;
    public const FATIGUE_CONSECUTIVE_DISMISSALS = 10;
    public const FATIGUE_ACCELERATION_THRESHOLD = 2.0;

    // Prioritization amplification cap
    public const MAX_PRIORITY_AMPLIFICATION     = 2.5;

    // Suppression governance
    public const MAX_SUPPRESSION_DAYS           = 30;

    // Escalation quality thresholds
    public const MIN_QUALITY_SCORE_HIGH         = 0.80;
    public const MIN_QUALITY_SCORE_MEDIUM       = 0.50;

    // =========================================================================
    // Analyst workload snapshots
    // =========================================================================

    public function recordWorkloadSnapshot(
        string $analystId,
        string $tenantId,
        array  $metrics = [],
        string $shiftId = null
    ): AnalystWorkloadSnapshot {
        $openInv     = $metrics['open_investigations']              ?? 0;
        $pending     = $metrics['pending_acknowledgments']          ?? 0;
        $queueDepth  = $metrics['escalation_queue_depth']           ?? 0;
        $avgLatency  = $metrics['avg_acknowledgment_latency_seconds']?? 0.0;
        $completed   = $metrics['investigations_completed_last_8h'] ?? 0;

        $workloadScore = min(1.0,
            ($openInv / max(1, self::MAX_INVESTIGATIONS_PER_ANALYST)) * 0.5 +
            ($pending / 20.0) * 0.3 +
            ($queueDepth / 10.0) * 0.2
        );

        return AnalystWorkloadSnapshot::create([
            'snapshot_id'                       => 'aws-' . Str::uuid(),
            'analyst_id'                        => $analystId,
            'tenant_id'                         => $tenantId,
            'shift_id'                          => $shiftId,
            'open_investigations'               => $openInv,
            'pending_acknowledgments'           => $pending,
            'escalation_queue_depth'            => $queueDepth,
            'avg_acknowledgment_latency_seconds'=> $avgLatency,
            'investigations_completed_last_8h'  => $completed,
            'workload_score'                    => $workloadScore,
            'overload_indicator'                => $workloadScore >= self::OVERLOAD_WORKLOAD_SCORE,
            'is_advisory'                       => true,
            'metadata'                          => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Alert prioritization scoring
    // =========================================================================

    public function scoreAlertPrioritization(
        string $alertId,
        string $tenantId,
        string $ruleId,
        float  $baseSeverityScore,
        array  $factors = []
    ): AlertPrioritizationScore {
        $replayFactor     = min(self::MAX_PRIORITY_AMPLIFICATION, max(0.1, $factors['replay_confidence_factor']     ?? 1.0));
        $recurrenceFactor = min(self::MAX_PRIORITY_AMPLIFICATION, max(0.1, $factors['recurrence_factor']            ?? 1.0));
        $escalationFactor = min(self::MAX_PRIORITY_AMPLIFICATION, max(0.1, $factors['escalation_frequency_factor']  ?? 1.0));

        $finalScore = min(1.0, $baseSeverityScore * $replayFactor * min(1.5, $recurrenceFactor * $escalationFactor));

        $tier = match(true) {
            $finalScore >= 0.85 => 'critical',
            $finalScore >= 0.65 => 'high',
            $finalScore >= 0.40 => 'medium',
            default             => 'low',
        };

        return AlertPrioritizationScore::create([
            'score_id'                   => 'aps-' . Str::uuid(),
            'alert_id'                   => $alertId,
            'tenant_id'                  => $tenantId,
            'rule_id'                    => $ruleId,
            'base_severity_score'        => $baseSeverityScore,
            'replay_confidence_factor'   => $replayFactor,
            'recurrence_factor'          => $recurrenceFactor,
            'escalation_frequency_factor'=> $escalationFactor,
            'final_priority_score'       => $finalScore,
            'priority_tier'              => $tier,
            'replay_validated'           => $factors['replay_validated'] ?? false,
            'is_advisory'                => true,
            'scoring_factors'            => $factors ?: null,
        ]);
    }

    // =========================================================================
    // False-positive tuning
    // =========================================================================

    public function recordFpTuningReport(
        string $ruleId,
        string $tenantId,
        string $analystId,
        string $tuningAction,
        float  $fpRateBefore,
        array  $params = []
    ): FalsePositiveTuningReport {
        if (!in_array($tuningAction, FalsePositiveTuningReport::TUNING_ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid tuning action: {$tuningAction}");
        }

        $suppressionDays = $params['suppression_duration_days'] ?? null;
        if ($suppressionDays !== null && $suppressionDays > self::MAX_SUPPRESSION_DAYS) {
            throw new \OverflowException(
                "Suppression duration {$suppressionDays} days exceeds maximum " . self::MAX_SUPPRESSION_DAYS . '.'
            );
        }

        return FalsePositiveTuningReport::create([
            'report_id'               => 'fpt-' . Str::uuid(),
            'rule_id'                 => $ruleId,
            'tenant_id'               => $tenantId,
            'analyst_id'              => $analystId,
            'tuning_action'           => $tuningAction,
            'suppression_scope'       => $params['suppression_scope']       ?? null,
            'suppression_duration_days'=> $suppressionDays,
            'fp_rate_before'          => $fpRateBefore,
            'fp_rate_after_estimate'  => $params['fp_rate_after_estimate']  ?? 0.0,
            'replay_validated'        => $params['replay_validated']        ?? false,
            'expiry_tracked'          => $suppressionDays !== null,
            'is_advisory'             => true,
            'evidence'                => $params['evidence']                ?? null,
        ]);
    }

    // =========================================================================
    // Analyst acknowledgment audit
    // =========================================================================

    public function recordAcknowledgment(
        string $analystId,
        string $tenantId,
        string $alertId,
        string $ruleId,
        string $action,
        float  $latencySeconds,
        array  $params = []
    ): AnalystAcknowledgmentAudit {
        if (!in_array($action, AnalystAcknowledgmentAudit::ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid acknowledgment action: {$action}");
        }

        $dismissalCount = $params['dismissal_count'] ?? 1;
        $repeatedDismissal = $action === 'dismissed' && $dismissalCount > 3;

        return AnalystAcknowledgmentAudit::create([
            'audit_id'             => 'aaa-' . Str::uuid(),
            'analyst_id'           => $analystId,
            'tenant_id'            => $tenantId,
            'alert_id'             => $alertId,
            'rule_id'              => $ruleId,
            'acknowledgment_action'=> $action,
            'latency_seconds'      => $latencySeconds,
            'repeated_dismissal'   => $repeatedDismissal,
            'dismissal_count'      => $dismissalCount,
            'replay_consistent'    => true,
            'is_advisory'          => true,
            'context'              => $params['context'] ?? null,
        ]);
    }

    // =========================================================================
    // Escalation quality reviews
    // =========================================================================

    public function reviewEscalationQuality(
        string $escalationId,
        string $tenantId,
        string $reviewedBy,
        float  $qualityScore,
        bool   $evidenceSufficient = true,
        bool   $severityAppropriate = true,
        array  $params = []
    ): EscalationQualityReview {
        $qualityTier = match(true) {
            $qualityScore >= self::MIN_QUALITY_SCORE_HIGH   => 'high',
            $qualityScore >= self::MIN_QUALITY_SCORE_MEDIUM => 'medium',
            $qualityScore >= 0.25                           => 'low',
            default                                         => 'noise',
        };

        $verdict = match(true) {
            $qualityScore >= self::MIN_QUALITY_SCORE_HIGH && $evidenceSufficient && $severityAppropriate => 'valid',
            $qualityScore >= self::MIN_QUALITY_SCORE_MEDIUM && $severityAppropriate => 'over_escalated',
            $qualityScore < self::MIN_QUALITY_SCORE_MEDIUM && $evidenceSufficient   => 'under_escalated',
            default                                                                  => 'noise',
        };

        return EscalationQualityReview::create([
            'review_id'           => 'eqr-' . Str::uuid(),
            'escalation_id'       => $escalationId,
            'tenant_id'           => $tenantId,
            'reviewed_by'         => $reviewedBy,
            'quality_score'       => $qualityScore,
            'quality_tier'        => $qualityTier,
            'evidence_sufficient' => $evidenceSufficient,
            'severity_appropriate'=> $severityAppropriate,
            'replay_validated'    => $params['replay_validated'] ?? false,
            'verdict'             => $verdict,
            'is_advisory'         => true,
            'review_notes'        => $params['review_notes'] ?? null,
        ]);
    }

    // =========================================================================
    // Investigation ergonomic views (mutable)
    // =========================================================================

    public function createErgonomicView(
        string $investigationId,
        string $analystId,
        string $tenantId,
        array  $params = []
    ): InvestigationErgonomicView {
        return InvestigationErgonomicView::create([
            'view_id'            => 'iev-' . Str::uuid(),
            'investigation_id'   => $investigationId,
            'analyst_id'         => $analystId,
            'tenant_id'          => $tenantId,
            'status'             => 'active',
            'evidence_count'     => $params['evidence_count']  ?? 0,
            'bookmark_count'     => 0,
            'timeline_compressed'=> false,
            'chain_summarized'   => false,
            'bounded_traversal'  => true,
            'is_advisory'        => true,
            'view_state'         => $params['view_state'] ?? null,
        ]);
    }

    public function bookmarkView(InvestigationErgonomicView $view): InvestigationErgonomicView
    {
        $view->update([
            'status'         => 'bookmarked',
            'bookmark_count' => $view->bookmark_count + 1,
        ]);
        return $view->fresh();
    }

    // =========================================================================
    // Alert recurrence reports
    // =========================================================================

    public function recordAlertRecurrence(
        string $ruleId,
        string $tenantId,
        int    $recurrenceCount,
        int    $windowHours = 24,
        array  $params = []
    ): AlertRecurrenceReport {
        $recurrenceRate      = $recurrenceCount / max(1, $windowHours);
        $suppressionCandidate= $recurrenceRate >= 1.0 && $recurrenceCount >= 5;

        return AlertRecurrenceReport::create([
            'report_id'           => 'arr-' . Str::uuid(),
            'rule_id'             => $ruleId,
            'tenant_id'           => $tenantId,
            'recurrence_count'    => $recurrenceCount,
            'window_hours'        => $windowHours,
            'recurrence_rate'     => $recurrenceRate,
            'suppression_candidate'=> $suppressionCandidate,
            'replay_consistent'   => true,
            'is_advisory'         => true,
            'recurrence_evidence' => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Operational fatigue indicators
    // =========================================================================

    public function recordFatigueIndicator(
        string $analystId,
        string $tenantId,
        array  $metrics = [],
        string $shiftId = null
    ): OperationalFatigueIndicator {
        $dismissalAccel      = $metrics['dismissal_acceleration_rate']  ?? 0.0;
        $avgReviewTime       = $metrics['avg_review_time_seconds']       ?? 0.0;
        $baselineReviewTime  = $metrics['baseline_review_time_seconds']  ?? $avgReviewTime;
        $consecutiveDismissals = $metrics['consecutive_dismissals']      ?? 0;

        $fatigueDetected = $consecutiveDismissals >= self::FATIGUE_CONSECUTIVE_DISMISSALS
                        || $dismissalAccel >= self::FATIGUE_ACCELERATION_THRESHOLD;

        $fatigueSeverity = match(true) {
            !$fatigueDetected                                  => 'none',
            $dismissalAccel >= 3.0 || $consecutiveDismissals >= 20 => 'high',
            $dismissalAccel >= 2.0 || $consecutiveDismissals >= 15 => 'medium',
            default                                            => 'low',
        };

        return OperationalFatigueIndicator::create([
            'indicator_id'                => 'ofi-' . Str::uuid(),
            'analyst_id'                  => $analystId,
            'tenant_id'                   => $tenantId,
            'shift_id'                    => $shiftId,
            'dismissal_acceleration_rate' => $dismissalAccel,
            'avg_review_time_seconds'     => $avgReviewTime,
            'baseline_review_time_seconds'=> $baselineReviewTime,
            'consecutive_dismissals'      => $consecutiveDismissals,
            'fatigue_detected'            => $fatigueDetected,
            'fatigue_severity'            => $fatigueSeverity,
            'is_advisory'                 => true,
            'evidence'                    => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Shift handoff validations
    // =========================================================================

    public function recordShiftHandoff(
        string $outgoingAnalystId,
        string $incomingAnalystId,
        string $tenantId,
        string $shiftId,
        array  $params = []
    ): ShiftHandoffValidation {
        return ShiftHandoffValidation::create([
            'handoff_id'                      => 'shv-' . Str::uuid(),
            'outgoing_analyst_id'             => $outgoingAnalystId,
            'incoming_analyst_id'             => $incomingAnalystId,
            'tenant_id'                       => $tenantId,
            'shift_id'                        => $shiftId,
            'open_investigations_handed_off'  => $params['open_investigations']   ?? 0,
            'pending_escalations_handed_off'  => $params['pending_escalations']   ?? 0,
            'context_documented'              => $params['context_documented']    ?? false,
            'replay_validated'                => false,
            'continuity_preserved'            => true,
            'is_advisory'                     => true,
            'handoff_summary'                 => $params['summary']               ?? null,
        ]);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'overloaded_analysts'    => AnalystWorkloadSnapshot::where('overload_indicator', true)->count(),
            'critical_alerts'        => AlertPrioritizationScore::where('priority_tier', 'critical')->count(),
            'fp_tuning_reports'      => FalsePositiveTuningReport::count(),
            'repeated_dismissals'    => AnalystAcknowledgmentAudit::where('repeated_dismissal', true)->count(),
            'escalation_noise'       => EscalationQualityReview::where('verdict', 'noise')->count(),
            'recurrence_candidates'  => AlertRecurrenceReport::where('suppression_candidate', true)->count(),
            'fatigue_detected'       => OperationalFatigueIndicator::where('fatigue_detected', true)->count(),
            'handoffs_validated'     => ShiftHandoffValidation::where('continuity_preserved', true)->count(),
            'active_views'           => InvestigationErgonomicView::where('status', 'active')->count(),
        ];
    }
}
