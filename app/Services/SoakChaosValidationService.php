<?php

namespace App\Services;

use App\Models\SoakValidationRun;
use App\Models\SoakValidationMetric;
use App\Models\ChaosSimulationRun;
use App\Models\ChaosFailureEvent;
use App\Models\RecoveryValidationArtifact;
use App\Models\OperationalDriftReport;
use App\Models\ReplayRecoveryRun;
use App\Models\TelemetryContinuityReport;
use App\Models\BoundedFailureScenario;
use Illuminate\Support\Str;

class SoakChaosValidationService
{
    public const ADVISORY_ONLY = true;

    // Drift thresholds
    public const MAX_MEMORY_GROWTH_MB        = 512.0;
    public const MAX_QUEUE_LAG_GROWTH        = 10_000;
    public const MAX_RETRY_AMPLIFICATION     = 3.0;
    public const MAX_TELEMETRY_GAP_RATE      = 0.05; // 5%
    public const MAX_DUPLICATE_RATE          = 0.01; // 1%
    public const MAX_WORKER_RESTARTS_PER_HOUR= 10;
    public const MAX_CHAOS_DURATION_SECONDS  = 600;  // 10 minutes per scenario
    public const MIN_CONTINUITY_PCT          = 0.95; // 95%

    // =========================================================================
    // Soak run
    // =========================================================================

    public function recordSoakRun(
        string $soakType,
        int    $durationMinutes,
        string $status,
        array  $metrics = []
    ): SoakValidationRun {
        if (!in_array($soakType, SoakValidationRun::SOAK_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid soak type: {$soakType}");
        }

        if (!in_array($status, SoakValidationRun::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $memGrowth  = $metrics['memory_growth_mb']        ?? 0.0;
        $queueGrowth= $metrics['queue_lag_growth']        ?? 0.0;
        $backlog    = $metrics['replay_backlog']           ?? 0;
        $dupRate    = $metrics['duplicate_event_rate']     ?? 0.0;
        $restarts   = $metrics['worker_restart_count']     ?? 0;
        $gapRate    = $metrics['telemetry_gap_rate']       ?? 0.0;
        $retryAmp   = $metrics['retry_amplification_factor']?? 0.0;

        $passed = $status === 'completed'
            && $memGrowth  <= self::MAX_MEMORY_GROWTH_MB
            && $dupRate    <= self::MAX_DUPLICATE_RATE
            && $gapRate    <= self::MAX_TELEMETRY_GAP_RATE
            && $retryAmp   <= self::MAX_RETRY_AMPLIFICATION;

        return SoakValidationRun::create([
            'run_id'                    => 'svr-' . Str::uuid(),
            'soak_type'                 => $soakType,
            'duration_minutes'          => $durationMinutes,
            'status'                    => $status,
            'passed'                    => $passed,
            'memory_growth_mb'          => $memGrowth,
            'queue_lag_growth'          => $queueGrowth,
            'replay_backlog'            => $backlog,
            'duplicate_event_rate'      => $dupRate,
            'worker_restart_count'      => $restarts,
            'telemetry_gap_rate'        => $gapRate,
            'retry_amplification_factor'=> $retryAmp,
            'is_advisory'               => true,
            'summary'                   => $metrics ?: null,
        ]);
    }

    public function recordSoakMetric(
        string  $runId,
        string  $metricName,
        float   $value,
        ?string $unit          = null,
        int     $offsetMinutes = 0,
        ?float  $baseline      = null
    ): SoakValidationMetric {
        $driftDelta    = $baseline !== null ? $value - $baseline : null;
        $driftDetected = $driftDelta !== null && abs($driftDelta) > (abs($baseline) * 0.1 + 0.001);

        return SoakValidationMetric::create([
            'metric_id'            => 'svm-' . Str::uuid(),
            'run_id'               => $runId,
            'metric_name'          => $metricName,
            'metric_value'         => $value,
            'unit'                 => $unit,
            'sample_offset_minutes'=> $offsetMinutes,
            'drift_detected'       => $driftDetected,
            'baseline_value'       => $baseline,
            'drift_delta'          => $driftDelta,
            'is_advisory'          => true,
        ]);
    }

    // =========================================================================
    // Chaos simulation
    // =========================================================================

    public function runChaosSimulation(
        string $scenario,
        int    $durationSeconds,
        array  $failureSequence = []
    ): ChaosSimulationRun {
        if (!in_array($scenario, ChaosSimulationRun::SCENARIOS, true)) {
            throw new \InvalidArgumentException("Invalid chaos scenario: {$scenario}");
        }

        if ($durationSeconds > self::MAX_CHAOS_DURATION_SECONDS) {
            throw new \OverflowException(
                "Chaos duration {$durationSeconds}s exceeds MAX={self::MAX_CHAOS_DURATION_SECONDS}s"
            );
        }

        $injected   = count($failureSequence);
        $recovered  = count(array_filter($failureSequence, fn($f) => ($f['recovered'] ?? false)));
        $verdict    = match (true) {
            $injected === 0         => 'partial',
            $recovered === $injected => 'pass',
            $recovered === 0        => 'fail',
            default                 => 'partial',
        };

        return ChaosSimulationRun::create([
            'simulation_id'       => 'csr-' . Str::uuid(),
            'scenario'            => $scenario,
            'duration_seconds'    => $durationSeconds,
            'recovery_verified'   => $recovered === $injected,
            'failures_injected'   => $injected,
            'recoveries_observed' => $recovered,
            'verdict'             => $verdict,
            'replay_safe'         => true,
            'isolation_preserved' => true,
            'is_advisory'         => true,
            'failure_sequence'    => $failureSequence ?: null,
        ]);
    }

    public function recordChaosFailureEvent(
        string  $simulationId,
        string  $failureType,
        string  $component,
        string  $outcome,
        int     $offsetSeconds    = 0,
        ?int    $recoverySeconds  = null
    ): ChaosFailureEvent {
        if (!in_array($component, ChaosFailureEvent::COMPONENTS, true)) {
            throw new \InvalidArgumentException("Invalid component: {$component}");
        }

        if (!in_array($outcome, ChaosFailureEvent::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Invalid outcome: {$outcome}");
        }

        return ChaosFailureEvent::create([
            'event_id'         => 'cfe-' . Str::uuid(),
            'simulation_id'    => $simulationId,
            'failure_type'     => $failureType,
            'component'        => $component,
            'offset_seconds'   => $offsetSeconds,
            'outcome'          => $outcome,
            'recovery_seconds' => $recoverySeconds,
            'replay_safe'      => true,
            'is_advisory'      => true,
        ]);
    }

    // =========================================================================
    // Recovery validation
    // =========================================================================

    public function validateRecovery(
        string  $recoveryType,
        bool    $recoveryOk,
        int     $recoverySeconds,
        bool    $duplicatesPrevented   = true,
        bool    $tenantIsolation       = true,
        bool    $graphIntegrity        = true,
        ?string $simulationId          = null,
        ?string $runId                 = null
    ): RecoveryValidationArtifact {
        if (!in_array($recoveryType, RecoveryValidationArtifact::RECOVERY_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid recovery type: {$recoveryType}");
        }

        $verdict = match (true) {
            !$recoveryOk              => 'fail',
            !$duplicatesPrevented     => 'fail',
            !$tenantIsolation         => 'fail',
            !$graphIntegrity          => 'partial',
            default                   => 'pass',
        };

        return RecoveryValidationArtifact::create([
            'artifact_id'                => 'rva-' . Str::uuid(),
            'simulation_id'              => $simulationId,
            'run_id'                     => $runId,
            'recovery_type'              => $recoveryType,
            'recovery_ok'                => $recoveryOk,
            'recovery_seconds'           => $recoverySeconds,
            'duplicates_prevented'       => $duplicatesPrevented,
            'tenant_isolation_preserved' => $tenantIsolation,
            'graph_integrity_preserved'  => $graphIntegrity,
            'verdict'                    => $verdict,
            'is_advisory'                => true,
        ]);
    }

    // =========================================================================
    // Drift detection
    // =========================================================================

    public function recordDrift(
        string  $driftType,
        float   $baseline,
        float   $observed,
        int     $windowMinutes,
        ?string $runId = null
    ): OperationalDriftReport {
        if (!in_array($driftType, OperationalDriftReport::DRIFT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid drift type: {$driftType}");
        }

        $delta    = $observed - $baseline;
        $pct      = $baseline != 0 ? ($delta / abs($baseline)) * 100.0 : 0.0;
        $exceeds  = abs($delta) > ($baseline * 0.20 + 0.001); // 20% threshold

        return OperationalDriftReport::create([
            'report_id'               => 'odr-' . Str::uuid(),
            'run_id'                  => $runId,
            'drift_type'              => $driftType,
            'baseline_value'          => $baseline,
            'observed_value'          => $observed,
            'drift_delta'             => $delta,
            'drift_pct'               => round($pct, 4),
            'window_minutes'          => $windowMinutes,
            'drift_exceeds_threshold' => $exceeds,
            'is_advisory'             => true,
        ]);
    }

    // =========================================================================
    // Replay recovery
    // =========================================================================

    public function recordReplayRecovery(
        string $trigger,
        int    $eventsPending,
        int    $eventsReplayed,
        int    $replaySeconds,
        bool   $orderingPreserved       = true,
        bool   $duplicatesPrevented     = true,
        bool   $tenantIsolation         = true,
        bool   $continuityVerified      = true
    ): ReplayRecoveryRun {
        if (!in_array($trigger, ReplayRecoveryRun::TRIGGERS, true)) {
            throw new \InvalidArgumentException("Invalid trigger: {$trigger}");
        }

        $verdict = match (true) {
            !$orderingPreserved    => 'fail',
            !$duplicatesPrevented  => 'fail',
            !$tenantIsolation      => 'fail',
            !$continuityVerified   => 'partial',
            default                => 'pass',
        };

        return ReplayRecoveryRun::create([
            'run_id'                     => 'rrr-' . Str::uuid(),
            'trigger'                    => $trigger,
            'events_pending'             => $eventsPending,
            'events_replayed'            => $eventsReplayed,
            'ordering_preserved'         => $orderingPreserved,
            'duplicates_prevented'       => $duplicatesPrevented,
            'tenant_isolation_preserved' => $tenantIsolation,
            'continuity_verified'        => $continuityVerified,
            'replay_seconds'             => $replaySeconds,
            'verdict'                    => $verdict,
            'is_advisory'                => true,
        ]);
    }

    // =========================================================================
    // Telemetry continuity
    // =========================================================================

    public function recordTelemetryContinuity(
        int     $observationWindowMinutes,
        int     $expectedEvents,
        int     $observedEvents,
        int     $gapCount       = 0,
        int     $totalGapSeconds= 0,
        ?string $soakRunId      = null
    ): TelemetryContinuityReport {
        $continuityPct = $expectedEvents > 0
            ? min(1.0, $observedEvents / $expectedEvents)
            : 0.0;
        $continuityOk  = $continuityPct >= self::MIN_CONTINUITY_PCT;
        $verdict       = $continuityOk ? 'pass' : ($continuityPct > 0.80 ? 'degraded' : 'fail');

        return TelemetryContinuityReport::create([
            'report_id'                  => 'tcr-' . Str::uuid(),
            'soak_run_id'                => $soakRunId,
            'observation_window_minutes' => $observationWindowMinutes,
            'expected_events'            => $expectedEvents,
            'observed_events'            => $observedEvents,
            'continuity_pct'             => $continuityPct,
            'gap_count'                  => $gapCount,
            'total_gap_seconds'          => $totalGapSeconds,
            'continuity_ok'              => $continuityOk,
            'verdict'                    => $verdict,
            'is_advisory'                => true,
        ]);
    }

    // =========================================================================
    // Scenario catalog
    // =========================================================================

    public function getEnabledScenarios(): \Illuminate\Database\Eloquent\Collection
    {
        return BoundedFailureScenario::where('enabled', true)
            ->where('destructive', false)
            ->get();
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_soak_runs'        => SoakValidationRun::count(),
            'soak_runs_passed'       => SoakValidationRun::where('passed', true)->count(),
            'soak_runs_failed'       => SoakValidationRun::where('passed', false)->where('status', 'completed')->count(),
            'chaos_runs_total'       => ChaosSimulationRun::count(),
            'chaos_pass'             => ChaosSimulationRun::where('verdict', 'pass')->count(),
            'chaos_fail'             => ChaosSimulationRun::where('verdict', 'fail')->count(),
            'recovery_pass'          => RecoveryValidationArtifact::where('verdict', 'pass')->count(),
            'recovery_fail'          => RecoveryValidationArtifact::where('verdict', 'fail')->count(),
            'drift_exceeded'         => OperationalDriftReport::where('drift_exceeds_threshold', true)->count(),
            'replay_recovery_pass'   => ReplayRecoveryRun::where('verdict', 'pass')->count(),
            'telemetry_continuity_ok'=> TelemetryContinuityReport::where('continuity_ok', true)->count(),
            'enabled_scenarios'      => BoundedFailureScenario::where('enabled', true)->count(),
        ];
    }
}
