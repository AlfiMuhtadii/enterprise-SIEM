<?php

namespace App\Services;

use App\Models\PilotOnboardingRun;
use App\Models\PilotHealthValidation;
use App\Models\PilotSuccessMetric;
use App\Models\PilotRollbackValidation;
use App\Models\TelemetryOnboardingPressure;
use App\Models\OperatorReadinessReview;
use App\Models\PilotAuditEvent;
use App\Models\OnboardingApprovalRequest;
use App\Models\PilotObservationWindow;
use Illuminate\Support\Str;

class PilotReadinessService
{
    public const ADVISORY_ONLY = true;

    // Pilot limits
    public const MAX_EVENTS_PER_SECOND   = 10_000;
    public const MAX_ENDPOINTS_PER_PILOT = 1_000;
    public const MAX_PILOT_DURATION_HOURS = 168; // 7 days
    public const CRITICAL_PRESSURE_EPS   = 8_000;
    public const HIGH_PRESSURE_EPS       = 5_000;
    public const ELEVATED_PRESSURE_EPS   = 2_000;

    // Success targets
    public const TARGET_TELEMETRY_CONTINUITY_PCT = 0.95;
    public const TARGET_REPLAY_SUCCESS_PCT        = 0.98;
    public const TARGET_ISOLATION_PASS_RATE       = 1.00;
    public const TARGET_ENDPOINT_STABILITY_PCT    = 0.95;
    public const TARGET_DRIFT_STABILITY_PCT       = 0.80;
    public const MAX_FP_RATIO                     = 0.05;

    // =========================================================================
    // Onboarding run
    // =========================================================================

    public function registerPilotOnboarding(
        string  $tenantId,
        string  $operatorId,
        int     $maxEventsPerSecond,
        int     $maxEndpoints,
        int     $pilotDurationHours,
        array   $evidenceRefs = []
    ): PilotOnboardingRun {
        if ($maxEventsPerSecond > self::MAX_EVENTS_PER_SECOND) {
            throw new \OverflowException(
                "max_events_per_second {$maxEventsPerSecond} exceeds MAX=" . self::MAX_EVENTS_PER_SECOND
            );
        }

        if ($maxEndpoints > self::MAX_ENDPOINTS_PER_PILOT) {
            throw new \OverflowException(
                "max_endpoints {$maxEndpoints} exceeds MAX=" . self::MAX_ENDPOINTS_PER_PILOT
            );
        }

        if ($pilotDurationHours > self::MAX_PILOT_DURATION_HOURS) {
            throw new \OverflowException(
                "pilot_duration_hours {$pilotDurationHours} exceeds MAX=" . self::MAX_PILOT_DURATION_HOURS
            );
        }

        return PilotOnboardingRun::create([
            'run_id'                       => 'por-' . Str::uuid(),
            'tenant_id'                    => $tenantId,
            'status'                       => 'pending',
            'max_events_per_second'        => $maxEventsPerSecond,
            'max_endpoints'                => $maxEndpoints,
            'pilot_duration_hours'         => $pilotDurationHours,
            'readiness_checklist_complete' => false,
            'rollback_drill_complete'      => false,
            'operator_acknowledged'        => false,
            'operator_id'                  => $operatorId,
            'is_advisory'                  => true,
            'evidence_refs'                => $evidenceRefs ?: null,
        ]);
    }

    // =========================================================================
    // Health validation
    // =========================================================================

    public function runHealthCheck(
        string  $runId,
        string  $tenantId,
        string  $checkType,
        float   $metricValue,
        float   $thresholdValue
    ): PilotHealthValidation {
        if (!in_array($checkType, PilotHealthValidation::CHECK_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid check type: {$checkType}");
        }

        $passed = $metricValue >= $thresholdValue;
        $verdict = $passed ? 'pass' : ($metricValue >= $thresholdValue * 0.80 ? 'degraded' : 'fail');

        return PilotHealthValidation::create([
            'validation_id'  => 'phv-' . Str::uuid(),
            'run_id'         => $runId,
            'tenant_id'      => $tenantId,
            'check_type'     => $checkType,
            'check_passed'   => $passed,
            'verdict'        => $verdict,
            'failure_reason' => $passed ? null : "Metric {$metricValue} below threshold {$thresholdValue}",
            'metric_value'   => $metricValue,
            'threshold_value'=> $thresholdValue,
            'is_advisory'    => true,
        ]);
    }

    // =========================================================================
    // Success metrics
    // =========================================================================

    public function recordSuccessMetric(
        string  $runId,
        string  $tenantId,
        string  $metricName,
        float   $metricValue,
        float   $targetValue,
        int     $windowHours
    ): PilotSuccessMetric {
        if (!in_array($metricName, PilotSuccessMetric::METRIC_NAMES, true)) {
            throw new \InvalidArgumentException("Invalid metric name: {$metricName}");
        }

        $targetMet = match ($metricName) {
            'fp_ratio'           => $metricValue <= $targetValue,
            'queue_recovery_latency_ms', 'operator_ack_latency_s' => $metricValue <= $targetValue,
            default              => $metricValue >= $targetValue,
        };

        return PilotSuccessMetric::create([
            'metric_id'    => 'psm-' . Str::uuid(),
            'run_id'       => $runId,
            'tenant_id'    => $tenantId,
            'metric_name'  => $metricName,
            'metric_value' => $metricValue,
            'target_value' => $targetValue,
            'target_met'   => $targetMet,
            'window_hours' => $windowHours,
            'is_advisory'  => true,
        ]);
    }

    // =========================================================================
    // Rollback validation
    // =========================================================================

    public function validateRollback(
        string  $runId,
        string  $tenantId,
        string  $trigger,
        bool    $checkpointValid,
        bool    $approvalObtained,
        bool    $rollbackSafe,
        bool    $auditComplete,
        ?string $approvedBy = null
    ): PilotRollbackValidation {
        if (!in_array($trigger, PilotRollbackValidation::TRIGGERS, true)) {
            throw new \InvalidArgumentException("Invalid rollback trigger: {$trigger}");
        }

        $verdict = match (true) {
            !$checkpointValid  => 'fail',
            !$rollbackSafe     => 'fail',
            !$approvalObtained => 'pending_approval',
            !$auditComplete    => 'fail',
            default            => 'pass',
        };

        return PilotRollbackValidation::create([
            'validation_id'    => 'prv-' . Str::uuid(),
            'run_id'           => $runId,
            'tenant_id'        => $tenantId,
            'trigger'          => $trigger,
            'checkpoint_valid' => $checkpointValid,
            'approval_obtained'=> $approvalObtained,
            'rollback_safe'    => $rollbackSafe,
            'audit_complete'   => $auditComplete,
            'approved_by'      => $approvedBy,
            'verdict'          => $verdict,
            'is_advisory'      => true,
        ]);
    }

    // =========================================================================
    // Telemetry pressure
    // =========================================================================

    public function snapshotTelemetryPressure(
        string  $runId,
        string  $tenantId,
        float   $eventsPerSecond,
        float   $queueGrowthRate,
        float   $storageGrowthMbPerHour,
        int     $endpointCount,
        float   $replayAmplification
    ): TelemetryOnboardingPressure {
        $pressureLevel = match (true) {
            $eventsPerSecond >= self::CRITICAL_PRESSURE_EPS => 'critical',
            $eventsPerSecond >= self::HIGH_PRESSURE_EPS     => 'high',
            $eventsPerSecond >= self::ELEVATED_PRESSURE_EPS => 'elevated',
            default                                          => 'normal',
        };

        $pressureOk = $pressureLevel !== 'critical'
            && $replayAmplification <= 3.0
            && $queueGrowthRate < 50_000;

        return TelemetryOnboardingPressure::create([
            'snapshot_id'                => 'top-' . Str::uuid(),
            'run_id'                     => $runId,
            'tenant_id'                  => $tenantId,
            'events_per_second'          => $eventsPerSecond,
            'queue_growth_rate'          => $queueGrowthRate,
            'storage_growth_mb_per_hour' => $storageGrowthMbPerHour,
            'endpoint_count'             => $endpointCount,
            'replay_amplification_factor'=> $replayAmplification,
            'pressure_ok'                => $pressureOk,
            'pressure_level'             => $pressureLevel,
            'is_advisory'                => true,
        ]);
    }

    // =========================================================================
    // Operator readiness
    // =========================================================================

    public function recordOperatorReadiness(
        string  $runId,
        string  $operatorId,
        string  $reviewType,
        bool    $runbookReviewed,
        bool    $escalationValidated,
        bool    $shiftHandoffReady,
        bool    $incidentWorkflowTested,
        ?int    $ackLatencySeconds = null
    ): OperatorReadinessReview {
        if (!in_array($reviewType, OperatorReadinessReview::REVIEW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid review type: {$reviewType}");
        }

        $operatorReady = $runbookReviewed && $escalationValidated
            && $shiftHandoffReady && $incidentWorkflowTested;

        $verdict = $operatorReady ? 'pass'
            : (($runbookReviewed || $escalationValidated) ? 'incomplete' : 'fail');

        return OperatorReadinessReview::create([
            'review_id'                   => 'orr-' . Str::uuid(),
            'run_id'                      => $runId,
            'operator_id'                 => $operatorId,
            'review_type'                 => $reviewType,
            'runbook_reviewed'            => $runbookReviewed,
            'escalation_validated'        => $escalationValidated,
            'shift_handoff_ready'         => $shiftHandoffReady,
            'incident_workflow_tested'    => $incidentWorkflowTested,
            'operator_ready'              => $operatorReady,
            'acknowledgment_latency_seconds' => $ackLatencySeconds,
            'verdict'                     => $verdict,
            'is_advisory'                 => true,
        ]);
    }

    // =========================================================================
    // Audit trail
    // =========================================================================

    public function recordAuditEvent(
        string  $runId,
        string  $tenantId,
        string  $eventType,
        string  $description,
        ?string $actorId  = null,
        array   $payload  = []
    ): PilotAuditEvent {
        if (!in_array($eventType, PilotAuditEvent::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid audit event type: {$eventType}");
        }

        return PilotAuditEvent::create([
            'event_id'    => 'pae-' . Str::uuid(),
            'run_id'      => $runId,
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'actor_id'    => $actorId,
            'description' => $description,
            'payload'     => $payload ?: null,
            'is_advisory' => true,
        ]);
    }

    // =========================================================================
    // Approval request
    // =========================================================================

    public function requestOnboardingApproval(
        string  $runId,
        string  $tenantId,
        string  $requestedBy
    ): OnboardingApprovalRequest {
        return OnboardingApprovalRequest::create([
            'request_id'          => 'oar-' . Str::uuid(),
            'run_id'              => $runId,
            'tenant_id'           => $tenantId,
            'requested_by'        => $requestedBy,
            'status'              => 'pending',
            'self_approve_blocked'=> true,
            'is_advisory'         => true,
        ]);
    }

    public function canSelfApprove(OnboardingApprovalRequest $request, string $reviewerId): bool
    {
        return !($request->self_approve_blocked && $request->requested_by === $reviewerId);
    }

    // =========================================================================
    // Observation window (mutable)
    // =========================================================================

    public function createObservationWindow(
        string  $runId,
        string  $tenantId,
        int     $durationHours,
        string  $phase
    ): PilotObservationWindow {
        if (!in_array($phase, PilotObservationWindow::PHASES, true)) {
            throw new \InvalidArgumentException("Invalid phase: {$phase}");
        }

        return PilotObservationWindow::create([
            'window_id'                => 'pow-' . Str::uuid(),
            'run_id'                   => $runId,
            'tenant_id'                => $tenantId,
            'duration_hours'           => $durationHours,
            'status'                   => 'pending',
            'health_ok'                => true,
            'metrics_meeting_targets'  => false,
            'phase'                    => $phase,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_pilots'          => PilotOnboardingRun::count(),
            'active_pilots'         => PilotOnboardingRun::where('status', 'active')->count(),
            'pending_approvals'     => OnboardingApprovalRequest::where('status', 'pending')->count(),
            'health_failures'       => PilotHealthValidation::where('check_passed', false)->count(),
            'rollback_validations'  => PilotRollbackValidation::count(),
            'rollback_fail'         => PilotRollbackValidation::where('verdict', 'fail')->count(),
            'critical_pressure'     => TelemetryOnboardingPressure::where('pressure_level', 'critical')->count(),
            'metrics_targets_met'   => PilotSuccessMetric::where('target_met', true)->count(),
            'metrics_targets_missed'=> PilotSuccessMetric::where('target_met', false)->count(),
            'operator_ready'        => OperatorReadinessReview::where('operator_ready', true)->count(),
            'operator_not_ready'    => OperatorReadinessReview::where('operator_ready', false)->count(),
            'active_windows'        => PilotObservationWindow::where('status', 'active')->count(),
        ];
    }
}
