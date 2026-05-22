<?php

namespace App\Services;

use App\Models\CollectorHealthEvent;
use App\Models\CollectorRestartAudit;
use App\Models\EndpointUpgradeValidation;
use App\Models\OfflineRecoveryRun;
use App\Models\PackageSignatureValidation;
use App\Models\SensorResourceSnapshot;
use App\Models\TelemetryGapReport;
use App\Models\TelemetryIntegrityRun;
use App\Models\TelemetrySequenceValidation;
use Illuminate\Support\Str;

/**
 * Sensor Hardening Phase 2.
 *
 * Advisory-only, replay-safe, deterministic sensor governance.
 * No autonomous remediation, kernel enforcement, or destructive endpoint action.
 * No hidden collector restart, no silent event loss, no hidden persistence mutation.
 */
class SensorHardeningService
{
    // ─── Bounded thresholds ───────────────────────────────────────────────────

    public const MAX_SPOOL_SIZE_KB         = 102_400; // 100 MB
    public const CRITICAL_CPU_PCT          = 80.0;
    public const CRITICAL_MEMORY_MB        = 512;
    public const MAX_RESTART_RATE_24H      = 10;
    public const MAX_OFFLINE_BUFFER_EVENTS = 50_000;

    // ─── Resource Snapshot ───────────────────────────────────────────────────

    public function recordResourceSnapshot(
        string $agentId,
        float  $cpuPct,
        int    $memoryMb,
        int    $spoolSizeKb,
        int    $queueDepth,
        string $hostId = '',
        float  $eventBurstRate = 0.0,
        int    $diskPressureKb = 0,
    ): SensorResourceSnapshot {
        $pressureState = $this->classifyResourcePressure($cpuPct, $memoryMb, $spoolSizeKb);

        $snap = new SensorResourceSnapshot([
            'snapshot_id'     => 'srs-' . Str::random(32),
            'agent_id'        => $agentId,
            'host_id'         => $hostId ?: null,
            'cpu_pct'         => round($cpuPct, 2),
            'memory_mb'       => $memoryMb,
            'spool_size_kb'   => $spoolSizeKb,
            'queue_depth'     => $queueDepth,
            'event_burst_rate'=> round($eventBurstRate, 2),
            'disk_pressure_kb'=> $diskPressureKb,
            'pressure_state'  => $pressureState,
        ]);
        $snap->save();
        return $snap;
    }

    // ─── Collector Health ────────────────────────────────────────────────────

    public function recordCollectorHealthEvent(
        string  $agentId,
        string  $healthState,
        string  $eventType,
        string  $hostId = '',
        string  $previousState = '',
        string  $reason = '',
        bool    $operatorNotified = false,
    ): CollectorHealthEvent {
        if (!in_array($healthState, CollectorHealthEvent::HEALTH_STATES, true)) {
            throw new \InvalidArgumentException("Invalid health_state: {$healthState}");
        }
        if (!in_array($eventType, CollectorHealthEvent::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid event_type: {$eventType}");
        }

        $event = new CollectorHealthEvent([
            'event_id'          => 'che-' . Str::random(32),
            'agent_id'          => $agentId,
            'host_id'           => $hostId ?: null,
            'health_state'      => $healthState,
            'previous_state'    => $previousState ?: null,
            'event_type'        => $eventType,
            'reason'            => $reason ?: null,
            'operator_notified' => $operatorNotified,
        ]);
        $event->save();
        return $event;
    }

    // ─── Telemetry Integrity ─────────────────────────────────────────────────

    public function runTelemetryIntegrityCheck(
        string $agentId,
        int    $eventsChecked,
        string $hostId = '',
        bool   $checksumValid = true,
        bool   $sequenceValid = true,
        bool   $replaySafe = true,
        int    $corruptionCount = 0,
        array  $integrityDetails = [],
    ): TelemetryIntegrityRun {
        $verdict = $this->computeIntegrityVerdict($checksumValid, $sequenceValid, $replaySafe, $corruptionCount);

        $run = new TelemetryIntegrityRun([
            'run_id'            => 'tir-' . Str::random(32),
            'agent_id'          => $agentId,
            'host_id'           => $hostId ?: null,
            'checksum_valid'    => $checksumValid,
            'sequence_valid'    => $sequenceValid,
            'replay_safe'       => $replaySafe,
            'events_checked'    => $eventsChecked,
            'corruption_count'  => $corruptionCount,
            'verdict'           => $verdict,
            'integrity_details' => $integrityDetails,
        ]);
        $run->save();
        return $run;
    }

    // ─── Telemetry Gap ───────────────────────────────────────────────────────

    public function reportTelemetryGap(
        string  $agentId,
        int     $gapDurationSeconds,
        string  $hostId = '',
        int     $estimatedLostEvents = 0,
        string  $gapReason = '',
        bool    $recovered = false,
        bool    $replayAttempted = false,
        ?string $gapStartedAt = null,
        ?string $gapEndedAt = null,
    ): TelemetryGapReport {
        $report = new TelemetryGapReport([
            'report_id'              => 'tgr-' . Str::random(32),
            'agent_id'               => $agentId,
            'host_id'                => $hostId ?: null,
            'gap_duration_seconds'   => $gapDurationSeconds,
            'estimated_lost_events'  => $estimatedLostEvents,
            'gap_reason'             => $gapReason ?: null,
            'recovered'              => $recovered,
            'replay_attempted'       => $replayAttempted,
            'gap_started_at'         => $gapStartedAt,
            'gap_ended_at'           => $gapEndedAt,
        ]);
        $report->save();
        return $report;
    }

    // ─── Package Signature Validation ────────────────────────────────────────

    public function validatePackageSignature(
        string $packageName,
        string $packageVersion,
        string $validatedBy,
        string $agentId = '',
        string $expectedHash = '',
        string $observedHash = '',
        string $signer = '',
    ): PackageSignatureValidation {
        $hashValid      = $expectedHash !== '' && $expectedHash === $observedHash;
        $signatureValid = $hashValid && $signer !== '';
        $verdict        = $signatureValid ? PackageSignatureValidation::VERDICT_PASS
                        : ($expectedHash !== '' ? PackageSignatureValidation::VERDICT_FAIL
                        : PackageSignatureValidation::VERDICT_UNKNOWN);

        $val = new PackageSignatureValidation([
            'validation_id'  => 'psv-' . Str::random(32),
            'agent_id'       => $agentId ?: null,
            'package_name'   => $packageName,
            'package_version'=> $packageVersion,
            'expected_hash'  => $expectedHash ?: null,
            'observed_hash'  => $observedHash ?: null,
            'signer'         => $signer ?: null,
            'signature_valid'=> $signatureValid,
            'hash_valid'     => $hashValid,
            'verdict'        => $verdict,
            'validated_by'   => $validatedBy,
        ]);
        $val->save();
        return $val;
    }

    // ─── Offline Recovery ────────────────────────────────────────────────────

    public function recordOfflineRecovery(
        string $agentId,
        int    $offlineDurationSeconds,
        int    $bufferedEventCount,
        int    $replayedEventCount,
        string $hostId = '',
        int    $droppedEventCount = 0,
        bool   $replayComplete = false,
        bool   $sequenceContinuityOk = false,
    ): OfflineRecoveryRun {
        $bounded        = min($bufferedEventCount, self::MAX_OFFLINE_BUFFER_EVENTS);
        $verdict        = match (true) {
            $replayComplete && $sequenceContinuityOk => OfflineRecoveryRun::VERDICT_COMPLETE,
            $replayComplete && !$sequenceContinuityOk => OfflineRecoveryRun::VERDICT_PARTIAL,
            $replayedEventCount > 0                  => OfflineRecoveryRun::VERDICT_PARTIAL,
            default                                  => OfflineRecoveryRun::VERDICT_FAILED,
        };

        $run = new OfflineRecoveryRun([
            'run_id'                  => 'orr-' . Str::random(32),
            'agent_id'                => $agentId,
            'host_id'                 => $hostId ?: null,
            'offline_duration_seconds'=> $offlineDurationSeconds,
            'buffered_event_count'    => $bounded,
            'replayed_event_count'    => $replayedEventCount,
            'dropped_event_count'     => $droppedEventCount,
            'replay_complete'         => $replayComplete,
            'sequence_continuity_ok'  => $sequenceContinuityOk,
            'recovery_verdict'        => $verdict,
        ]);
        $run->save();
        return $run;
    }

    // ─── Collector Restart Audit ─────────────────────────────────────────────

    public function auditCollectorRestart(
        string $agentId,
        int    $restartCount24h,
        string $hostId = '',
        string $restartReason = '',
        bool   $operatorInitiated = false,
        bool   $crashInduced = false,
        string $priorHealthState = '',
    ): CollectorRestartAudit {
        $audit = new CollectorRestartAudit([
            'audit_id'           => 'cra-' . Str::random(32),
            'agent_id'           => $agentId,
            'host_id'            => $hostId ?: null,
            'restart_reason'     => $restartReason ?: null,
            'restart_count_24h'  => $restartCount24h,
            'operator_initiated' => $operatorInitiated,
            'crash_induced'      => $crashInduced,
            'prior_health_state' => $priorHealthState ?: null,
        ]);
        $audit->save();
        return $audit;
    }

    // ─── Sequence Validation ────────────────────────────────────────────────

    public function validateSequenceContinuity(
        string $agentId,
        int    $expectedSequence,
        int    $observedSequence,
        string $hostId = '',
        int    $gapCount = 0,
        int    $duplicateCount = 0,
    ): TelemetrySequenceValidation {
        $continuityOk = $gapCount === 0 && $duplicateCount === 0 && $expectedSequence === $observedSequence;
        $verdict      = $continuityOk ? 'pass' : ($gapCount > 0 ? 'gap_detected' : 'duplicate_detected');

        $val = new TelemetrySequenceValidation([
            'validation_id'     => 'tsv-' . Str::random(32),
            'agent_id'          => $agentId,
            'host_id'           => $hostId ?: null,
            'expected_sequence' => $expectedSequence,
            'observed_sequence' => $observedSequence,
            'gap_count'         => $gapCount,
            'duplicate_count'   => $duplicateCount,
            'continuity_ok'     => $continuityOk,
            'verdict'           => $verdict,
        ]);
        $val->save();
        return $val;
    }

    // ─── Upgrade Validation ──────────────────────────────────────────────────

    public function validateUpgrade(
        string $agentId,
        string $fromVersion,
        string $toVersion,
        string $validatedBy,
        string $hostId = '',
        bool   $packageVerified = false,
        bool   $rollbackAvailable = false,
        bool   $telemetryResumed = false,
    ): EndpointUpgradeValidation {
        $verdict = ($packageVerified && $telemetryResumed)
            ? EndpointUpgradeValidation::VERDICT_PASS
            : ($packageVerified ? 'pass_package_only' : EndpointUpgradeValidation::VERDICT_FAIL);

        $val = new EndpointUpgradeValidation([
            'validation_id'    => 'euv-' . Str::random(32),
            'agent_id'         => $agentId,
            'host_id'          => $hostId ?: null,
            'from_version'     => $fromVersion,
            'to_version'       => $toVersion,
            'package_verified' => $packageVerified,
            'rollback_available'=> $rollbackAvailable,
            'telemetry_resumed'=> $telemetryResumed,
            'verdict'          => $verdict,
            'validated_by'     => $validatedBy,
        ]);
        $val->save();
        return $val;
    }

    // ─── Dashboard Stats ──────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_resource_snapshots'  => SensorResourceSnapshot::count(),
            'critical_resource_pressure'=> SensorResourceSnapshot::where('pressure_state', 'critical')->count(),
            'total_collector_events'    => CollectorHealthEvent::count(),
            'unhealthy_collector_events'=> CollectorHealthEvent::whereIn('health_state', ['degraded','stalled','disconnected'])->count(),
            'total_integrity_runs'      => TelemetryIntegrityRun::count(),
            'integrity_failures'        => TelemetryIntegrityRun::where('verdict', 'fail')->count(),
            'total_gap_reports'         => TelemetryGapReport::count(),
            'unrecovered_gaps'          => TelemetryGapReport::where('recovered', false)->count(),
            'total_pkg_validations'     => PackageSignatureValidation::count(),
            'pkg_validation_failures'   => PackageSignatureValidation::where('verdict', 'fail')->count(),
            'total_offline_runs'        => OfflineRecoveryRun::count(),
            'failed_recoveries'         => OfflineRecoveryRun::where('recovery_verdict', 'failed')->count(),
            'total_restart_audits'      => CollectorRestartAudit::count(),
            'crash_induced_restarts'    => CollectorRestartAudit::where('crash_induced', true)->count(),
            'total_sequence_validations'=> TelemetrySequenceValidation::count(),
            'sequence_failures'         => TelemetrySequenceValidation::where('continuity_ok', false)->count(),
            'total_upgrade_validations' => EndpointUpgradeValidation::count(),
            'upgrade_failures'          => EndpointUpgradeValidation::where('verdict', 'fail')->count(),
            'advisory_only'             => true,
        ];
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    private function classifyResourcePressure(float $cpu, int $memMb, int $spoolKb): string
    {
        if ($cpu >= self::CRITICAL_CPU_PCT || $memMb >= self::CRITICAL_MEMORY_MB || $spoolKb >= self::MAX_SPOOL_SIZE_KB) {
            return 'critical';
        }
        if ($cpu >= 60.0 || $memMb >= 256 || $spoolKb >= self::MAX_SPOOL_SIZE_KB * 0.75) {
            return 'high';
        }
        if ($cpu >= 40.0 || $memMb >= 128) {
            return 'elevated';
        }
        return 'normal';
    }

    private function computeIntegrityVerdict(bool $checksum, bool $sequence, bool $replay, int $corruption): string
    {
        if ($checksum && $sequence && $replay && $corruption === 0) {
            return TelemetryIntegrityRun::VERDICT_PASS;
        }
        if (!$checksum || $corruption > 0) {
            return TelemetryIntegrityRun::VERDICT_FAIL;
        }
        return TelemetryIntegrityRun::VERDICT_PARTIAL;
    }
}
