<?php

namespace App\Services;

use App\Models\LivePilotRun;
use App\Models\PilotEndpointEnrollment;
use App\Models\PilotHealthCheckpoint;
use App\Models\PilotOperationalReview;
use App\Models\PilotDriftReview;
use App\Models\PilotRollbackAudit;
use App\Models\LiveTelemetryValidation;
use App\Models\ProductionObservationCheckpoint;
use App\Models\PilotExecutionAudit;
use Illuminate\Support\Str;

class PilotExecutionService
{
    public const ADVISORY_ONLY = true;

    // Bounded pilot size
    public const MIN_ENDPOINTS = 5;
    public const MAX_ENDPOINTS = 20;

    // Success thresholds
    public const MIN_TELEMETRY_CONTINUITY_PCT    = 0.95;
    public const MIN_REPLAY_RECOVERY_SUCCESS_PCT = 0.95;
    public const MIN_ENDPOINT_STABILITY_PCT      = 0.90;
    public const MIN_TENANT_ISOLATION_PASS_RATE  = 1.0;
    public const MAX_FALSE_POSITIVE_RATIO        = 0.05;
    public const MIN_DRIFT_STABILITY_PCT         = 0.95;
    public const MIN_ROLLBACK_READINESS_SCORE    = 0.80;

    // Telemetry thresholds
    public const MAX_QUEUE_LAG              = 50_000;
    public const MAX_DUPLICATE_RATE         = 0.01;
    public const MAX_TELEMETRY_GAP_RATE     = 0.05;
    public const MAX_STORAGE_PRESSURE_PCT   = 0.85;
    public const MAX_RECONNECT_COUNT        = 20;

    // =========================================================================
    // Pilot activation
    // =========================================================================

    public function activatePilot(
        string $tenantId,
        string $pilotName,
        int    $targetEndpointCount,
        string $approvedBy,
        int    $observationWindowHours = 24,
        array  $summary = []
    ): LivePilotRun {
        if ($targetEndpointCount < self::MIN_ENDPOINTS) {
            throw new \InvalidArgumentException(
                "Target endpoint count {$targetEndpointCount} is below minimum " . self::MIN_ENDPOINTS . '.'
            );
        }

        if ($targetEndpointCount > self::MAX_ENDPOINTS) {
            throw new \OverflowException(
                "Target endpoint count {$targetEndpointCount} exceeds maximum " . self::MAX_ENDPOINTS . '.'
            );
        }

        if (!in_array($observationWindowHours, LivePilotRun::OBSERVATION_WINDOWS, true)) {
            throw new \InvalidArgumentException(
                "Invalid observation window hours: {$observationWindowHours}. Allowed: " .
                implode(', ', LivePilotRun::OBSERVATION_WINDOWS)
            );
        }

        $run = LivePilotRun::create([
            'run_id'                   => 'lpr-' . Str::uuid(),
            'tenant_id'                => $tenantId,
            'pilot_name'               => $pilotName,
            'target_endpoint_count'    => $targetEndpointCount,
            'enrolled_endpoint_count'  => 0,
            'status'                   => 'active',
            'activation_approved'      => true,
            'approved_by'              => $approvedBy,
            'activated_at'             => now(),
            'observation_window_hours' => $observationWindowHours,
            'rollback_ready'           => false,
            'is_advisory'              => true,
            'summary'                  => $summary ?: null,
        ]);

        $this->writeAudit($run->run_id, $tenantId, 'activation', $approvedBy, 'success', 'Pilot activated');
        return $run;
    }

    // =========================================================================
    // Endpoint enrollment
    // =========================================================================

    public function enrollEndpoint(
        string $runId,
        string $tenantId,
        string $endpointId,
        string $hostname,
        array  $metadata = []
    ): PilotEndpointEnrollment {
        $enrolledCount = PilotEndpointEnrollment::where('run_id', $runId)
            ->where('status', 'enrolled')
            ->count();

        $run = LivePilotRun::where('run_id', $runId)->first();
        $maxAllowed = $run ? $run->target_endpoint_count : self::MAX_ENDPOINTS;

        if ($enrolledCount >= $maxAllowed) {
            throw new \OverflowException(
                "Enrollment limit reached for pilot {$runId}: {$enrolledCount}/{$maxAllowed}."
            );
        }

        $enrollment = PilotEndpointEnrollment::create([
            'enrollment_id'            => 'pee-' . Str::uuid(),
            'run_id'                   => $runId,
            'tenant_id'                => $tenantId,
            'endpoint_id'              => $endpointId,
            'hostname'                 => $hostname,
            'status'                   => 'enrolled',
            'onboarding_verified'      => true,
            'telemetry_flowing'        => true,
            'telemetry_continuity_pct' => 1.0,
            'is_advisory'              => true,
            'metadata'                 => $metadata ?: null,
        ]);

        $this->writeAudit($runId, $tenantId, 'enrollment', 'system', 'success', "Enrolled endpoint {$hostname}");
        return $enrollment;
    }

    // =========================================================================
    // Health checkpoint
    // =========================================================================

    public function recordHealthCheckpoint(
        string $runId,
        string $tenantId,
        string $checkpointType,
        array  $metrics = []
    ): PilotHealthCheckpoint {
        if (!in_array($checkpointType, PilotHealthCheckpoint::CHECKPOINT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid checkpoint type: {$checkpointType}");
        }

        $telCont   = $metrics['telemetry_continuity_pct']    ?? 1.0;
        $replaySuc = $metrics['replay_recovery_success_pct'] ?? 1.0;
        $queueLat  = $metrics['queue_recovery_latency_ms']   ?? 0.0;
        $epStab    = $metrics['endpoint_stability_pct']      ?? 1.0;
        $isoRate   = $metrics['tenant_isolation_pass_rate']  ?? 1.0;
        $fpRatio   = $metrics['false_positive_ratio']        ?? 0.0;
        $driftStab = $metrics['drift_stability_pct']         ?? 1.0;
        $rbScore   = $metrics['rollback_readiness_score']    ?? 0.0;

        $healthOk = $telCont   >= self::MIN_TELEMETRY_CONTINUITY_PCT
                 && $replaySuc >= self::MIN_REPLAY_RECOVERY_SUCCESS_PCT
                 && $epStab    >= self::MIN_ENDPOINT_STABILITY_PCT
                 && $isoRate   >= self::MIN_TENANT_ISOLATION_PASS_RATE
                 && $fpRatio   <= self::MAX_FALSE_POSITIVE_RATIO
                 && $driftStab >= self::MIN_DRIFT_STABILITY_PCT;

        $checkpoint = PilotHealthCheckpoint::create([
            'checkpoint_id'               => 'phc-' . Str::uuid(),
            'run_id'                      => $runId,
            'tenant_id'                   => $tenantId,
            'checkpoint_type'             => $checkpointType,
            'telemetry_continuity_pct'    => $telCont,
            'replay_recovery_success_pct' => $replaySuc,
            'queue_recovery_latency_ms'   => $queueLat,
            'endpoint_stability_pct'      => $epStab,
            'tenant_isolation_pass_rate'  => $isoRate,
            'false_positive_ratio'        => $fpRatio,
            'drift_stability_pct'         => $driftStab,
            'rollback_readiness_score'    => $rbScore,
            'health_ok'                   => $healthOk,
            'is_advisory'                 => true,
            'metrics'                     => $metrics ?: null,
        ]);

        $this->writeAudit($runId, $tenantId, 'checkpoint', 'system', $healthOk ? 'success' : 'failure', "Health checkpoint: {$checkpointType}");
        return $checkpoint;
    }

    // =========================================================================
    // Telemetry validation
    // =========================================================================

    public function recordTelemetryValidation(
        string $runId,
        string $tenantId,
        array  $metrics = []
    ): LiveTelemetryValidation {
        $eps          = $metrics['events_per_second']        ?? 0.0;
        $continuity   = $metrics['telemetry_continuity_pct'] ?? 1.0;
        $queueLag     = $metrics['queue_lag']                ?? 0;
        $replayCont   = $metrics['replay_continuity_pct']    ?? 1.0;
        $dupRate      = $metrics['duplicate_event_rate']     ?? 0.0;
        $gapRate      = $metrics['telemetry_gap_rate']       ?? 0.0;
        $reconnects   = $metrics['collector_reconnect_count'] ?? 0;
        $storagePct   = $metrics['storage_pressure_pct']     ?? 0.0;
        $workerHealthy= $metrics['worker_healthy']            ?? true;

        $passed = $continuity >= self::MIN_TELEMETRY_CONTINUITY_PCT
               && $replayCont >= self::MIN_TELEMETRY_CONTINUITY_PCT
               && $queueLag   <= self::MAX_QUEUE_LAG
               && $dupRate    <= self::MAX_DUPLICATE_RATE
               && $gapRate    <= self::MAX_TELEMETRY_GAP_RATE
               && $storagePct <= self::MAX_STORAGE_PRESSURE_PCT
               && $workerHealthy;

        return LiveTelemetryValidation::create([
            'validation_id'            => 'ltv-' . Str::uuid(),
            'run_id'                   => $runId,
            'tenant_id'                => $tenantId,
            'events_per_second'        => $eps,
            'telemetry_continuity_pct' => $continuity,
            'queue_lag'                => $queueLag,
            'replay_continuity_pct'    => $replayCont,
            'duplicate_event_rate'     => $dupRate,
            'telemetry_gap_rate'       => $gapRate,
            'collector_reconnect_count'=> $reconnects,
            'storage_pressure_pct'     => $storagePct,
            'worker_healthy'           => $workerHealthy,
            'validation_passed'        => $passed,
            'is_advisory'              => true,
            'raw_metrics'              => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Observation checkpoint
    // =========================================================================

    public function recordObservationCheckpoint(
        string $runId,
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): ProductionObservationCheckpoint {
        if (!in_array($windowType, ProductionObservationCheckpoint::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $telCont   = $metrics['telemetry_continuity_pct']    ?? 1.0;
        $replaySuc = $metrics['replay_recovery_success_pct'] ?? 1.0;
        $driftStab = $metrics['drift_stability_pct']         ?? 1.0;
        $rbScore   = $metrics['rollback_readiness_score']    ?? 0.0;

        $criteriaMet = $telCont   >= self::MIN_TELEMETRY_CONTINUITY_PCT
                    && $replaySuc >= self::MIN_REPLAY_RECOVERY_SUCCESS_PCT
                    && $driftStab >= self::MIN_DRIFT_STABILITY_PCT
                    && $rbScore   >= self::MIN_ROLLBACK_READINESS_SCORE;

        return ProductionObservationCheckpoint::create([
            'checkpoint_id'               => 'poc-' . Str::uuid(),
            'run_id'                      => $runId,
            'tenant_id'                   => $tenantId,
            'window_type'                 => $windowType,
            'telemetry_continuity_pct'    => $telCont,
            'replay_recovery_success_pct' => $replaySuc,
            'drift_stability_pct'         => $driftStab,
            'rollback_readiness_score'    => $rbScore,
            'criteria_met'                => $criteriaMet,
            'is_advisory'                 => true,
            'summary'                     => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Operational review
    // =========================================================================

    public function recordOperationalReview(
        string $runId,
        string $tenantId,
        string $reviewType,
        string $reviewedBy,
        string $verdict,
        string $notes = '',
        bool   $requiresFollowup = false,
        array  $evidence = []
    ): PilotOperationalReview {
        if (!in_array($reviewType, PilotOperationalReview::REVIEW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid review type: {$reviewType}");
        }

        if (!in_array($verdict, PilotOperationalReview::VERDICTS, true)) {
            throw new \InvalidArgumentException("Invalid verdict: {$verdict}");
        }

        $review = PilotOperationalReview::create([
            'review_id'        => 'por-' . Str::uuid(),
            'run_id'           => $runId,
            'tenant_id'        => $tenantId,
            'review_type'      => $reviewType,
            'reviewed_by'      => $reviewedBy,
            'verdict'          => $verdict,
            'notes'            => $notes ?: null,
            'requires_followup'=> $requiresFollowup,
            'is_advisory'      => true,
            'evidence'         => $evidence ?: null,
        ]);

        $this->writeAudit($runId, $tenantId, 'review', $reviewedBy, 'success', "Review: {$reviewType} → {$verdict}");
        return $review;
    }

    // =========================================================================
    // Drift review
    // =========================================================================

    public function recordDriftReview(
        string $runId,
        string $tenantId,
        string $driftType,
        float  $driftMagnitude,
        string $driftSeverity,
        string $verdict,
        string $reviewedBy,
        bool   $rollbackTriggered = false,
        array  $snapshot = []
    ): PilotDriftReview {
        if (!in_array($driftType, PilotDriftReview::DRIFT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid drift type: {$driftType}");
        }

        if (!in_array($driftSeverity, PilotDriftReview::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid drift severity: {$driftSeverity}");
        }

        if (!in_array($verdict, PilotDriftReview::VERDICTS, true)) {
            throw new \InvalidArgumentException("Invalid drift verdict: {$verdict}");
        }

        return PilotDriftReview::create([
            'drift_review_id'  => 'pdr-' . Str::uuid(),
            'run_id'           => $runId,
            'tenant_id'        => $tenantId,
            'drift_type'       => $driftType,
            'drift_magnitude'  => $driftMagnitude,
            'drift_severity'   => $driftSeverity,
            'verdict'          => $verdict,
            'reviewed_by'      => $reviewedBy,
            'rollback_triggered'=> $rollbackTriggered,
            'is_advisory'      => true,
            'snapshot'         => $snapshot ?: null,
        ]);
    }

    // =========================================================================
    // Rollback audit
    // =========================================================================

    public function recordRollbackAudit(
        string $runId,
        string $tenantId,
        string $triggerReason,
        string $triggeredBy,
        string $status = 'pending_approval',
        array  $auditTrail = []
    ): PilotRollbackAudit {
        if (!in_array($triggerReason, PilotRollbackAudit::TRIGGER_REASONS, true)) {
            throw new \InvalidArgumentException("Invalid trigger reason: {$triggerReason}");
        }

        if (!in_array($status, PilotRollbackAudit::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid rollback status: {$status}");
        }

        $audit = PilotRollbackAudit::create([
            'rollback_id'          => 'prb-' . Str::uuid(),
            'run_id'               => $runId,
            'tenant_id'            => $tenantId,
            'trigger_reason'       => $triggerReason,
            'triggered_by'         => $triggeredBy,
            'rollback_approved'    => false,
            'approved_by'          => null,
            'status'               => $status,
            'destructive_action'   => false, // always false
            'replay_reconstructed' => false,
            'isolation_preserved'  => true,
            'is_advisory'          => true,
            'audit_trail'          => $auditTrail ?: null,
        ]);

        $this->writeAudit($runId, $tenantId, 'rollback', $triggeredBy, 'pending', "Rollback triggered: {$triggerReason}");
        return $audit;
    }

    // =========================================================================
    // Scoring
    // =========================================================================

    public function scorePilotHealth(LivePilotRun $pilot): array
    {
        $runId = $pilot->run_id;

        $latestCheckpoint = PilotHealthCheckpoint::where('run_id', $runId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$latestCheckpoint) {
            return [
                'run_id'                       => $runId,
                'health_ok'                    => false,
                'telemetry_continuity_pct'     => 0.0,
                'replay_recovery_success_pct'  => 0.0,
                'endpoint_stability_pct'       => 0.0,
                'rollback_readiness_score'     => 0.0,
                'enrolled_endpoints'           => 0,
                'is_advisory'                  => true,
            ];
        }

        $enrolledCount = PilotEndpointEnrollment::where('run_id', $runId)
            ->where('status', 'enrolled')
            ->count();

        return [
            'run_id'                       => $runId,
            'health_ok'                    => $latestCheckpoint->health_ok,
            'telemetry_continuity_pct'     => $latestCheckpoint->telemetry_continuity_pct,
            'replay_recovery_success_pct'  => $latestCheckpoint->replay_recovery_success_pct,
            'endpoint_stability_pct'       => $latestCheckpoint->endpoint_stability_pct,
            'rollback_readiness_score'     => $latestCheckpoint->rollback_readiness_score,
            'enrolled_endpoints'           => $enrolledCount,
            'is_advisory'                  => true,
        ];
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'active_pilots'           => LivePilotRun::where('status', 'active')->count(),
            'total_enrollments'       => PilotEndpointEnrollment::where('status', 'enrolled')->count(),
            'health_ok'               => PilotHealthCheckpoint::where('health_ok', true)->count(),
            'health_fail'             => PilotHealthCheckpoint::where('health_ok', false)->count(),
            'pending_reviews'         => PilotOperationalReview::where('verdict', 'deferred')->count(),
            'drift_escalated'         => PilotDriftReview::where('verdict', 'escalated')->count(),
            'rollback_pending'        => PilotRollbackAudit::where('status', 'pending_approval')->count(),
            'telemetry_validations'   => LiveTelemetryValidation::where('validation_passed', true)->count(),
            'checkpoints_criteria_met'=> ProductionObservationCheckpoint::where('criteria_met', true)->count(),
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function writeAudit(
        string $runId,
        string $tenantId,
        string $eventType,
        string $actor,
        string $outcome,
        string $description
    ): void {
        PilotExecutionAudit::create([
            'audit_id'    => 'pea-' . Str::uuid(),
            'run_id'      => $runId,
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'actor'       => $actor,
            'outcome'     => $outcome,
            'description' => $description,
            'is_advisory' => true,
        ]);
    }
}
