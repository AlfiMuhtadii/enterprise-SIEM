<?php

namespace App\Services;

use App\Models\CapacityProjectionRun;
use App\Models\CardinalityPressureReport;
use App\Models\InfrastructureCostEstimate;
use App\Models\PartitionPressureSnapshot;
use App\Models\QueryPerformanceSnapshot;
use App\Models\ReplayAmplificationReport;
use App\Models\ReplayEconomicsRun;
use App\Models\StorageCapacitySnapshot;
use App\Models\TelemetryCapacitySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Capacity, Performance & Cost Governance Service — Phase 1.
 *
 * Operational visibility controls only. No autonomous scaling,
 * destructive retention purge, or hidden infrastructure mutation.
 *
 * NOT implemented: isolateHost, quarantineHost, executeShell, killProcess,
 * autoRemediate, silent retention purge, hidden storage mutation,
 * unsafe replay acceleration, automatic destructive compaction,
 * hidden auto-scaling, unbounded replay fanout.
 */
class CapacityGovernanceService
{
    // Amplification safe threshold — exceeding triggers a report
    public const SAFE_AMPLIFICATION_RATIO = 3.0;

    // Storage exhaustion warning threshold (days)
    public const EXHAUSTION_WARNING_DAYS = 90;

    // ─── Telemetry capacity snapshots ───────────────────────────────────────

    public function recordTelemetryCapacity(
        ?string $topic,
        float   $eventsPerSec,
        int     $eventsPerDay,
        float   $partitionUtilizationPct = 0.0,
        int     $queueDepth = 0,
        float   $ingestSpikeRatio = 1.0,
        float   $cardinalityPressureScore = 0.0,
        string  $windowDuration = '1m',
        array   $details = []
    ): TelemetryCapacitySnapshot {
        $growthPct    = $eventsPerDay > 0 ? min(100.0, ($eventsPerSec * 86400 / max($eventsPerDay, 1) - 1) * 100) : 0.0;
        $pressureState= $this->classifyTelemetryPressure($eventsPerSec, $partitionUtilizationPct, $cardinalityPressureScore);

        return TelemetryCapacitySnapshot::create([
            'snapshot_id'               => 'tcs-' . Str::random(32),
            'topic'                     => $topic,
            'events_per_sec'            => $eventsPerSec,
            'events_per_day'            => $eventsPerDay,
            'topic_growth_pct'          => $growthPct,
            'partition_utilization_pct' => $partitionUtilizationPct,
            'queue_depth'               => $queueDepth,
            'ingestion_spike_ratio'     => $ingestSpikeRatio,
            'cardinality_pressure_score'=> $cardinalityPressureScore,
            'pressure_state'            => $pressureState,
            'window_duration'           => $windowDuration,
            'details'                   => $details ?: null,
            'created_at'                => now(),
        ]);
    }

    private function classifyTelemetryPressure(float $eps, float $utilPct, float $cardScore): string
    {
        if ($utilPct >= 90.0 || $eps > 10000 || $cardScore > 0.8) {
            return TelemetryCapacitySnapshot::PRESSURE_CRITICAL;
        }
        if ($utilPct >= 75.0 || $eps > 5000 || $cardScore > 0.5) {
            return TelemetryCapacitySnapshot::PRESSURE_HIGH;
        }
        if ($utilPct >= 50.0 || $eps > 1000 || $cardScore > 0.2) {
            return TelemetryCapacitySnapshot::PRESSURE_ELEVATED;
        }
        return TelemetryCapacitySnapshot::PRESSURE_NORMAL;
    }

    // ─── Replay economics ────────────────────────────────────────────────────

    public function recordReplayEconomics(
        ?string $consumerGroup,
        ?string $topic,
        int     $durationSeconds,
        float   $throughputEps,
        int     $eventsReplayed,
        int     $replayBacklog = 0,
        float   $storagImpactMb = 0.0,
        float   $queuePressure = 0.0,
        ?string $triggeredBy = null
    ): ReplayEconomicsRun {
        $originalEstimate = max(1, $eventsReplayed / max($durationSeconds, 1));
        $amplificationRatio = $eventsReplayed > 0
            ? round($throughputEps / max($originalEstimate, 1), 3)
            : 1.0;
        $amplificationRatio = max(1.0, $amplificationRatio);

        $costEstimate = ($durationSeconds / 3600.0) * 0.5 + ($storagImpactMb / 1024.0) * 0.1;

        $concurrencyState = $this->classifyConcurrencyState($queuePressure, $amplificationRatio);

        return ReplayEconomicsRun::create([
            'run_id'                     => 'rer-' . Str::random(32),
            'consumer_group'             => $consumerGroup,
            'topic'                      => $topic,
            'replay_duration_seconds'    => $durationSeconds,
            'replay_throughput_eps'      => $throughputEps,
            'events_replayed'            => $eventsReplayed,
            'replay_backlog'             => $replayBacklog,
            'replay_amplification_ratio' => $amplificationRatio,
            'replay_storage_impact_mb'   => $storagImpactMb,
            'replay_cost_estimate'       => round($costEstimate, 4),
            'replay_queue_pressure'      => $queuePressure,
            'concurrency_state'          => $concurrencyState,
            'is_bounded'                 => $amplificationRatio <= self::SAFE_AMPLIFICATION_RATIO,
            'triggered_by'               => $triggeredBy,
            'created_at'                 => now(),
        ]);
    }

    private function classifyConcurrencyState(float $queuePressure, float $amplRatio): string
    {
        if ($amplRatio >= self::SAFE_AMPLIFICATION_RATIO || $queuePressure >= 0.9) {
            return ReplayEconomicsRun::CONCURRENCY_AT_LIMIT;
        }
        if ($queuePressure >= 0.7) {
            return ReplayEconomicsRun::CONCURRENCY_APPROACHING_LIMIT;
        }
        if ($queuePressure >= 0.4) {
            return ReplayEconomicsRun::CONCURRENCY_ELEVATED;
        }
        return ReplayEconomicsRun::CONCURRENCY_BOUNDED;
    }

    // ─── Query performance snapshots ─────────────────────────────────────────

    public function recordQueryPerformance(
        string  $backend,
        float   $p50Ms,
        float   $p95Ms,
        float   $p99Ms,
        int     $slowQueryCount = 0,
        float   $slowThresholdMs = 1000.0,
        float   $indexHitRatio = 1.0,
        float   $searchDegradationPct = 0.0,
        float   $replayOverheadPct = 0.0,
        array   $details = []
    ): QueryPerformanceSnapshot {
        $indexMissRatio = max(0.0, 1.0 - $indexHitRatio);
        $latencyState   = $this->classifyLatencyState($p95Ms, $slowQueryCount, $searchDegradationPct);

        return QueryPerformanceSnapshot::create([
            'snapshot_id'              => 'qps-' . Str::random(32),
            'backend'                  => $backend,
            'p50_latency_ms'           => $p50Ms,
            'p95_latency_ms'           => $p95Ms,
            'p99_latency_ms'           => $p99Ms,
            'slow_query_count'         => $slowQueryCount,
            'slow_query_threshold_ms'  => $slowThresholdMs,
            'index_hit_ratio'          => $indexHitRatio,
            'index_miss_ratio'         => $indexMissRatio,
            'search_degradation_pct'   => $searchDegradationPct,
            'replay_query_overhead_pct'=> $replayOverheadPct,
            'latency_state'            => $latencyState,
            'details'                  => $details ?: null,
            'created_at'               => now(),
        ]);
    }

    private function classifyLatencyState(float $p95Ms, int $slowCount, float $degradPct): string
    {
        if ($p95Ms >= 5000.0 || $slowCount > 50 || $degradPct >= 50.0) {
            return QueryPerformanceSnapshot::LATENCY_CRITICAL;
        }
        if ($p95Ms >= 2000.0 || $slowCount > 10 || $degradPct >= 20.0) {
            return QueryPerformanceSnapshot::LATENCY_DEGRADED;
        }
        if ($p95Ms >= 500.0 || $slowCount > 3 || $degradPct >= 5.0) {
            return QueryPerformanceSnapshot::LATENCY_ELEVATED;
        }
        return QueryPerformanceSnapshot::LATENCY_NORMAL;
    }

    // ─── Storage capacity snapshots ──────────────────────────────────────────

    public function recordStorageCapacity(
        string  $backend,
        int     $currentSizeBytes,
        float   $growthRateBytesPerDay,
        float   $compressionRatio = 1.0,
        float   $indexGrowthPct = 0.0,
        float   $shardPressurePct = 0.0,
        float   $partitionPressurePct = 0.0,
        float   $retentionCostEstimate = 0.0,
        array   $details = []
    ): StorageCapacitySnapshot {
        $exhaustionDate = $this->estimateExhaustionDate($currentSizeBytes, $growthRateBytesPerDay);
        $state          = $this->classifyCapacityState($shardPressurePct, $partitionPressurePct, $exhaustionDate);

        return StorageCapacitySnapshot::create([
            'snapshot_id'               => 'scs-' . Str::random(32),
            'backend'                   => $backend,
            'current_size_bytes'        => $currentSizeBytes,
            'growth_rate_bytes_per_day' => $growthRateBytesPerDay,
            'compression_ratio'         => $compressionRatio,
            'index_growth_pct'          => $indexGrowthPct,
            'shard_pressure_pct'        => $shardPressurePct,
            'partition_pressure_pct'    => $partitionPressurePct,
            'estimated_exhaustion_date' => $exhaustionDate,
            'retention_cost_estimate'   => $retentionCostEstimate,
            'capacity_state'            => $state,
            'details'                   => $details ?: null,
            'created_at'                => now(),
        ]);
    }

    private function estimateExhaustionDate(int $currentBytes, float $growthBytesPerDay): ?string
    {
        if ($growthBytesPerDay <= 0) {
            return null;
        }
        // Assume 10x headroom capacity
        $remainingCapacity = $currentBytes * 10;
        $daysUntilExhaustion = $remainingCapacity / $growthBytesPerDay;
        return now()->addDays((int) $daysUntilExhaustion)->toDateString();
    }

    private function classifyCapacityState(float $shardPct, float $partPct, ?string $exhaustionDate): string
    {
        if ($shardPct >= 90.0 || $partPct >= 90.0) {
            return StorageCapacitySnapshot::STATE_CRITICAL;
        }
        if ($shardPct >= 70.0 || $partPct >= 70.0) {
            return StorageCapacitySnapshot::STATE_PRESSURE;
        }
        if ($exhaustionDate && now()->diffInDays($exhaustionDate) < self::EXHAUSTION_WARNING_DAYS) {
            return StorageCapacitySnapshot::STATE_GROWING;
        }
        return StorageCapacitySnapshot::STATE_HEALTHY;
    }

    // ─── Cardinality pressure reports ────────────────────────────────────────

    public function reportCardinalityPressure(
        string  $fieldName,
        int     $cardinalityCount,
        string  $pressureType,
        ?string $sourceTable = null,
        float   $growthRatePct = 0.0,
        string  $severity = 'informational',
        array   $details = []
    ): CardinalityPressureReport {
        $bounded = $growthRatePct < 20.0 && $cardinalityCount < 1_000_000;

        return CardinalityPressureReport::create([
            'report_id'         => 'cpr-' . Str::random(32),
            'field_name'        => $fieldName,
            'source_table'      => $sourceTable,
            'cardinality_count' => $cardinalityCount,
            'growth_rate_pct'   => $growthRatePct,
            'pressure_type'     => $pressureType,
            'severity'          => $severity,
            'bounded'           => $bounded,
            'details'           => $details ?: null,
            'created_at'        => now(),
        ]);
    }

    // ─── Capacity projection runs ─────────────────────────────────────────────

    /**
     * Compute deterministic 30/90-day capacity projection.
     * Linear growth model: projection = current_daily × days.
     * All assumptions are explicit and operator-visible.
     */
    public function runCapacityProjection(
        string  $scope,
        float   $currentDailyGrowthBytes,
        float   $replayScalingThresholdEps = 1000.0,
        int     $workerScalingThreshold = 4,
        ?string $triggeredBy = null
    ): CapacityProjectionRun {
        $projected30d = $currentDailyGrowthBytes * 30;
        $projected90d = $currentDailyGrowthBytes * 90;

        $daysUntilExhaustion = $currentDailyGrowthBytes > 0
            ? (int) (1_073_741_824 * 10 / max($currentDailyGrowthBytes, 1))
            : 9999;
        $exhaustionForecast = $daysUntilExhaustion < 9999
            ? now()->addDays($daysUntilExhaustion)->toDateString()
            : null;

        $queueForecast = match(true) {
            $projected30d > 100_000_000_000 => 'critical',
            $projected30d > 10_000_000_000  => 'elevated',
            default                         => 'normal',
        };

        return CapacityProjectionRun::create([
            'run_id'                       => 'cpr-' . Str::random(32),
            'scope'                        => $scope,
            'projection_days'              => 90,
            'current_daily_growth_bytes'   => $currentDailyGrowthBytes,
            'projected_30d_bytes'          => $projected30d,
            'projected_90d_bytes'          => $projected90d,
            'replay_scaling_threshold_eps' => $replayScalingThresholdEps,
            'worker_scaling_threshold'     => $workerScalingThreshold,
            'storage_exhaustion_forecast'  => $exhaustionForecast,
            'queue_pressure_forecast'      => $queueForecast,
            'confidence_level'             => 0.70,
            'assumptions'                  => 'Linear growth model. Actual growth may vary. No autonomous scaling triggered.',
            'deterministic'                => true,
            'triggered_by'                 => $triggeredBy,
            'created_at'                   => now(),
        ]);
    }

    // ─── Replay amplification ─────────────────────────────────────────────────

    public function reportReplayAmplification(
        int     $originalCount,
        int     $replayedCount,
        ?string $consumerGroup = null,
        ?string $topic = null,
        float   $storageAmplificationMb = 0.0,
        ?string $triggeredBy = null
    ): ReplayAmplificationReport {
        $ratio     = $originalCount > 0 ? round($replayedCount / $originalCount, 3) : 1.0;
        $threshold = self::SAFE_AMPLIFICATION_RATIO;
        $exceeds   = $ratio > $threshold;

        return ReplayAmplificationReport::create([
            'report_id'               => 'rar-' . Str::random(32),
            'consumer_group'          => $consumerGroup,
            'topic'                   => $topic,
            'amplification_ratio'     => $ratio,
            'original_event_count'    => $originalCount,
            'replayed_event_count'    => $replayedCount,
            'storage_amplification_mb'=> $storageAmplificationMb,
            'exceeds_safe_threshold'  => $exceeds,
            'safe_threshold'          => $threshold,
            'triggered_by'            => $triggeredBy,
            'created_at'              => now(),
        ]);
    }

    // ─── Partition pressure ───────────────────────────────────────────────────

    public function upsertPartitionPressure(
        string  $topic,
        int     $partitionId,
        int     $offsetLag,
        float   $utilizationPct,
        float   $throughputEps = 0.0,
        bool    $isLeader = false,
        array   $metadata = []
    ): PartitionPressureSnapshot {
        $healthState  = $this->classifyPartitionHealth($offsetLag, $utilizationPct);
        $partitionKey = "{$topic}:{$partitionId}";

        return PartitionPressureSnapshot::updateOrCreate(
            ['partition_key' => $partitionKey],
            [
                'topic'           => $topic,
                'partition_id'    => $partitionId,
                'offset_lag'      => $offsetLag,
                'utilization_pct' => $utilizationPct,
                'throughput_eps'  => $throughputEps,
                'is_leader'       => $isLeader,
                'health_state'    => $healthState,
                'metadata'        => $metadata ?: null,
            ]
        );
    }

    private function classifyPartitionHealth(int $lag, float $utilPct): string
    {
        if ($lag > 100000 || $utilPct >= 90.0) {
            return PartitionPressureSnapshot::HEALTH_STALLED;
        }
        if ($lag > 10000 || $utilPct >= 75.0) {
            return PartitionPressureSnapshot::HEALTH_PRESSURE;
        }
        if ($lag > 1000) {
            return PartitionPressureSnapshot::HEALTH_LAGGING;
        }
        return PartitionPressureSnapshot::HEALTH_HEALTHY;
    }

    // ─── Infrastructure cost estimates ───────────────────────────────────────

    public function estimateCost(
        string  $scope,
        float   $dailyCostEstimate,
        float   $growthRatePct = 0.0,
        ?string $backend = null,
        string  $estimationMethod = 'linear_projection',
        ?string $assumptions = null
    ): InfrastructureCostEstimate {
        $monthly90d = $dailyCostEstimate * 30 * (1 + ($growthRatePct / 100));
        $projected90d = $dailyCostEstimate * 90 * (1 + ($growthRatePct / 100) * 2);

        return InfrastructureCostEstimate::create([
            'estimate_id'           => 'ice-' . Str::random(32),
            'scope'                 => $scope,
            'backend'               => $backend,
            'daily_cost_estimate'   => $dailyCostEstimate,
            'monthly_cost_estimate' => round($monthly90d, 4),
            'projected_90d_cost'    => round($projected90d, 4),
            'cost_unit'             => 'relative_units',
            'growth_rate_pct'       => $growthRatePct,
            'estimation_method'     => $estimationMethod,
            'assumptions'           => $assumptions ?? 'Linear cost model. No autonomous scaling triggered.',
            'is_advisory'           => true,
            'created_at'            => now(),
        ]);
    }

    // ─── Dashboard stats ─────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_telemetry_snapshots'   => TelemetryCapacitySnapshot::count(),
            'critical_telemetry'          => TelemetryCapacitySnapshot::where('pressure_state', 'critical')->count(),
            'total_replay_runs'           => ReplayEconomicsRun::count(),
            'unbounded_replays'           => ReplayEconomicsRun::where('is_bounded', false)->count(),
            'total_query_snapshots'       => QueryPerformanceSnapshot::count(),
            'degraded_queries'            => QueryPerformanceSnapshot::whereIn('latency_state', ['degraded', 'critical'])->count(),
            'total_storage_snapshots'     => StorageCapacitySnapshot::count(),
            'critical_storage'            => StorageCapacitySnapshot::where('capacity_state', StorageCapacitySnapshot::STATE_CRITICAL)->count(),
            'total_cardinality_reports'   => CardinalityPressureReport::count(),
            'total_projections'           => CapacityProjectionRun::count(),
            'total_amplification_reports' => ReplayAmplificationReport::count(),
            'exceeded_amplification'      => ReplayAmplificationReport::where('exceeds_safe_threshold', true)->count(),
            'total_partition_snapshots'   => PartitionPressureSnapshot::count(),
            'total_cost_estimates'        => InfrastructureCostEstimate::count(),
            'advisory_only'               => true,
            'autonomous_scaling'          => false,
        ];
    }
}
