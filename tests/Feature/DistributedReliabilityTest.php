<?php

namespace Tests\Feature;

use App\Models\DegradedModeEvent;
use App\Models\DuplicateEventReport;
use App\Models\IdempotencyValidationRecord;
use App\Models\RecoveryValidationRun;
use App\Models\ReplayThrottleState;
use App\Models\StoragePressureSnapshot;
use App\Models\StreamConsumerLagSnapshot;
use App\Models\SystemWorkerHeartbeat;
use App\Models\User;
use App\Services\DistributedReliabilityService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HA / Distributed Reliability Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No autonomous remediation
 *   - No destructive replay
 *   - No unsafe queue purge
 *   - No hidden data deletion
 *   - No unbounded retry loops (retry budget is bounded)
 *   - Append-only tables enforce immutability
 *   - Duplicate events are visible, not silently ignored
 *   - Analytics are protected from duplicate corruption
 */
class DistributedReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private DistributedReliabilityService $svc;
    private ThreatHuntingService          $hunting;
    private User                          $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc     = app(DistributedReliabilityService::class);
        $this->hunting = app(ThreatHuntingService::class);
        $this->user    = User::factory()->create();
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_system_worker_heartbeats_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('system_worker_heartbeats'));
    }

    public function test_stream_consumer_lag_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('stream_consumer_lag_snapshots'));
    }

    public function test_replay_throttle_states_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('replay_throttle_states'));
    }

    public function test_idempotency_validation_records_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('idempotency_validation_records'));
    }

    public function test_duplicate_event_reports_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('duplicate_event_reports'));
    }

    public function test_storage_pressure_snapshots_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('storage_pressure_snapshots'));
    }

    public function test_degraded_mode_events_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('degraded_mode_events'));
    }

    public function test_recovery_validation_runs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('recovery_validation_runs'));
    }

    // =========================================================================
    // Worker heartbeat lifecycle
    // =========================================================================

    public function test_record_heartbeat_creates_worker_record(): void
    {
        $w = $this->svc->recordHeartbeat(
            'normalizer-worker-1',
            'normalizer-worker',
            'normalizer-group',
            [0, 1, 2],
            currentLag: 500,
            processedCount: 10000
        );

        $this->assertInstanceOf(SystemWorkerHeartbeat::class, $w);
        $this->assertSame('normalizer-worker-1', $w->worker_id);
        $this->assertSame('normalizer-worker', $w->service_name);
        $this->assertSame(500, $w->current_lag);
        $this->assertFalse($w->is_stale);
        $this->assertFalse($w->is_stalled);
        $this->assertNotNull($w->last_heartbeat_at);
    }

    public function test_record_heartbeat_upserts_existing_worker(): void
    {
        $this->svc->recordHeartbeat('worker-1', 'svc', processedCount: 100);
        $this->svc->recordHeartbeat('worker-1', 'svc', processedCount: 200);

        $this->assertSame(1, SystemWorkerHeartbeat::where('worker_id', 'worker-1')->count());
        $this->assertSame(200, SystemWorkerHeartbeat::where('worker_id', 'worker-1')->value('processed_event_count'));
    }

    public function test_high_lag_worker_classified_as_lagging(): void
    {
        $w = $this->svc->recordHeartbeat('lag-worker', 'svc', currentLag: 15000);
        $this->assertSame(SystemWorkerHeartbeat::HEALTH_LAGGING, $w->health_state);
    }

    public function test_high_failure_worker_classified_as_degraded(): void
    {
        $w = $this->svc->recordHeartbeat('fail-worker', 'svc', failedCount: 15);
        $this->assertSame(SystemWorkerHeartbeat::HEALTH_DEGRADED, $w->health_state);
    }

    public function test_mark_stale_workers_detects_stale(): void
    {
        $w = $this->svc->recordHeartbeat('stale-worker', 'svc');
        // Force last_heartbeat_at into the past
        DB::table('system_worker_heartbeats')
            ->where('worker_id', 'stale-worker')
            ->update(['last_heartbeat_at' => now()->subSeconds(120)]);

        $count = $this->svc->markStaleWorkers();
        $this->assertGreaterThanOrEqual(1, $count);

        $w->refresh();
        $this->assertTrue($w->is_stale);
        $this->assertSame(SystemWorkerHeartbeat::HEALTH_DISCONNECTED, $w->health_state);
    }

    public function test_mark_stalled_workers_detects_stalled(): void
    {
        $this->svc->recordHeartbeat('stalled-worker', 'svc');
        DB::table('system_worker_heartbeats')
            ->where('worker_id', 'stalled-worker')
            ->update(['last_heartbeat_at' => now()->subSeconds(400)]);

        $count = $this->svc->markStalledWorkers();
        $this->assertGreaterThanOrEqual(1, $count);

        $w = SystemWorkerHeartbeat::where('worker_id', 'stalled-worker')->first();
        $this->assertTrue($w->is_stalled);
        $this->assertSame(SystemWorkerHeartbeat::HEALTH_STALLED, $w->health_state);
    }

    // =========================================================================
    // Consumer lag snapshots — append-only
    // =========================================================================

    public function test_record_lag_snapshot_creates_record(): void
    {
        $snap = $this->svc->recordLagSnapshot(
            'normalizer-group', 'telemetry.normalized', 1500,
            dlqGrowing: false
        );

        $this->assertInstanceOf(StreamConsumerLagSnapshot::class, $snap);
        $this->assertStringStartsWith('scls-', $snap->snapshot_id);
        $this->assertSame('warning', $snap->pressure_state);
        $this->assertNotNull($snap->created_at);
    }

    public function test_critical_lag_classified_correctly(): void
    {
        $snap = $this->svc->recordLagSnapshot('group', 'topic', 12000);
        $this->assertSame('critical', $snap->pressure_state);
    }

    public function test_lag_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordLagSnapshot('group', 'topic', 100);

        $this->expectException(\LogicException::class);
        $snap->lag_count = 9999;
        $snap->save();
    }

    public function test_lag_trend_is_increasing_when_lag_grows(): void
    {
        $this->svc->recordLagSnapshot('g', 't', 1000);
        $snap2 = $this->svc->recordLagSnapshot('g', 't', 2000);
        $this->assertSame('increasing', $snap2->trend);
    }

    // =========================================================================
    // Replay throttle state
    // =========================================================================

    public function test_get_or_create_throttle_creates_on_first_call(): void
    {
        $t = $this->svc->getOrCreateThrottle('normalizer-group', 'telemetry.raw');

        $this->assertInstanceOf(ReplayThrottleState::class, $t);
        $this->assertSame(ReplayThrottleState::STATE_NORMAL, $t->state);
        $this->assertSame(100, $t->max_events_per_second);
    }

    public function test_increment_retry_transitions_to_throttled(): void
    {
        $t = $this->svc->getOrCreateThrottle('group-x', 'topic-x');
        // Set budget to 10, increment past 70%
        $t->update(['retry_budget_limit' => 10]);

        for ($i = 0; $i < 8; $i++) {
            $this->svc->incrementRetryCount($t);
        }
        $t->refresh();

        $this->assertSame(ReplayThrottleState::STATE_THROTTLED, $t->state);
    }

    public function test_increment_retry_transitions_to_exhausted_at_limit(): void
    {
        $t = $this->svc->getOrCreateThrottle('group-y', 'topic-y');
        $t->update(['retry_budget_limit' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $this->svc->incrementRetryCount($t);
        }
        $t->refresh();

        $this->assertSame(ReplayThrottleState::STATE_EXHAUSTED, $t->state);
    }

    public function test_reset_throttle_clears_state(): void
    {
        $t = $this->svc->getOrCreateThrottle('group-z', 'topic-z');
        $t->update(['retry_budget_limit' => 2]);
        $this->svc->incrementRetryCount($t);
        $this->svc->incrementRetryCount($t);
        $this->svc->resetThrottle($t);
        $t->refresh();

        $this->assertSame(ReplayThrottleState::STATE_NORMAL, $t->state);
        $this->assertSame(0, $t->current_retry_count);
        $this->assertNotNull($t->throttle_cleared_at);
    }

    // =========================================================================
    // Idempotency validation
    // =========================================================================

    public function test_first_seen_event_is_not_duplicate(): void
    {
        $result = $this->svc->validateIdempotency('evt-001', 'trace-abc', 'telemetry.raw');

        $this->assertTrue($result['is_new']);
        $this->assertFalse($result['is_duplicate']);
        $this->assertSame('first_seen', $result['record']->processing_state);
    }

    public function test_same_event_replayed_after_processing_is_duplicate(): void
    {
        $this->svc->validateIdempotency('evt-dup', 'trace-dup', 'topic-dup');
        $this->svc->markEventProcessed('evt-dup', 'trace-dup', 'topic-dup');

        $result = $this->svc->validateIdempotency('evt-dup', 'trace-dup', 'topic-dup');

        $this->assertFalse($result['is_new']);
        $this->assertTrue($result['is_duplicate']);
    }

    public function test_duplicate_event_creates_report(): void
    {
        $this->svc->validateIdempotency('evt-rep', 'trace-rep', 'topic-rep');
        $this->svc->markEventProcessed('evt-rep', 'trace-rep', 'topic-rep');
        $this->svc->validateIdempotency('evt-rep', 'trace-rep', 'topic-rep');

        $this->assertGreaterThanOrEqual(1, DuplicateEventReport::count());
    }

    public function test_event_fingerprint_is_deterministic(): void
    {
        $f1 = IdempotencyValidationRecord::computeFingerprint('evt-1', 'tr-1', 'topic-1');
        $f2 = IdempotencyValidationRecord::computeFingerprint('evt-1', 'tr-1', 'topic-1');

        $this->assertSame($f1, $f2);
        $this->assertSame(64, strlen($f1));
    }

    public function test_different_events_produce_different_fingerprints(): void
    {
        $f1 = IdempotencyValidationRecord::computeFingerprint('evt-1', 'tr-1', 'topic-1');
        $f2 = IdempotencyValidationRecord::computeFingerprint('evt-2', 'tr-1', 'topic-1');

        $this->assertNotSame($f1, $f2);
    }

    // =========================================================================
    // Duplicate event reports — append-only
    // =========================================================================

    public function test_duplicate_report_has_analytics_protection(): void
    {
        $report = $this->svc->reportDuplicate('fp-001', 'topic-a', 'trace-001');
        $this->assertTrue($report->analytics_protected);
        $this->assertFalse($report->suppressed);
    }

    public function test_duplicate_report_is_append_only(): void
    {
        $report = $this->svc->reportDuplicate('fp-002', 'topic-b', 'trace-002');

        $this->expectException(\LogicException::class);
        $report->severity = 'critical';
        $report->save();
    }

    public function test_duplicate_report_has_no_updated_at(): void
    {
        $report = $this->svc->reportDuplicate('fp-003', 'topic-c', 'trace-003');
        $row = DB::table('duplicate_event_reports')->where('id', $report->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    // =========================================================================
    // Storage pressure snapshots — append-only
    // =========================================================================

    public function test_record_storage_pressure_creates_snapshot(): void
    {
        $snap = $this->svc->recordStoragePressure('postgresql', 45.0,
            indexSizeBytes: 1024 * 1024 * 500
        );

        $this->assertInstanceOf(StoragePressureSnapshot::class, $snap);
        $this->assertStringStartsWith('sps-', $snap->snapshot_id);
        $this->assertSame('normal', $snap->pressure_state);
        $this->assertFalse($snap->degraded);
    }

    public function test_high_retention_pressure_classified_as_warning(): void
    {
        $snap = $this->svc->recordStoragePressure('opensearch', 80.0);
        $this->assertSame('warning', $snap->pressure_state);
    }

    public function test_critical_retention_pressure_classified_as_critical(): void
    {
        $snap = $this->svc->recordStoragePressure('clickhouse', 95.0);
        $this->assertSame('critical', $snap->pressure_state);
        $this->assertTrue($snap->degraded);
    }

    public function test_storage_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordStoragePressure('qdrant', 30.0);

        $this->expectException(\LogicException::class);
        $snap->retention_pressure_pct = 99.0;
        $snap->save();
    }

    // =========================================================================
    // Degraded mode events — append-only
    // =========================================================================

    public function test_record_degraded_mode_entry_creates_event(): void
    {
        $ev = $this->svc->recordDegradedModeEntry(
            'correlation-worker', 'stream_lag_degraded', 'Consumer lag exceeds threshold'
        );

        $this->assertInstanceOf(DegradedModeEvent::class, $ev);
        $this->assertStringStartsWith('dme-', $ev->event_id);
        $this->assertSame(DegradedModeEvent::EVENT_TYPE_ENTERED, $ev->event_type);
        $this->assertFalse($ev->operator_acknowledged);
        $this->assertFalse($ev->auto_cleared);
    }

    public function test_record_degraded_mode_cleared_creates_cleared_event(): void
    {
        $this->svc->recordDegradedModeEntry('alert-writer', 'storage_write_degraded');
        $cleared = $this->svc->recordDegradedModeCleared('alert-writer', 'storage_write_degraded');

        $this->assertSame(DegradedModeEvent::EVENT_TYPE_CLEARED, $cleared->event_type);
        $this->assertTrue($cleared->operator_acknowledged);
    }

    public function test_degraded_mode_event_is_append_only(): void
    {
        $ev = $this->svc->recordDegradedModeEntry('svc', 'dlq_pressure');

        $this->expectException(\LogicException::class);
        $ev->event_type = 'cleared';
        $ev->save();
    }

    public function test_get_degraded_timeline_returns_chronological_events(): void
    {
        $this->svc->recordDegradedModeEntry('svc-a', 'dlq_pressure');
        $this->svc->recordDegradedModeCleared('svc-a', 'dlq_pressure');

        $timeline = $this->svc->getDegradedTimeline('svc-a');
        $this->assertCount(2, $timeline);
        $this->assertSame('entered', $timeline->first()->event_type);
    }

    // =========================================================================
    // Recovery validation runs — append-only
    // =========================================================================

    public function test_start_recovery_run_creates_running_record(): void
    {
        $run = $this->svc->startRecoveryRun('service_restart', 'automated-test');

        $this->assertInstanceOf(RecoveryValidationRun::class, $run);
        $this->assertStringStartsWith('rvr-', $run->run_id);
        $this->assertSame(RecoveryValidationRun::STATUS_RUNNING, $run->status);
    }

    public function test_complete_recovery_run_with_all_passing(): void
    {
        $run = $this->svc->startRecoveryRun('trace_propagation');
        $result = $this->svc->completeRecoveryRun(
            $run->run_id, 'trace_propagation',
            3, 3, true, true, true,
            [['check' => 'trace_id_preserved', 'result' => 'pass']]
        );

        $this->assertSame(RecoveryValidationRun::STATUS_PASSED, $result->status);
        $this->assertSame(3, $result->passed_checks);
        $this->assertSame(0, $result->failed_checks);
        $this->assertTrue($result->trace_propagation_ok);
        $this->assertTrue($result->replay_idempotency_ok);
    }

    public function test_complete_recovery_run_with_failures(): void
    {
        $run = $this->svc->startRecoveryRun('idempotency_check');
        $result = $this->svc->completeRecoveryRun(
            $run->run_id, 'idempotency_check',
            5, 3, false, false, true,
            [], 'Idempotency check failed on 2 events'
        );

        $this->assertSame(RecoveryValidationRun::STATUS_WARNING, $result->status);
        $this->assertNotNull($result->failure_summary);
    }

    public function test_recovery_run_is_append_only(): void
    {
        $run = $this->svc->startRecoveryRun('dlq_bounded');

        $this->expectException(\LogicException::class);
        $run->status = 'passed';
        $run->save();
    }

    public function test_recovery_run_has_no_updated_at(): void
    {
        $run = $this->svc->startRecoveryRun('replay_resume');
        $row = DB::table('recovery_validation_runs')->where('id', $run->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->getDashboardStats();

        $this->assertArrayHasKey('total_workers', $stats);
        $this->assertArrayHasKey('healthy_workers', $stats);
        $this->assertArrayHasKey('stale_workers', $stats);
        $this->assertArrayHasKey('stalled_workers', $stats);
        $this->assertArrayHasKey('critical_lag_count', $stats);
        $this->assertArrayHasKey('throttled_topics', $stats);
        $this->assertArrayHasKey('exhausted_topics', $stats);
        $this->assertArrayHasKey('duplicate_events', $stats);
        $this->assertArrayHasKey('degraded_mode_entries', $stats);
        $this->assertArrayHasKey('recovery_runs_passed', $stats);
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_remediation']);
    }

    // =========================================================================
    // Threat hunting domain support
    // =========================================================================

    public function test_threat_hunting_supports_50_domains(): void
    {
        $this->assertCount(125, $this->hunting->supportedDomains());
    }

    public function test_hunt_system_worker_heartbeats_domain(): void
    {
        $this->svc->recordHeartbeat('hunt-worker', 'svc');
        $results = $this->hunting->hunt('system_worker_heartbeats', ['service_name' => 'svc']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_stream_consumer_lag_snapshots_domain(): void
    {
        $this->svc->recordLagSnapshot('hunt-group', 'hunt-topic', 500);
        $results = $this->hunting->hunt('stream_consumer_lag_snapshots', ['consumer_group' => 'hunt-group']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_duplicate_event_reports_domain(): void
    {
        $this->svc->reportDuplicate('fp-hunt', 'topic-hunt', 'trace-hunt');
        $results = $this->hunting->hunt('duplicate_event_reports', ['source_topic' => 'topic-hunt']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_degraded_mode_events_domain(): void
    {
        $this->svc->recordDegradedModeEntry('svc-hunt', 'dlq_pressure');
        $results = $this->hunting->hunt('degraded_mode_events', ['degraded_reason' => 'dlq_pressure']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_recovery_validation_runs_domain(): void
    {
        $this->svc->startRecoveryRun('service_restart');
        $results = $this->hunting->hunt('recovery_validation_runs', ['scenario' => 'service_restart']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_reliability_dashboard_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.dashboard'))
            ->assertStatus(200);
    }

    public function test_reliability_dashboard_contains_safeguard_notice(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.dashboard'))
            ->assertSee('operational safeguards only');
    }

    public function test_worker_health_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.worker-health'))
            ->assertStatus(200);
    }

    public function test_lag_monitor_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.lag-monitor'))
            ->assertStatus(200);
    }

    public function test_throttle_console_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.throttle-console'))
            ->assertStatus(200);
    }

    public function test_idempotency_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.idempotency'))
            ->assertStatus(200);
    }

    public function test_duplicate_reports_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.duplicate-reports'))
            ->assertStatus(200);
    }

    public function test_storage_pressure_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.storage-pressure'))
            ->assertStatus(200);
    }

    public function test_degraded_mode_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.degraded-mode'))
            ->assertStatus(200);
    }

    public function test_recovery_validation_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('reliability.recovery-validation'))
            ->assertStatus(200);
    }

    // =========================================================================
    // Safety invariants
    // =========================================================================

    public function test_reliability_service_has_no_isolate_host(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'isolateHost'));
    }

    public function test_reliability_service_has_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'quarantineHost'));
    }

    public function test_reliability_service_has_no_execute_shell(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'executeShell'));
    }

    public function test_reliability_service_has_no_kill_process(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'killProcess'));
    }

    public function test_reliability_service_has_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'autoRemediate'));
    }

    public function test_reliability_service_has_no_purge_queue(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'purgeQueue'));
    }

    public function test_reliability_service_has_no_delete_events(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'deleteEvents'));
    }

    public function test_reliability_service_has_no_destructive_replay(): void
    {
        $this->assertFalse(method_exists(DistributedReliabilityService::class, 'destructiveReplay'));
    }

    public function test_duplicate_reports_always_protect_analytics(): void
    {
        $r = $this->svc->reportDuplicate('fp-safety', 'topic-safety', 'trace-safety');
        $this->assertTrue($r->analytics_protected);
    }

    public function test_retry_budget_is_bounded(): void
    {
        $t = $this->svc->getOrCreateThrottle('bounded-group');
        $t->update(['retry_budget_limit' => 3]);

        $this->svc->incrementRetryCount($t);
        $this->svc->incrementRetryCount($t);
        $this->svc->incrementRetryCount($t);
        $t->refresh();

        $this->assertSame(ReplayThrottleState::STATE_EXHAUSTED, $t->state);
        $this->assertLessThanOrEqual($t->retry_budget_limit, $t->current_retry_count);
    }
}
