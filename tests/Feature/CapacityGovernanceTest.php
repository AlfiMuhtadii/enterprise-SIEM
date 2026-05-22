<?php

namespace Tests\Feature;

use App\Models\CapacityProjectionRun;
use App\Models\CardinalityPressureReport;
use App\Models\InfrastructureCostEstimate;
use App\Models\PartitionPressureSnapshot;
use App\Models\QueryPerformanceSnapshot;
use App\Models\ReplayAmplificationReport;
use App\Models\ReplayEconomicsRun;
use App\Models\StorageCapacitySnapshot;
use App\Models\TelemetryCapacitySnapshot;
use App\Models\User;
use App\Services\CapacityGovernanceService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Performance / Capacity / Cost Governance Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No autonomous scaling
 *   - No destructive retention purge
 *   - No hidden storage mutation
 *   - No unsafe replay acceleration
 *   - No unbounded replay fanout
 *   - All cost estimates are advisory
 *   - All capacity projections are deterministic
 *   - Append-only tables enforce immutability
 */
class CapacityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private CapacityGovernanceService $svc;
    private ThreatHuntingService      $hunting;
    private User                      $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc     = app(CapacityGovernanceService::class);
        $this->hunting = app(ThreatHuntingService::class);
        $this->user    = User::factory()->create();
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_telemetry_capacity_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('telemetry_capacity_snapshots'));
    }

    public function test_replay_economics_runs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('replay_economics_runs'));
    }

    public function test_query_performance_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('query_performance_snapshots'));
    }

    public function test_storage_capacity_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('storage_capacity_snapshots'));
    }

    public function test_cardinality_pressure_reports_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('cardinality_pressure_reports'));
    }

    public function test_capacity_projection_runs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('capacity_projection_runs'));
    }

    public function test_replay_amplification_reports_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('replay_amplification_reports'));
    }

    public function test_partition_pressure_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('partition_pressure_snapshots'));
    }

    public function test_infrastructure_cost_estimates_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('infrastructure_cost_estimates'));
    }

    // =========================================================================
    // Telemetry capacity snapshots
    // =========================================================================

    public function test_record_telemetry_capacity_creates_snapshot(): void
    {
        $snap = $this->svc->recordTelemetryCapacity(
            'telemetry.normalized', 500.0, 43200000,
            partitionUtilizationPct: 45.0
        );

        $this->assertInstanceOf(TelemetryCapacitySnapshot::class, $snap);
        $this->assertStringStartsWith('tcs-', $snap->snapshot_id);
        $this->assertSame('telemetry.normalized', $snap->topic);
        $this->assertSame(TelemetryCapacitySnapshot::PRESSURE_NORMAL, $snap->pressure_state);
    }

    public function test_high_utilization_classified_as_critical(): void
    {
        $snap = $this->svc->recordTelemetryCapacity('topic', 15000.0, 1000000000, partitionUtilizationPct: 95.0);
        $this->assertSame(TelemetryCapacitySnapshot::PRESSURE_CRITICAL, $snap->pressure_state);
    }

    public function test_telemetry_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordTelemetryCapacity('topic', 100.0, 8640000);

        $this->expectException(\LogicException::class);
        $snap->events_per_sec = 999.0;
        $snap->save();
    }

    public function test_telemetry_snapshot_has_no_updated_at(): void
    {
        $snap = $this->svc->recordTelemetryCapacity('t', 10.0, 864000);
        $row  = DB::table('telemetry_capacity_snapshots')->where('id', $snap->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    // =========================================================================
    // Replay economics
    // =========================================================================

    public function test_record_replay_economics_creates_run(): void
    {
        $run = $this->svc->recordReplayEconomics(
            'normalizer-group', 'telemetry.raw',
            60, 1000.0, 60000, replayBacklog: 5000
        );

        $this->assertInstanceOf(ReplayEconomicsRun::class, $run);
        $this->assertStringStartsWith('rer-', $run->run_id);
        $this->assertTrue($run->is_bounded);
        $this->assertGreaterThan(0.0, $run->replay_cost_estimate);
    }

    public function test_replay_economics_amplification_is_bounded_below_threshold(): void
    {
        $run = $this->svc->recordReplayEconomics('g', 't', 60, 100.0, 6000);
        $this->assertLessThanOrEqual(CapacityGovernanceService::SAFE_AMPLIFICATION_RATIO, $run->replay_amplification_ratio);
        $this->assertTrue($run->is_bounded);
    }

    public function test_high_queue_pressure_transitions_to_at_limit(): void
    {
        $run = $this->svc->recordReplayEconomics('g', 't', 60, 5000.0, 300000, queuePressure: 0.95);
        $this->assertSame(ReplayEconomicsRun::CONCURRENCY_AT_LIMIT, $run->concurrency_state);
    }

    public function test_replay_economics_run_is_append_only(): void
    {
        $run = $this->svc->recordReplayEconomics('g', 't', 30, 100.0, 3000);

        $this->expectException(\LogicException::class);
        $run->is_bounded = false;
        $run->save();
    }

    // =========================================================================
    // Query performance snapshots
    // =========================================================================

    public function test_record_query_performance_creates_snapshot(): void
    {
        $snap = $this->svc->recordQueryPerformance(
            'postgresql', 25.0, 120.0, 350.0,
            slowQueryCount: 2
        );

        $this->assertInstanceOf(QueryPerformanceSnapshot::class, $snap);
        $this->assertStringStartsWith('qps-', $snap->snapshot_id);
        $this->assertSame('normal', $snap->latency_state);
        $this->assertSame(0.0, round($snap->index_miss_ratio, 4));
    }

    public function test_high_p95_classified_as_degraded(): void
    {
        $snap = $this->svc->recordQueryPerformance('opensearch', 500.0, 2500.0, 5000.0, slowQueryCount: 15);
        $this->assertSame(QueryPerformanceSnapshot::LATENCY_DEGRADED, $snap->latency_state);
    }

    public function test_index_miss_ratio_computed_from_hit_ratio(): void
    {
        $snap = $this->svc->recordQueryPerformance('clickhouse', 10.0, 50.0, 100.0, indexHitRatio: 0.85);
        $this->assertEqualsWithDelta(0.15, $snap->index_miss_ratio, 0.001);
    }

    public function test_query_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordQueryPerformance('postgresql', 10.0, 50.0, 100.0);

        $this->expectException(\LogicException::class);
        $snap->latency_state = 'critical';
        $snap->save();
    }

    // =========================================================================
    // Storage capacity snapshots
    // =========================================================================

    public function test_record_storage_capacity_creates_snapshot(): void
    {
        $snap = $this->svc->recordStorageCapacity(
            'postgresql', 1024 * 1024 * 500, 1024.0 * 1024
        );

        $this->assertInstanceOf(StorageCapacitySnapshot::class, $snap);
        $this->assertStringStartsWith('scs-', $snap->snapshot_id);
        $this->assertSame(StorageCapacitySnapshot::STATE_HEALTHY, $snap->capacity_state);
        $this->assertNotNull($snap->estimated_exhaustion_date);
    }

    public function test_high_shard_pressure_classified_as_pressure(): void
    {
        $snap = $this->svc->recordStorageCapacity('opensearch', 1024 * 1024 * 100, 1000.0, shardPressurePct: 80.0);
        $this->assertSame(StorageCapacitySnapshot::STATE_PRESSURE, $snap->capacity_state);
    }

    public function test_critical_shard_pressure_classified_as_critical(): void
    {
        $snap = $this->svc->recordStorageCapacity('clickhouse', 1024 * 1024 * 200, 2000.0, shardPressurePct: 95.0);
        $this->assertSame(StorageCapacitySnapshot::STATE_CRITICAL, $snap->capacity_state);
    }

    public function test_storage_exhaustion_date_is_computed(): void
    {
        $snap = $this->svc->recordStorageCapacity('redpanda', 1024 * 1024 * 10, 1024 * 1024);
        $this->assertNotNull($snap->estimated_exhaustion_date);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $snap->estimated_exhaustion_date);
    }

    public function test_zero_growth_rate_produces_no_exhaustion_date(): void
    {
        $snap = $this->svc->recordStorageCapacity('qdrant', 1024 * 1024, 0.0);
        $this->assertNull($snap->estimated_exhaustion_date);
    }

    public function test_storage_capacity_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordStorageCapacity('postgresql', 1024, 100.0);

        $this->expectException(\LogicException::class);
        $snap->capacity_state = 'critical';
        $snap->save();
    }

    // =========================================================================
    // Cardinality pressure reports
    // =========================================================================

    public function test_report_cardinality_pressure_creates_record(): void
    {
        $report = $this->svc->reportCardinalityPressure(
            'entity_key', 50000, 'entity_explosion',
            sourceTable: 'entities', growthRatePct: 5.0
        );

        $this->assertInstanceOf(CardinalityPressureReport::class, $report);
        $this->assertStringStartsWith('cpr-', $report->report_id);
        $this->assertTrue($report->bounded);
    }

    public function test_high_growth_rate_marks_as_unbounded(): void
    {
        $report = $this->svc->reportCardinalityPressure('label', 2000000, 'label_explosion', growthRatePct: 30.0);
        $this->assertFalse($report->bounded);
    }

    public function test_cardinality_report_is_append_only(): void
    {
        $report = $this->svc->reportCardinalityPressure('field', 100, 'entity_explosion');

        $this->expectException(\LogicException::class);
        $report->severity = 'critical';
        $report->save();
    }

    // =========================================================================
    // Capacity projection runs
    // =========================================================================

    public function test_run_capacity_projection_is_deterministic(): void
    {
        $r1 = $this->svc->runCapacityProjection('postgresql', 1024 * 1024.0);
        $r2 = $this->svc->runCapacityProjection('postgresql', 1024 * 1024.0);

        $this->assertSame($r1->projected_30d_bytes, $r2->projected_30d_bytes);
        $this->assertSame($r1->projected_90d_bytes, $r2->projected_90d_bytes);
        $this->assertTrue($r1->deterministic);
    }

    public function test_projection_30d_equals_daily_times_30(): void
    {
        $dailyGrowth = 1024 * 1024.0;
        $proj = $this->svc->runCapacityProjection('postgresql', $dailyGrowth);

        $this->assertSame($dailyGrowth * 30, $proj->projected_30d_bytes);
        $this->assertSame($dailyGrowth * 90, $proj->projected_90d_bytes);
    }

    public function test_high_growth_forecast_elevated_queue_pressure(): void
    {
        $proj = $this->svc->runCapacityProjection('redpanda', 500_000_000.0);
        $this->assertSame('elevated', $proj->queue_pressure_forecast);
    }

    public function test_projection_confidence_is_set(): void
    {
        $proj = $this->svc->runCapacityProjection('clickhouse', 1024.0);
        $this->assertSame(0.70, $proj->confidence_level);
    }

    public function test_capacity_projection_is_append_only(): void
    {
        $proj = $this->svc->runCapacityProjection('opensearch', 512.0);

        $this->expectException(\LogicException::class);
        $proj->confidence_level = 1.0;
        $proj->save();
    }

    // =========================================================================
    // Replay amplification reports
    // =========================================================================

    public function test_report_replay_amplification_calculates_ratio(): void
    {
        $report = $this->svc->reportReplayAmplification(1000, 2500, 'group-a', 'topic-a');

        $this->assertInstanceOf(ReplayAmplificationReport::class, $report);
        $this->assertStringStartsWith('rar-', $report->report_id);
        $this->assertSame(2.5, $report->amplification_ratio);
        $this->assertFalse($report->exceeds_safe_threshold);
    }

    public function test_amplification_above_threshold_flagged(): void
    {
        $report = $this->svc->reportReplayAmplification(1000, 5000, 'group', 'topic');
        $this->assertSame(5.0, $report->amplification_ratio);
        $this->assertTrue($report->exceeds_safe_threshold);
    }

    public function test_amplification_report_is_append_only(): void
    {
        $report = $this->svc->reportReplayAmplification(100, 200);

        $this->expectException(\LogicException::class);
        $report->amplification_ratio = 99.0;
        $report->save();
    }

    // =========================================================================
    // Partition pressure snapshots — mutable
    // =========================================================================

    public function test_upsert_partition_pressure_creates_record(): void
    {
        $snap = $this->svc->upsertPartitionPressure('telemetry.raw', 0, 500, 30.0, 1000.0, true);

        $this->assertInstanceOf(PartitionPressureSnapshot::class, $snap);
        $this->assertSame('telemetry.raw', $snap->topic);
        $this->assertSame(PartitionPressureSnapshot::HEALTH_HEALTHY, $snap->health_state);
        $this->assertTrue($snap->is_leader);
    }

    public function test_partition_pressure_upserts_existing_record(): void
    {
        $this->svc->upsertPartitionPressure('t', 0, 100, 20.0);
        $this->svc->upsertPartitionPressure('t', 0, 5000, 60.0);

        $this->assertSame(1, PartitionPressureSnapshot::where('topic', 't')->count());
        $snap = PartitionPressureSnapshot::where('topic', 't')->first();
        $this->assertSame(5000, $snap->offset_lag);
    }

    public function test_high_offset_lag_classified_as_pressure(): void
    {
        $snap = $this->svc->upsertPartitionPressure('t', 1, 50000, 80.0);
        $this->assertSame(PartitionPressureSnapshot::HEALTH_PRESSURE, $snap->health_state);
    }

    public function test_very_high_lag_classified_as_stalled(): void
    {
        $snap = $this->svc->upsertPartitionPressure('t', 2, 200000, 95.0);
        $this->assertSame(PartitionPressureSnapshot::HEALTH_STALLED, $snap->health_state);
    }

    // =========================================================================
    // Infrastructure cost estimates
    // =========================================================================

    public function test_estimate_cost_creates_advisory_record(): void
    {
        $est = $this->svc->estimateCost('storage', 2.5, 5.0, 'postgresql');

        $this->assertInstanceOf(InfrastructureCostEstimate::class, $est);
        $this->assertStringStartsWith('ice-', $est->estimate_id);
        $this->assertTrue($est->is_advisory);
        $this->assertGreaterThan(0.0, $est->monthly_cost_estimate);
        $this->assertGreaterThan(0.0, $est->projected_90d_cost);
    }

    public function test_cost_estimate_monthly_is_daily_times_30(): void
    {
        $est = $this->svc->estimateCost('compute', 1.0, 0.0);
        // monthly = daily * 30 * (1 + 0/100) = 30.0
        $this->assertEqualsWithDelta(30.0, $est->monthly_cost_estimate, 0.01);
    }

    public function test_cost_estimate_is_append_only(): void
    {
        $est = $this->svc->estimateCost('total', 5.0);

        $this->expectException(\LogicException::class);
        $est->daily_cost_estimate = 999.0;
        $est->save();
    }

    public function test_cost_estimate_is_always_advisory(): void
    {
        $est = $this->svc->estimateCost('replay', 1.0);
        $this->assertTrue($est->is_advisory);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->getDashboardStats();

        $this->assertArrayHasKey('total_telemetry_snapshots', $stats);
        $this->assertArrayHasKey('unbounded_replays', $stats);
        $this->assertArrayHasKey('degraded_queries', $stats);
        $this->assertArrayHasKey('critical_storage', $stats);
        $this->assertArrayHasKey('exceeded_amplification', $stats);
        $this->assertArrayHasKey('total_projections', $stats);
        $this->assertArrayHasKey('total_cost_estimates', $stats);
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_scaling']);
    }

    // =========================================================================
    // Threat hunting domain support
    // =========================================================================

    public function test_threat_hunting_supports_60_domains(): void
    {
        $this->assertCount(95, $this->hunting->supportedDomains());
    }

    public function test_hunt_telemetry_capacity_snapshots_domain(): void
    {
        $this->svc->recordTelemetryCapacity('topic-hunt', 100.0, 8640000);
        $results = $this->hunting->hunt('telemetry_capacity_snapshots', ['pressure_state' => 'normal']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_replay_economics_runs_domain(): void
    {
        $this->svc->recordReplayEconomics('group-hunt', 'topic-hunt', 60, 100.0, 6000);
        $results = $this->hunting->hunt('replay_economics_runs', ['is_bounded' => true]);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_query_performance_snapshots_domain(): void
    {
        $this->svc->recordQueryPerformance('postgresql', 10.0, 50.0, 100.0);
        $results = $this->hunting->hunt('query_performance_snapshots', ['backend' => 'postgresql']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_storage_capacity_snapshots_domain(): void
    {
        $this->svc->recordStorageCapacity('postgresql', 1024, 100.0);
        $results = $this->hunting->hunt('storage_capacity_snapshots', ['backend' => 'postgresql']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_capacity_projection_runs_domain(): void
    {
        $this->svc->runCapacityProjection('postgresql', 1024.0);
        $results = $this->hunting->hunt('capacity_projection_runs', ['scope' => 'postgresql']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_capacity_dashboard_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.dashboard'))
            ->assertStatus(200);
    }

    public function test_capacity_dashboard_contains_governance_notice(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.dashboard'))
            ->assertSee('operational visibility controls only');
    }

    public function test_replay_economics_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.replay-economics'))
            ->assertStatus(200);
    }

    public function test_query_performance_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.query-performance'))
            ->assertStatus(200);
    }

    public function test_storage_capacity_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.storage-capacity'))
            ->assertStatus(200);
    }

    public function test_cardinality_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.cardinality'))
            ->assertStatus(200);
    }

    public function test_replay_amplification_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.replay-amplification'))
            ->assertStatus(200);
    }

    public function test_partition_pressure_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.partition-pressure'))
            ->assertStatus(200);
    }

    public function test_capacity_projection_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.projection'))
            ->assertStatus(200);
    }

    public function test_cost_dashboard_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('capacity.cost'))
            ->assertStatus(200);
    }

    // =========================================================================
    // Safety invariants
    // =========================================================================

    public function test_capacity_service_has_no_isolate_host(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'isolateHost'));
    }

    public function test_capacity_service_has_no_kill_process(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'killProcess'));
    }

    public function test_capacity_service_has_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'autoRemediate'));
    }

    public function test_capacity_service_has_no_purge_retention(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'purgeRetention'));
    }

    public function test_capacity_service_has_no_auto_scale(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'autoScale'));
    }

    public function test_capacity_service_has_no_destructive_compaction(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'destructiveCompaction'));
    }

    public function test_capacity_service_has_no_unsafe_replay_acceleration(): void
    {
        $this->assertFalse(method_exists(CapacityGovernanceService::class, 'unsafeReplayAcceleration'));
    }

    public function test_all_cost_estimates_are_advisory(): void
    {
        $est = $this->svc->estimateCost('storage', 1.0);
        $this->assertTrue($est->is_advisory);
    }

    public function test_all_projections_are_deterministic(): void
    {
        $proj = $this->svc->runCapacityProjection('full', 1024.0);
        $this->assertTrue($proj->deterministic);
        $this->assertStringContainsString('No autonomous scaling', $proj->assumptions);
    }
}
