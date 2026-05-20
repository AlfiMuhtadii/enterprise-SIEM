<?php

namespace App\Services;

use App\Models\DegradedModeEvent;
use App\Models\DuplicateEventReport;
use App\Models\IdempotencyValidationRecord;
use App\Models\RecoveryValidationRun;
use App\Models\ReplayThrottleState;
use App\Models\StoragePressureSnapshot;
use App\Models\StreamConsumerLagSnapshot;
use App\Models\SystemWorkerHeartbeat;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Distributed Reliability Service — HA / Scaling & Reliability Phase 1.
 *
 * Operational safeguards only. No autonomous remediation, destructive replay,
 * or hidden data deletion. All reliability behavior is deterministic,
 * audit-visible, bounded, and operator-visible.
 *
 * NOT implemented: isolateHost, quarantineHost, executeShell, killProcess,
 * autoRemediate, autonomous response, destructive replay, unsafe queue purge,
 * hidden data deletion, unbounded retry loops.
 */
class DistributedReliabilityService
{
    // ─── Worker heartbeat management ────────────────────────────────────────

    public function recordHeartbeat(
        string  $workerId,
        string  $serviceName,
        ?string $consumerGroup = null,
        array   $assignedPartitions = [],
        int     $currentLag = 0,
        int     $processedCount = 0,
        int     $failedCount = 0,
        int     $retryCount = 0,
        array   $metadata = []
    ): SystemWorkerHeartbeat {
        $healthState = $this->classifyWorkerHealth($currentLag, $failedCount, $retryCount);

        $heartbeat = SystemWorkerHeartbeat::updateOrCreate(
            ['worker_id' => $workerId],
            [
                'service_name'          => $serviceName,
                'consumer_group'        => $consumerGroup,
                'assigned_partitions'   => $assignedPartitions ?: null,
                'processed_event_count' => $processedCount,
                'failed_event_count'    => $failedCount,
                'retry_count'           => $retryCount,
                'current_lag'           => $currentLag,
                'health_state'          => $healthState,
                'last_heartbeat_at'     => now(),
                'is_stale'              => false,
                'is_stalled'            => false,
                'metadata'              => $metadata ?: null,
            ]
        );

        return $heartbeat;
    }

    public function markStaleWorkers(): int
    {
        $threshold = now()->subSeconds(SystemWorkerHeartbeat::STALE_THRESHOLD_SECONDS);
        return SystemWorkerHeartbeat::where('last_heartbeat_at', '<', $threshold)
            ->where('is_stale', false)
            ->update(['is_stale' => true, 'health_state' => SystemWorkerHeartbeat::HEALTH_DISCONNECTED]);
    }

    public function markStalledWorkers(): int
    {
        $threshold = now()->subSeconds(SystemWorkerHeartbeat::STALLED_THRESHOLD_SECONDS);
        return SystemWorkerHeartbeat::where('last_heartbeat_at', '<', $threshold)
            ->where('is_stalled', false)
            ->update(['is_stalled' => true, 'health_state' => SystemWorkerHeartbeat::HEALTH_STALLED]);
    }

    public function getWorkersByHealth(?string $healthState = null): Collection
    {
        $q = SystemWorkerHeartbeat::orderByDesc('last_heartbeat_at');
        if ($healthState) {
            $q->where('health_state', $healthState);
        }
        return $q->get();
    }

    private function classifyWorkerHealth(int $lag, int $failedCount, int $retryCount): string
    {
        if ($failedCount > 10 || $retryCount > 50) {
            return SystemWorkerHeartbeat::HEALTH_DEGRADED;
        }
        if ($lag >= 10000) {
            return SystemWorkerHeartbeat::HEALTH_LAGGING;
        }
        return SystemWorkerHeartbeat::HEALTH_HEALTHY;
    }

    // ─── Consumer lag snapshots (append-only) ────────────────────────────────

    public function recordLagSnapshot(
        string  $consumerGroup,
        string  $topic,
        int     $lagCount,
        int     $partition = 0,
        ?string $workerId = null,
        int     $lagAgeSeconds = 0,
        int     $poisonMessageCount = 0,
        int     $idempotencyViolations = 0,
        int     $retryBudgetRemaining = 100,
        bool    $dlqGrowing = false,
        ?string $traceId = null
    ): StreamConsumerLagSnapshot {
        $trend = $this->classifyLagTrend($consumerGroup, $topic, $lagCount);
        $state = $this->classifyPressureState($lagCount);

        return StreamConsumerLagSnapshot::create([
            'snapshot_id'           => 'scls-' . Str::random(28),
            'consumer_group'        => $consumerGroup,
            'topic'                 => $topic,
            'partition'             => $partition,
            'worker_id'             => $workerId,
            'lag_count'             => $lagCount,
            'lag_age_seconds'       => $lagAgeSeconds,
            'poison_message_count'  => $poisonMessageCount,
            'idempotency_violations'=> $idempotencyViolations,
            'retry_budget_remaining'=> $retryBudgetRemaining,
            'trend'                 => $trend,
            'pressure_state'        => $state,
            'dlq_growing'           => $dlqGrowing,
            'trace_id'              => $traceId,
            'created_at'            => now(),
        ]);
    }

    private function classifyLagTrend(string $group, string $topic, int $currentLag): string
    {
        $prev = StreamConsumerLagSnapshot::where('consumer_group', $group)
            ->where('topic', $topic)
            ->orderByDesc('created_at')
            ->value('lag_count');

        if ($prev === null) {
            return 'stable';
        }
        if ($currentLag > $prev * 1.1) {
            return 'increasing';
        }
        if ($currentLag < $prev * 0.9) {
            return 'decreasing';
        }
        return 'stable';
    }

    private function classifyPressureState(int $lagCount): string
    {
        if ($lagCount >= StreamConsumerLagSnapshot::LAG_CRITICAL_COUNT) {
            return StreamConsumerLagSnapshot::PRESSURE_CRITICAL;
        }
        if ($lagCount >= StreamConsumerLagSnapshot::LAG_WARNING_COUNT) {
            return StreamConsumerLagSnapshot::PRESSURE_WARNING;
        }
        return StreamConsumerLagSnapshot::PRESSURE_NORMAL;
    }

    // ─── Replay throttle state ───────────────────────────────────────────────

    public function getOrCreateThrottle(string $consumerGroup, ?string $topic = null): ReplayThrottleState
    {
        return ReplayThrottleState::firstOrCreate(
            ['consumer_group' => $consumerGroup, 'topic' => $topic],
            [
                'throttle_id'          => 'rts-' . Str::random(32),
                'max_events_per_second'=> 100,
                'retry_budget_limit'   => 50,
                'current_retry_count'  => 0,
                'state'                => ReplayThrottleState::STATE_NORMAL,
                'is_active'            => true,
            ]
        );
    }

    public function incrementRetryCount(ReplayThrottleState $throttle): void
    {
        $throttle->increment('current_retry_count');
        $throttle->refresh();

        if ($throttle->current_retry_count >= $throttle->retry_budget_limit) {
            $throttle->update([
                'state'               => ReplayThrottleState::STATE_EXHAUSTED,
                'throttle_started_at' => $throttle->throttle_started_at ?? now(),
            ]);
        } elseif ($throttle->current_retry_count >= ($throttle->retry_budget_limit * 0.7)) {
            $throttle->update([
                'state'               => ReplayThrottleState::STATE_THROTTLED,
                'throttle_started_at' => $throttle->throttle_started_at ?? now(),
            ]);
        }
    }

    public function resetThrottle(ReplayThrottleState $throttle): void
    {
        $throttle->update([
            'current_retry_count'   => 0,
            'state'                 => ReplayThrottleState::STATE_NORMAL,
            'throttle_cleared_at'   => now(),
        ]);
    }

    // ─── Idempotency validation ──────────────────────────────────────────────

    /**
     * Check/register event idempotency. Returns the record and whether it's a duplicate.
     * Deterministic: same fingerprint always yields the same result.
     * Never mutates source events or append-only tables.
     */
    public function validateIdempotency(
        string  $eventId,
        string  $traceId,
        string  $topic
    ): array {
        $fingerprint = IdempotencyValidationRecord::computeFingerprint($eventId, $traceId, $topic);

        $existing = IdempotencyValidationRecord::where('event_fingerprint', $fingerprint)->first();

        if ($existing) {
            $existing->increment('replay_count');
            $isDuplicate = $existing->processing_state === IdempotencyValidationRecord::STATE_PROCESSED;

            if ($isDuplicate) {
                $existing->update([
                    'processing_state' => IdempotencyValidationRecord::STATE_DUPLICATE,
                    'is_duplicate'     => true,
                ]);

                $this->reportDuplicate($fingerprint, $topic, $traceId, $eventId, null,
                    'Replay of already-processed event');
            } else {
                $existing->update(['processing_state' => IdempotencyValidationRecord::STATE_REPLAYED_SAFE]);
            }

            return ['is_new' => false, 'is_duplicate' => $isDuplicate, 'record' => $existing->fresh()];
        }

        $record = IdempotencyValidationRecord::create([
            'record_id'         => 'ivr-' . Str::random(32),
            'event_fingerprint' => $fingerprint,
            'source_topic'      => $topic,
            'trace_id'          => $traceId,
            'event_id'          => $eventId,
            'processing_state'  => IdempotencyValidationRecord::STATE_FIRST_SEEN,
            'is_duplicate'      => false,
            'replay_count'      => 0,
            'corruption_risk'   => false,
        ]);

        return ['is_new' => true, 'is_duplicate' => false, 'record' => $record];
    }

    public function markEventProcessed(string $eventId, string $traceId, string $topic): void
    {
        $fingerprint = IdempotencyValidationRecord::computeFingerprint($eventId, $traceId, $topic);
        IdempotencyValidationRecord::where('event_fingerprint', $fingerprint)
            ->update(['processing_state' => IdempotencyValidationRecord::STATE_PROCESSED]);
    }

    // ─── Duplicate event reporting (append-only) ─────────────────────────────

    public function reportDuplicate(
        string  $fingerprint,
        string  $sourceTopic,
        string  $traceId,
        ?string $originalEventId = null,
        ?string $duplicateEventId = null,
        ?string $detectionReason = null,
        string  $severity = DuplicateEventReport::SEVERITY_INFORMATIONAL
    ): DuplicateEventReport {
        return DuplicateEventReport::create([
            'report_id'          => 'der-' . Str::random(32),
            'event_fingerprint'  => $fingerprint,
            'source_topic'       => $sourceTopic,
            'trace_id'           => $traceId,
            'original_event_id'  => $originalEventId,
            'duplicate_event_id' => $duplicateEventId,
            'detection_reason'   => $detectionReason,
            'severity'           => $severity,
            'suppressed'         => false,
            'analytics_protected'=> true,
            'created_at'         => now(),
        ]);
    }

    // ─── Storage pressure snapshots (append-only) ────────────────────────────

    public function recordStoragePressure(
        string  $backend,
        float   $retentionPressurePct,
        ?int    $indexSizeBytes = null,
        ?float  $ingestionLagSeconds = null,
        ?float  $writeFailureRate = null,
        ?float  $queryLatencyMs = null,
        ?float  $compactionPct = null,
        array   $details = []
    ): StoragePressureSnapshot {
        $state = $this->classifyStoragePressure($retentionPressurePct, $writeFailureRate ?? 0.0);

        return StoragePressureSnapshot::create([
            'snapshot_id'             => 'sps-' . Str::random(32),
            'backend'                 => $backend,
            'retention_pressure_pct'  => $retentionPressurePct,
            'index_size_bytes'        => $indexSizeBytes,
            'ingestion_lag_seconds'   => $ingestionLagSeconds,
            'write_failure_rate'      => $writeFailureRate,
            'query_latency_ms'        => $queryLatencyMs,
            'compaction_pressure_pct' => $compactionPct,
            'pressure_state'          => $state,
            'degraded'                => $state === StoragePressureSnapshot::PRESSURE_CRITICAL,
            'details'                 => $details ?: null,
            'created_at'              => now(),
        ]);
    }

    private function classifyStoragePressure(float $retentionPct, float $writeFailureRate): string
    {
        if ($retentionPct >= 90.0 || $writeFailureRate >= 0.1) {
            return StoragePressureSnapshot::PRESSURE_CRITICAL;
        }
        if ($retentionPct >= 75.0 || $writeFailureRate >= 0.01) {
            return StoragePressureSnapshot::PRESSURE_WARNING;
        }
        return StoragePressureSnapshot::PRESSURE_NORMAL;
    }

    // ─── Degraded mode events (append-only) ──────────────────────────────────

    public function recordDegradedModeEntry(
        string  $serviceName,
        string  $degradedReason,
        ?string $description = null,
        array   $metadata = [],
        ?string $traceId = null
    ): DegradedModeEvent {
        return DegradedModeEvent::create([
            'event_id'              => 'dme-' . Str::random(32),
            'service_name'          => $serviceName,
            'degraded_reason'       => $degradedReason,
            'event_type'            => DegradedModeEvent::EVENT_TYPE_ENTERED,
            'description'           => $description,
            'operator_acknowledged' => false,
            'auto_cleared'          => false,
            'trace_id'              => $traceId,
            'metadata'              => $metadata ?: null,
            'created_at'            => now(),
        ]);
    }

    public function recordDegradedModeCleared(
        string  $serviceName,
        string  $degradedReason,
        bool    $autoClear = false,
        ?string $traceId = null
    ): DegradedModeEvent {
        return DegradedModeEvent::create([
            'event_id'              => 'dme-' . Str::random(32),
            'service_name'          => $serviceName,
            'degraded_reason'       => $degradedReason,
            'event_type'            => DegradedModeEvent::EVENT_TYPE_CLEARED,
            'operator_acknowledged' => !$autoClear,
            'auto_cleared'          => $autoClear,
            'trace_id'              => $traceId,
            'created_at'            => now(),
        ]);
    }

    public function getDegradedTimeline(string $serviceName): Collection
    {
        return DegradedModeEvent::where('service_name', $serviceName)
            ->orderBy('created_at')
            ->get();
    }

    // ─── Recovery validation runs (append-only) ──────────────────────────────

    public function startRecoveryRun(string $scenario, ?string $triggeredBy = null, ?string $traceId = null): RecoveryValidationRun
    {
        return RecoveryValidationRun::create([
            'run_id'     => 'rvr-' . Str::random(32),
            'scenario'   => $scenario,
            'triggered_by'=> $triggeredBy,
            'status'     => RecoveryValidationRun::STATUS_RUNNING,
            'trace_id'   => $traceId,
            'created_at' => now(),
        ]);
    }

    /**
     * Complete a recovery validation run. Runs are append-only so this
     * creates a new record reflecting the final state, NOT mutating the original.
     */
    public function completeRecoveryRun(
        string  $runId,
        string  $scenario,
        int     $totalChecks,
        int     $passedChecks,
        bool    $tracePropagationOk,
        bool    $replayIdempotencyOk,
        bool    $workerStaleDetectionOk,
        array   $checkResults = [],
        ?string $failureSummary = null,
        ?string $traceId = null
    ): RecoveryValidationRun {
        $failedChecks = $totalChecks - $passedChecks;
        $status = match(true) {
            $failedChecks === 0 => RecoveryValidationRun::STATUS_PASSED,
            $failedChecks < $totalChecks => RecoveryValidationRun::STATUS_WARNING,
            default => RecoveryValidationRun::STATUS_FAILED,
        };

        return RecoveryValidationRun::create([
            'run_id'                    => 'rvr-' . Str::random(32),
            'scenario'                  => $scenario,
            'status'                    => $status,
            'total_checks'              => $totalChecks,
            'passed_checks'             => $passedChecks,
            'failed_checks'             => $failedChecks,
            'trace_propagation_ok'      => $tracePropagationOk,
            'replay_idempotency_ok'     => $replayIdempotencyOk,
            'worker_stale_detection_ok' => $workerStaleDetectionOk,
            'check_results'             => $checkResults ?: null,
            'failure_summary'           => $failureSummary,
            'trace_id'                  => $traceId,
            'completed_at'              => now(),
            'created_at'                => now(),
        ]);
    }

    // ─── Dashboard stats ─────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_workers'             => SystemWorkerHeartbeat::count(),
            'healthy_workers'           => SystemWorkerHeartbeat::where('health_state', SystemWorkerHeartbeat::HEALTH_HEALTHY)->count(),
            'stale_workers'             => SystemWorkerHeartbeat::where('is_stale', true)->count(),
            'stalled_workers'           => SystemWorkerHeartbeat::where('is_stalled', true)->count(),
            'total_lag_snapshots'       => StreamConsumerLagSnapshot::count(),
            'critical_lag_count'        => StreamConsumerLagSnapshot::where('pressure_state', 'critical')->count(),
            'total_throttle_states'     => ReplayThrottleState::count(),
            'throttled_topics'          => ReplayThrottleState::where('state', ReplayThrottleState::STATE_THROTTLED)->count(),
            'exhausted_topics'          => ReplayThrottleState::where('state', ReplayThrottleState::STATE_EXHAUSTED)->count(),
            'total_idempotency_records' => IdempotencyValidationRecord::count(),
            'duplicate_events'          => IdempotencyValidationRecord::where('is_duplicate', true)->count(),
            'total_duplicate_reports'   => DuplicateEventReport::count(),
            'total_storage_snapshots'   => StoragePressureSnapshot::count(),
            'degraded_backends'         => StoragePressureSnapshot::selectRaw('DISTINCT ON (backend) backend, pressure_state')->orderByRaw('backend, id DESC')->get()->where('pressure_state', '!=', 'normal')->count(),
            'degraded_mode_entries'     => DegradedModeEvent::where('event_type', DegradedModeEvent::EVENT_TYPE_ENTERED)->count(),
            'recovery_runs_passed'      => RecoveryValidationRun::where('status', RecoveryValidationRun::STATUS_PASSED)->count(),
            'advisory_only'             => true,
            'autonomous_remediation'    => false,
        ];
    }
}
