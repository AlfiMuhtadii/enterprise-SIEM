<?php

namespace App\Services;

use App\Models\TelemetryScaleValidationRun;
use App\Models\TelemetryScaleMetric;
use App\Models\ReplayScaleRecoveryRun;
use App\Models\AnalystLoadStabilityReport;
use App\Models\InfrastructurePressureRun;
use App\Models\TelemetryGrowthDriftReport;
use App\Models\ScaleObservationWindow;
use App\Models\QueueRecoveryValidationReport;
use App\Models\ScalePilotAudit;
use Illuminate\Support\Str;

class TelemetryScalePilotService
{
    public const ADVISORY_ONLY = true;

    // Bounded pilot scale
    public const MIN_ENDPOINTS = 50;
    public const MAX_ENDPOINTS = 100;
    public const ALLOWED_OBSERVATION_WINDOWS = [24, 48, 72];

    // Telemetry thresholds
    public const MIN_TELEMETRY_CONTINUITY_PCT    = 0.95;
    public const MAX_DUPLICATE_RATE              = 0.01;
    public const MAX_QUEUE_LAG                   = 100_000;
    public const MAX_REPLAY_AMPLIFICATION        = 3.0;
    public const MAX_STORAGE_PRESSURE_PCT        = 0.85;

    // Infrastructure thresholds
    public const MAX_MEMORY_GROWTH_MB            = 512.0;
    public const MAX_CPU_PCT                     = 0.90;
    public const MAX_QUERY_LATENCY_MS            = 500.0;

    // Drift thresholds
    public const MAX_DRIFT_MAGNITUDE_HIGH        = 0.25;
    public const MAX_DRIFT_MAGNITUDE_CRITICAL    = 0.50;

    // =========================================================================
    // Telemetry scale validation runs
    // =========================================================================

    public function startScaleValidation(
        string $tenantId,
        int    $endpointCount,
        array  $params = []
    ): TelemetryScaleValidationRun {
        if ($endpointCount < self::MIN_ENDPOINTS) {
            throw new \InvalidArgumentException(
                "Endpoint count {$endpointCount} is below minimum " . self::MIN_ENDPOINTS . '.'
            );
        }

        if ($endpointCount > self::MAX_ENDPOINTS) {
            throw new \OverflowException(
                "Endpoint count {$endpointCount} exceeds maximum " . self::MAX_ENDPOINTS . '.'
            );
        }

        $profile = match(true) {
            $endpointCount <= 60  => 'scale_50',
            $endpointCount <= 85  => 'scale_75',
            default               => 'scale_100',
        };

        $run = TelemetryScaleValidationRun::create([
            'run_id'                   => 'tsv-' . Str::uuid(),
            'tenant_id'                => $tenantId,
            'endpoint_count'           => $endpointCount,
            'scale_profile'            => $profile,
            'status'                   => 'running',
            'avg_events_per_second'    => $params['avg_events_per_second']    ?? 0.0,
            'telemetry_continuity_pct' => $params['telemetry_continuity_pct'] ?? 1.0,
            'duplicate_rate'           => $params['duplicate_rate']           ?? 0.0,
            'replay_backlog'           => $params['replay_backlog']           ?? 0,
            'validation_passed'        => false,
            'is_advisory'              => true,
            'summary'                  => $params ?: null,
        ]);

        $this->writeAudit($run->run_id, $tenantId, 'run_started', 'system', 'success', "Scale validation started for {$endpointCount} endpoints");
        return $run;
    }

    public function completeScaleValidation(
        string $runId,
        string $tenantId,
        array  $finalMetrics = []
    ): TelemetryScaleValidationRun {
        $continuity  = $finalMetrics['telemetry_continuity_pct'] ?? 1.0;
        $dupRate     = $finalMetrics['duplicate_rate']            ?? 0.0;
        $queueLag    = $finalMetrics['queue_lag']                 ?? 0;
        $storagePct  = $finalMetrics['storage_pressure_pct']      ?? 0.0;

        $passed = $continuity >= self::MIN_TELEMETRY_CONTINUITY_PCT
               && $dupRate    <= self::MAX_DUPLICATE_RATE
               && $queueLag   <= self::MAX_QUEUE_LAG
               && $storagePct <= self::MAX_STORAGE_PRESSURE_PCT;

        $record = TelemetryScaleValidationRun::create([
            'run_id'                   => 'tsv-' . Str::uuid(),
            'tenant_id'                => $tenantId,
            'endpoint_count'           => $finalMetrics['endpoint_count'] ?? 0,
            'scale_profile'            => $finalMetrics['scale_profile']  ?? 'scale_50',
            'status'                   => 'completed',
            'avg_events_per_second'    => $finalMetrics['avg_events_per_second']    ?? 0.0,
            'telemetry_continuity_pct' => $continuity,
            'duplicate_rate'           => $dupRate,
            'replay_backlog'           => $queueLag,
            'validation_passed'        => $passed,
            'is_advisory'              => true,
            'summary'                  => $finalMetrics ?: null,
        ]);

        $this->writeAudit($record->run_id, $tenantId, 'completion', 'system', $passed ? 'success' : 'failure', "Scale validation completed");
        return $record;
    }

    // =========================================================================
    // Telemetry scale metrics
    // =========================================================================

    public function recordScaleMetric(
        string $runId,
        string $tenantId,
        string $metricType,
        float  $value,
        float  $baselineValue = 0.0,
        array  $metadata = []
    ): TelemetryScaleMetric {
        if (!in_array($metricType, TelemetryScaleMetric::METRIC_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid metric type: {$metricType}");
        }

        $driftPct      = $baselineValue > 0.0 ? abs($value - $baselineValue) / $baselineValue : 0.0;
        $withinBounds  = $this->isMetricWithinBounds($metricType, $value);

        return TelemetryScaleMetric::create([
            'metric_id'      => 'tsm-' . Str::uuid(),
            'run_id'         => $runId,
            'tenant_id'      => $tenantId,
            'metric_type'    => $metricType,
            'value'          => $value,
            'baseline_value' => $baselineValue,
            'drift_pct'      => $driftPct,
            'within_bounds'  => $withinBounds,
            'is_advisory'    => true,
            'metadata'       => $metadata ?: null,
        ]);
    }

    // =========================================================================
    // Replay scale recovery
    // =========================================================================

    public function recordReplayRecovery(
        string $runId,
        string $tenantId,
        int    $backlogAtStart,
        int    $backlogAtEnd,
        float  $recoveryLatencySeconds,
        float  $replayAmplificationFactor,
        array  $params = []
    ): ReplayScaleRecoveryRun {
        $amplificationBounded = $replayAmplificationFactor <= self::MAX_REPLAY_AMPLIFICATION;
        $recoverySuccessful   = $backlogAtEnd < $backlogAtStart && $amplificationBounded;

        $record = ReplayScaleRecoveryRun::create([
            'recovery_id'                => 'rsr-' . Str::uuid(),
            'run_id'                     => $runId,
            'tenant_id'                  => $tenantId,
            'backlog_at_start'           => $backlogAtStart,
            'backlog_at_end'             => $backlogAtEnd,
            'recovery_latency_seconds'   => $recoveryLatencySeconds,
            'replay_amplification_factor'=> $replayAmplificationFactor,
            'amplification_bounded'      => $amplificationBounded,
            'duplicate_protected'        => true,
            'recovery_successful'        => $recoverySuccessful,
            'is_advisory'                => true,
            'recovery_evidence'          => $params['evidence'] ?? null,
        ]);

        if (!$amplificationBounded) {
            $this->writeAudit($runId, $tenantId, 'drift_detected', 'system', 'degraded', "Replay amplification exceeded threshold: {$replayAmplificationFactor}");
        }

        return $record;
    }

    // =========================================================================
    // Analyst load stability
    // =========================================================================

    public function recordAnalystLoadStability(
        string $runId,
        string $tenantId,
        array  $metrics = []
    ): AnalystLoadStabilityReport {
        $throughput   = $metrics['alert_throughput_per_hour']           ?? 0.0;
        $latency      = $metrics['avg_acknowledgment_latency_seconds']  ?? 0.0;
        $backlog      = $metrics['escalation_backlog']                  ?? 0;
        $fatigue      = $metrics['fatigue_detected']                    ?? false;
        $dismissed    = $metrics['repeated_dismissal_count']            ?? 0;
        $invDuration  = $metrics['avg_investigation_duration_minutes']  ?? 0.0;
        $queueGrowth  = $metrics['queue_growth_rate']                   ?? 0.0;

        $workloadStable = !$fatigue && $queueGrowth < 1.0 && $backlog < 50;

        return AnalystLoadStabilityReport::create([
            'report_id'                         => 'als-' . Str::uuid(),
            'run_id'                            => $runId,
            'tenant_id'                         => $tenantId,
            'alert_throughput_per_hour'         => $throughput,
            'avg_acknowledgment_latency_seconds'=> $latency,
            'escalation_backlog'                => $backlog,
            'fatigue_detected'                  => $fatigue,
            'repeated_dismissal_count'          => $dismissed,
            'avg_investigation_duration_minutes'=> $invDuration,
            'queue_growth_rate'                 => $queueGrowth,
            'workload_stable'                   => $workloadStable,
            'is_advisory'                       => true,
            'stability_evidence'                => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Infrastructure pressure
    // =========================================================================

    public function recordInfrastructurePressure(
        string $runId,
        string $tenantId,
        array  $metrics = []
    ): InfrastructurePressureRun {
        $cpu        = $metrics['cpu_usage_pct']              ?? 0.0;
        $memGrowth  = $metrics['memory_growth_mb']           ?? 0.0;
        $storage    = $metrics['storage_pressure_pct']       ?? 0.0;
        $partition  = $metrics['partition_pressure_pct']     ?? 0.0;
        $queryLat   = $metrics['query_latency_ms']           ?? 0.0;
        $graphLat   = $metrics['graph_traversal_latency_ms'] ?? 0.0;
        $replayLat  = $metrics['replay_latency_ms']          ?? 0.0;

        $withinBounds = $cpu       <= self::MAX_CPU_PCT
                     && $memGrowth <= self::MAX_MEMORY_GROWTH_MB
                     && $storage   <= self::MAX_STORAGE_PRESSURE_PCT
                     && $queryLat  <= self::MAX_QUERY_LATENCY_MS;

        return InfrastructurePressureRun::create([
            'pressure_id'                => 'ipr-' . Str::uuid(),
            'run_id'                     => $runId,
            'tenant_id'                  => $tenantId,
            'cpu_usage_pct'              => $cpu,
            'memory_growth_mb'           => $memGrowth,
            'storage_pressure_pct'       => $storage,
            'partition_pressure_pct'     => $partition,
            'query_latency_ms'           => $queryLat,
            'graph_traversal_latency_ms' => $graphLat,
            'replay_latency_ms'          => $replayLat,
            'pressure_within_bounds'     => $withinBounds,
            'is_advisory'                => true,
            'pressure_snapshot'          => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Drift reports
    // =========================================================================

    public function recordDriftReport(
        string $runId,
        string $tenantId,
        string $driftDimension,
        float  $currentValue,
        float  $baselineValue,
        array  $params = []
    ): TelemetryGrowthDriftReport {
        if (!in_array($driftDimension, TelemetryGrowthDriftReport::DRIFT_DIMENSIONS, true)) {
            throw new \InvalidArgumentException("Invalid drift dimension: {$driftDimension}");
        }

        $driftMagnitude = $baselineValue > 0.0
            ? abs($currentValue - $baselineValue) / $baselineValue
            : 0.0;

        $severity = match(true) {
            $driftMagnitude >= self::MAX_DRIFT_MAGNITUDE_CRITICAL => 'critical',
            $driftMagnitude >= self::MAX_DRIFT_MAGNITUDE_HIGH     => 'high',
            $driftMagnitude >= 0.10                               => 'medium',
            default                                               => 'low',
        };

        $driftBounded = $driftMagnitude < self::MAX_DRIFT_MAGNITUDE_CRITICAL;

        if (!$driftBounded) {
            $this->writeAudit($runId, $tenantId, 'drift_detected', 'system', 'degraded', "Critical drift: {$driftDimension}");
        }

        return TelemetryGrowthDriftReport::create([
            'drift_id'        => 'tgd-' . Str::uuid(),
            'run_id'          => $runId,
            'tenant_id'       => $tenantId,
            'drift_dimension' => $driftDimension,
            'current_value'   => $currentValue,
            'baseline_value'  => $baselineValue,
            'drift_magnitude' => $driftMagnitude,
            'drift_severity'  => $severity,
            'drift_bounded'   => $driftBounded,
            'is_advisory'     => true,
            'drift_evidence'  => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Observation windows (mutable)
    // =========================================================================

    public function openObservationWindow(
        string $runId,
        string $tenantId,
        int    $windowHours
    ): ScaleObservationWindow {
        if (!in_array($windowHours, ScaleObservationWindow::ALLOWED_WINDOWS, true)) {
            throw new \InvalidArgumentException("Invalid observation window: {$windowHours}h. Allowed: " . implode(', ', ScaleObservationWindow::ALLOWED_WINDOWS));
        }

        return ScaleObservationWindow::create([
            'window_id'      => 'sow-' . Str::uuid(),
            'run_id'         => $runId,
            'tenant_id'      => $tenantId,
            'window_hours'   => $windowHours,
            'status'         => 'active',
            'bounded_window' => true,
            'is_advisory'    => true,
        ]);
    }

    public function closeObservationWindow(
        ScaleObservationWindow $window,
        array                  $finalMetrics = []
    ): ScaleObservationWindow {
        $continuity  = $finalMetrics['telemetry_continuity_pct']    ?? 1.0;
        $replaySuc   = $finalMetrics['replay_recovery_success_pct'] ?? 1.0;
        $driftStab   = $finalMetrics['drift_stability_pct']         ?? 1.0;

        $criteriaMet = $continuity >= self::MIN_TELEMETRY_CONTINUITY_PCT
                    && $replaySuc  >= self::MIN_TELEMETRY_CONTINUITY_PCT
                    && $driftStab  >= 0.90;

        $window->update([
            'status'                      => 'completed',
            'telemetry_continuity_pct'    => $continuity,
            'replay_recovery_success_pct' => $replaySuc,
            'drift_stability_pct'         => $driftStab,
            'criteria_met'                => $criteriaMet,
            'window_summary'              => $finalMetrics ?: null,
        ]);

        return $window->fresh();
    }

    // =========================================================================
    // Queue recovery validation
    // =========================================================================

    public function recordQueueRecovery(
        string $runId,
        string $tenantId,
        int    $lagAtStart,
        int    $lagAtEnd,
        float  $recoveryLatencySeconds,
        bool   $amplificationSafe = true,
        array  $params = []
    ): QueueRecoveryValidationReport {
        $recoverySuccessful = $lagAtEnd < $lagAtStart && $amplificationSafe;

        return QueueRecoveryValidationReport::create([
            'report_id'                   => 'qrv-' . Str::uuid(),
            'run_id'                      => $runId,
            'tenant_id'                   => $tenantId,
            'queue_lag_at_start'          => $lagAtStart,
            'queue_lag_at_end'            => $lagAtEnd,
            'recovery_latency_seconds'    => $recoveryLatencySeconds,
            'duplicate_protected'         => true,
            'replay_amplification_safe'   => $amplificationSafe,
            'continuity_after_reconnect'  => true,
            'recovery_successful'         => $recoverySuccessful,
            'is_advisory'                 => true,
            'recovery_evidence'           => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'scale_runs'            => TelemetryScaleValidationRun::count(),
            'passed_runs'           => TelemetryScaleValidationRun::where('validation_passed', true)->count(),
            'active_windows'        => ScaleObservationWindow::where('status', 'active')->count(),
            'recovery_successful'   => ReplayScaleRecoveryRun::where('recovery_successful', true)->count(),
            'pressure_bounded'      => InfrastructurePressureRun::where('pressure_within_bounds', true)->count(),
            'drift_critical'        => TelemetryGrowthDriftReport::where('drift_severity', 'critical')->count(),
            'workload_stable'       => AnalystLoadStabilityReport::where('workload_stable', true)->count(),
            'queue_recovered'       => QueueRecoveryValidationReport::where('recovery_successful', true)->count(),
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function isMetricWithinBounds(string $metricType, float $value): bool
    {
        return match($metricType) {
            'throughput'      => true,
            'queue_lag'       => $value <= self::MAX_QUEUE_LAG,
            'replay_backlog'  => $value <= 50_000,
            'storage'         => $value <= self::MAX_STORAGE_PRESSURE_PCT,
            'worker_restarts' => $value <= 10,
            'duplicate_rate'  => $value <= self::MAX_DUPLICATE_RATE,
            default           => true,
        };
    }

    private function writeAudit(
        string $runId,
        string $tenantId,
        string $eventType,
        string $actor,
        string $outcome,
        string $description
    ): void {
        ScalePilotAudit::create([
            'audit_id'    => 'spa-' . Str::uuid(),
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
