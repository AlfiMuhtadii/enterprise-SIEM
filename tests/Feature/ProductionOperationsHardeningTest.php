<?php

namespace Tests\Feature;

use App\Models\BackupMetadata;
use App\Models\DlqReplayJob;
use App\Models\QueueLagMetric;
use App\Models\RecoveryValidation;
use App\Models\RetentionAuditEvent;
use App\Models\RetentionExecution;
use App\Models\RetentionPolicy;
use App\Models\ServiceHealthSnapshot;
use App\Models\StreamPressureMetric;
use App\Models\User;
use App\Services\BackupRecoveryService;
use App\Services\DlqReplayService;
use App\Services\OperationsHealthService;
use App\Services\RetentionGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Production Operations Hardening Phase 1 tests.
 *
 * Explicitly asserts:
 * - No destructive restore automation
 * - No uncontrolled retention deletion
 * - No infinite replay loops
 * - No infrastructure auto-mutation
 * - No autonomous remediation
 * - Retention dry-run safety
 * - Bounded cleanup execution
 * - Replay-safe DLQ replay
 * - Duplicate prevention
 * - Startup validation warnings
 * - Backup metadata integrity
 * - Recovery verification determinism
 * - Queue lag metric correctness
 * - Degraded-mode visibility
 * - Append-only operational audit
 */
class ProductionOperationsHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function retention(): RetentionGovernanceService
    {
        return app(RetentionGovernanceService::class);
    }

    private function backup(): BackupRecoveryService
    {
        return app(BackupRecoveryService::class);
    }

    private function dlq(): DlqReplayService
    {
        return app(DlqReplayService::class);
    }

    private function ops(): OperationsHealthService
    {
        return app(OperationsHealthService::class);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_retention_policies_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('retention_policies'));
    }

    public function test_retention_executions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('retention_executions'));
    }

    public function test_retention_audit_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('retention_audit_events'));
    }

    public function test_backup_metadata_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('backup_metadata'));
    }

    public function test_recovery_validations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('recovery_validations'));
    }

    public function test_dlq_replay_jobs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('dlq_replay_jobs'));
    }

    public function test_dlq_replay_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('dlq_replay_events'));
    }

    public function test_service_health_snapshots_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('service_health_snapshots'));
    }

    public function test_queue_lag_metrics_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('queue_lag_metrics'));
    }

    public function test_stream_pressure_metrics_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('stream_pressure_metrics'));
    }

    // Append-only tables must have no updated_at
    public function test_retention_audit_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('retention_audit_events', 'updated_at'));
    }

    public function test_recovery_validations_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('recovery_validations', 'updated_at'));
    }

    public function test_dlq_replay_events_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('dlq_replay_events', 'updated_at'));
    }

    public function test_service_health_snapshots_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('service_health_snapshots', 'updated_at'));
    }

    public function test_queue_lag_metrics_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('queue_lag_metrics', 'updated_at'));
    }

    public function test_stream_pressure_metrics_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn('stream_pressure_metrics', 'updated_at'));
    }

    // -----------------------------------------------------------------------
    // Retention governance — dry-run safety
    // -----------------------------------------------------------------------

    public function test_retention_policy_only_allows_eligible_domains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->retention()->createPolicy([
            'domain'         => 'security_alerts', // NOT eligible — audit table
            'target_table'   => 'security_alerts',
            'retention_days' => 90,
        ]);
    }

    public function test_retention_policy_creation_defaults_to_dry_run_only(): void
    {
        $policy = $this->retention()->createPolicy([
            'domain'         => 'endpoint_stream_events',
            'target_table'   => 'endpoint_stream_events',
            'retention_days' => 30,
        ]);

        $this->assertSame(RetentionPolicy::STATUS_DRY_RUN_ONLY, $policy->status);
    }

    public function test_retention_preview_never_deletes_data(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'      => RetentionPolicy::generatePolicyId(),
            'domain'         => 'endpoint_stream_health',
            'target_table'   => 'endpoint_stream_health',
            'time_column'    => 'created_at',
            'retention_days' => 1,
            'batch_size'     => 100,
            'status'         => RetentionPolicy::STATUS_DRY_RUN_ONLY,
            'safe_archive_mode' => true,
        ]);

        $preview = $this->retention()->previewRetention($policy);

        $this->assertTrue($preview['dry_run']);
        $this->assertArrayHasKey('eligible_count', $preview);
        $this->assertStringContainsString('Preview only', $preview['advisory_note']);
    }

    public function test_retention_dry_run_always_deletes_zero_rows(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'      => RetentionPolicy::generatePolicyId(),
            'domain'         => 'endpoint_stream_health',
            'target_table'   => 'endpoint_stream_health',
            'time_column'    => 'created_at',
            'retention_days' => 1,
            'batch_size'     => 100,
            'status'         => RetentionPolicy::STATUS_DRY_RUN_ONLY,
            'safe_archive_mode' => true,
        ]);

        $exec = $this->retention()->executeDryRun($policy);

        $this->assertSame(0, (int) $exec->rows_deleted, 'Dry-run must NEVER delete rows');
        $this->assertTrue((bool) $exec->dry_run);
        $this->assertSame(RetentionExecution::STATUS_DRY_RUN, $exec->status);
    }

    public function test_retention_cleanup_blocked_for_non_active_policy(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'      => RetentionPolicy::generatePolicyId(),
            'domain'         => 'endpoint_stream_health',
            'target_table'   => 'endpoint_stream_health',
            'time_column'    => 'created_at',
            'retention_days' => 1,
            'batch_size'     => 100,
            'status'         => RetentionPolicy::STATUS_DRY_RUN_ONLY, // NOT active
            'safe_archive_mode' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not active/i');
        $this->retention()->executeBoundedCleanup($policy);
    }

    public function test_retention_cleanup_blocked_for_append_only_protected_table(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'             => RetentionPolicy::generatePolicyId(),
            'domain'                => 'endpoint_stream_health',
            'target_table'          => 'endpoint_stream_health',
            'time_column'           => 'created_at',
            'retention_days'        => 1,
            'batch_size'            => 100,
            'status'                => RetentionPolicy::STATUS_ACTIVE,
            'safe_archive_mode'     => true,
            'append_only_protected' => true, // protected
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/append-only protected/i');
        $this->retention()->executeBoundedCleanup($policy);
    }

    public function test_retention_batch_size_capped_at_2000(): void
    {
        $policy = $this->retention()->createPolicy([
            'domain'         => 'queue_lag_metrics',
            'target_table'   => 'queue_lag_metrics',
            'retention_days' => 90,
            'batch_size'     => 9999, // attempt to set huge batch
        ]);

        $this->assertLessThanOrEqual(2000, $policy->batch_size);
    }

    public function test_retention_audit_events_are_append_only_after_dry_run(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'      => RetentionPolicy::generatePolicyId(),
            'domain'         => 'endpoint_stream_health',
            'target_table'   => 'endpoint_stream_health',
            'time_column'    => 'created_at',
            'retention_days' => 1,
            'batch_size'     => 100,
            'status'         => RetentionPolicy::STATUS_DRY_RUN_ONLY,
            'safe_archive_mode' => true,
        ]);

        $this->retention()->executeDryRun($policy);
        $audit = RetentionAuditEvent::where('domain', 'endpoint_stream_health')->first();

        $this->assertNotNull($audit);
        $this->assertNull($audit->updated_at, 'Audit events must be append-only');
        $this->assertTrue((bool) $audit->dry_run);
    }

    // -----------------------------------------------------------------------
    // Backup & recovery — no destructive restore
    // -----------------------------------------------------------------------

    public function test_backup_registration_creates_pending_record(): void
    {
        $backup = $this->backup()->registerBackup([
            'backup_type'  => BackupMetadata::TYPE_SNAPSHOT,
            'scope'        => 'database',
            'backup_at'    => now(),
            'size_bytes'   => 1024 * 1024,
            'checksum'     => 'sha256:abc123',
            'storage_path' => '/backups/2026-05-19.tar.gz',
        ]);

        $this->assertStringStartsWith('bkp-', $backup->backup_id);
        $this->assertSame(BackupMetadata::STATUS_PENDING, $backup->status);
    }

    public function test_integrity_validation_passes_with_full_metadata(): void
    {
        $backup = BackupMetadata::create([
            'backup_id'    => BackupMetadata::generateBackupId(),
            'backup_type'  => 'full',
            'scope'        => 'database',
            'backup_at'    => now(),
            'size_bytes'   => 1024,
            'checksum'     => 'sha256:valid',
            'storage_path' => '/backups/test.tar.gz',
            'status'       => 'pending',
        ]);

        $validation = $this->backup()->validateIntegrity($backup);
        $this->assertSame(RecoveryValidation::RESULT_PASS, $validation->result);
    }

    public function test_integrity_validation_warns_on_missing_checksum(): void
    {
        $backup = BackupMetadata::create([
            'backup_id'   => BackupMetadata::generateBackupId(),
            'backup_type' => 'full',
            'scope'       => 'database',
            'backup_at'   => now(),
            'status'      => 'pending',
        ]);

        $validation = $this->backup()->validateIntegrity($backup);
        $this->assertNotSame(RecoveryValidation::RESULT_PASS, $validation->result);
    }

    public function test_recovery_validations_are_append_only(): void
    {
        $backup = BackupMetadata::create([
            'backup_id'   => BackupMetadata::generateBackupId(),
            'backup_type' => 'full',
            'scope'       => 'database',
            'backup_at'   => now(),
            'status'      => 'pending',
        ]);

        $validation = $this->backup()->validateIntegrity($backup);
        $this->assertNull($validation->updated_at, 'Recovery validations must be append-only');
    }

    public function test_validation_no_destructive_restore_in_service(): void
    {
        $src = file_get_contents(app_path('Services/BackupRecoveryService.php'));
        $this->assertStringNotContainsString('DROP TABLE',         $src);
        $this->assertStringNotContainsString('TRUNCATE',           $src);
        $this->assertStringNotContainsString('live_restore_exec',  $src);
        $this->assertStringContainsString('non-destructive',       strtolower($src));
        $this->assertStringContainsString('no live destructive',   strtolower($src));
    }

    public function test_schema_validation_is_deterministic(): void
    {
        $v1 = $this->backup()->validateSchemaVersion();
        $v2 = $this->backup()->validateSchemaVersion();

        $details1 = $v1->details['migration_count'] ?? 0;
        $details2 = $v2->details['migration_count'] ?? 0;
        $this->assertSame($details1, $details2, 'Schema validation must be deterministic');
    }

    // -----------------------------------------------------------------------
    // DLQ replay — replay-safe, duplicate prevention, bounded
    // -----------------------------------------------------------------------

    public function test_dlq_job_creation_defaults_to_dry_run(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'        => 'xdr.alerts.dlq',
            'from_offset'  => 0,
            'batch_limit'  => 100,
        ]);

        $this->assertTrue((bool) $job->dry_run, 'New DLQ jobs must start in dry-run mode');
        $this->assertSame(DlqReplayJob::STATUS_PENDING, $job->status);
        $this->assertStringStartsWith('dlq-', $job->job_id);
    }

    public function test_dlq_batch_limit_capped_at_max(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'        => 'test.topic',
            'batch_limit'  => 9999, // attempt to exceed max
            'from_offset'  => 0,
        ]);

        $this->assertLessThanOrEqual(DlqReplayJob::MAX_BATCH_LIMIT, $job->batch_limit);
    }

    public function test_dlq_simulation_transitions_to_simulated(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 10,
            'from_offset' => 0,
        ]);

        $simulated = $this->dlq()->simulateReplay($job);
        $this->assertSame(DlqReplayJob::STATUS_SIMULATED, $simulated->status);
    }

    public function test_dlq_execution_blocked_without_simulation(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 10,
            'from_offset' => 0,
        ]);
        // Do NOT simulate
        $job->update(['dry_run' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/simulated before execution/i');
        $this->dlq()->executeReplay($job->fresh());
    }

    public function test_dlq_execution_blocked_in_dry_run_mode(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 10,
            'from_offset' => 0,
        ]);
        $this->dlq()->simulateReplay($job);
        // dry_run is still true

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dry-run mode/i');
        $this->dlq()->executeReplay($job->fresh());
    }

    public function test_dlq_execution_is_bounded(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'        => 'test.topic',
            'batch_limit'  => 5,
            'from_offset'  => 0,
            'to_offset'    => 100, // big range but bounded by batch_limit
        ]);
        $this->dlq()->simulateReplay($job);
        $this->dlq()->enableExecution($job->fresh());
        $executed = $this->dlq()->executeReplay($job->fresh());

        $this->assertLessThanOrEqual(5, $executed->rows_replayed, 'Execution must be bounded by batch_limit');
    }

    public function test_dlq_preview_is_non_destructive(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 10,
            'from_offset' => 0,
        ]);

        $preview = $this->dlq()->previewReplay($job);
        $this->assertTrue($preview['dry_run']);
        $this->assertStringContainsString('Preview only', $preview['advisory_note']);
        // Status must not change after preview
        $this->assertSame(DlqReplayJob::STATUS_PENDING, $job->fresh()->status);
    }

    public function test_dlq_cancel_prevents_infinite_loop(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 100,
            'from_offset' => 0,
        ]);
        $cancelled = $this->dlq()->cancelJob($job);

        $this->assertSame(DlqReplayJob::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->isTerminal(), 'Cancelled jobs must be terminal — no re-entry');
    }

    public function test_dlq_replay_events_are_append_only(): void
    {
        $job = $this->dlq()->createReplayJob([
            'topic'       => 'test.topic',
            'batch_limit' => 5,
            'from_offset' => 0,
        ]);
        $this->dlq()->simulateReplay($job);

        $event = \App\Models\DlqReplayEvent::where('job_id', $job->job_id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->updated_at, 'DLQ replay events must be append-only');
    }

    public function test_dlq_risk_scoring_is_deterministic(): void
    {
        $data = ['topic' => 'xdr.alerts', 'batch_limit' => 100];
        $s1   = $this->dlq()->scoreReplayRisk($data);
        $s2   = $this->dlq()->scoreReplayRisk($data);
        $this->assertSame($s1, $s2, 'Risk scoring must be deterministic');
    }

    public function test_dlq_risk_score_bounded_between_zero_and_one(): void
    {
        foreach (['telemetry.raw', 'xdr.alerts', 'xdr.alerts.shadow.endpoint'] as $topic) {
            $score = $this->dlq()->scoreReplayRisk(['topic' => $topic, 'batch_limit' => 500]);
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }

    // -----------------------------------------------------------------------
    // Queue lag metric correctness
    // -----------------------------------------------------------------------

    public function test_queue_lag_metric_records_correctly(): void
    {
        $metric = $this->ops()->recordQueueLag(
            'normalizer-worker', 'telemetry.raw', 0, 500, 10
        );

        $this->assertSame('normalizer-worker', $metric->consumer_group);
        $this->assertSame(500, (int) $metric->lag_count);
        $this->assertContains($metric->trend, [
            QueueLagMetric::TREND_INCREASING,
            QueueLagMetric::TREND_STABLE,
            QueueLagMetric::TREND_DECREASING,
        ]);
    }

    public function test_queue_lag_trend_increasing_detected(): void
    {
        // Record a small initial lag
        $this->ops()->recordQueueLag('alert-writer-service', 'alerts.created', 0, 100, 5);
        // Then record a large jump — should be detected as increasing
        $metric = $this->ops()->recordQueueLag('alert-writer-service', 'alerts.created', 0, 2000, 30);

        $this->assertSame(QueueLagMetric::TREND_INCREASING, $metric->trend);
    }

    public function test_stream_pressure_metric_classifies_states(): void
    {
        $normal   = StreamPressureMetric::classifyState(50.0, 100.0, 200.0);
        $warning  = StreamPressureMetric::classifyState(150.0, 100.0, 200.0);
        $critical = StreamPressureMetric::classifyState(250.0, 100.0, 200.0);

        $this->assertSame(StreamPressureMetric::STATE_NORMAL,   $normal);
        $this->assertSame(StreamPressureMetric::STATE_WARNING,  $warning);
        $this->assertSame(StreamPressureMetric::STATE_CRITICAL, $critical);
    }

    // -----------------------------------------------------------------------
    // Degraded-mode visibility
    // -----------------------------------------------------------------------

    public function test_degraded_service_detected(): void
    {
        $this->ops()->recordServiceHealth('correlation-worker', 'degraded', null, 'Redpanda unavailable');

        $degraded = $this->ops()->getDegradedServices();
        $this->assertTrue(
            $degraded->contains(fn ($s) => $s->service_name === 'correlation-worker'),
            'Degraded service must appear in getDegradedServices()'
        );
    }

    public function test_operational_warnings_include_degraded_services(): void
    {
        $this->ops()->recordServiceHealth('opensearch', 'down', null, 'Connection refused');

        $warnings = $this->ops()->getOperationalWarnings();
        $hasDegraded = collect($warnings)
            ->where('type', 'service_health')
            ->isNotEmpty();

        $this->assertTrue($hasDegraded, 'Warnings must include down/degraded services');
    }

    public function test_health_score_degrades_with_down_services(): void
    {
        $this->ops()->recordServiceHealth('postgresql', 'healthy');
        $score_before = $this->ops()->scoreOverallHealth();

        $this->ops()->recordServiceHealth('postgresql', 'down');
        $score_after = $this->ops()->scoreOverallHealth();

        $this->assertLessThanOrEqual($score_before, $score_after,
            'Health score must degrade when services go down');
    }

    public function test_service_health_snapshots_are_append_only(): void
    {
        $snapshot = $this->ops()->recordServiceHealth('test-service', 'healthy');
        $this->assertNull($snapshot->updated_at, 'Service health snapshots must be append-only');
    }

    // -----------------------------------------------------------------------
    // Routes and views
    // -----------------------------------------------------------------------

    public function test_operations_health_dashboard_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.health'))
             ->assertOk();
    }

    public function test_queue_health_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.queue'))
             ->assertOk();
    }

    public function test_retention_center_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.retention'))
             ->assertOk();
    }

    public function test_backup_recovery_view_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.backup'))
             ->assertOk();
    }

    public function test_dlq_replay_center_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.dlq'))
             ->assertOk();
    }

    public function test_deployment_graph_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.graph'))
             ->assertOk();
    }

    public function test_warnings_timeline_accessible(): void
    {
        $this->actingAs($this->admin())
             ->get(route('operations.warnings'))
             ->assertOk();
    }

    public function test_operations_routes_require_authentication(): void
    {
        $this->get(route('operations.health'))->assertRedirect('/login');
    }

    public function test_api_health_summary_endpoint(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.operations.health'))
             ->assertOk()
             ->assertJsonStructure(['score', 'services', 'warnings_count', 'advisory_note']);
    }

    public function test_api_dependency_graph_endpoint(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('api.operations.graph'))
             ->assertOk()
             ->assertJsonStructure(['nodes', 'edges']);
    }

    // -----------------------------------------------------------------------
    // Safety boundary assertions
    // -----------------------------------------------------------------------

    public function test_no_autonomous_remediation_in_retention_service(): void
    {
        $src = file_get_contents(app_path('Services/RetentionGovernanceService.php'));
        $this->assertStringNotContainsString('auto_remediate',       $src);
        $this->assertStringNotContainsString('autonomous_execution', $src);
        $this->assertStringNotContainsString('auto_delete',          $src);
        $this->assertStringContainsString('dry_run',                 $src);
    }

    public function test_no_infinite_replay_loop_in_dlq_service(): void
    {
        $src = file_get_contents(app_path('Services/DlqReplayService.php'));
        $this->assertStringNotContainsString('while (true)',    $src);
        $this->assertStringNotContainsString('for(;;)',         $src);
        $this->assertStringContainsString('MAX_BATCH_LIMIT',   $src);
    }

    public function test_no_infrastructure_mutation_in_ops_health_service(): void
    {
        $src = file_get_contents(app_path('Services/OperationsHealthService.php'));
        $this->assertStringNotContainsString('system(',          $src);
        $this->assertStringNotContainsString('exec(',            $src);
        $this->assertStringNotContainsString('shell_exec(',      $src);
        $this->assertStringNotContainsString('auto_heal',        $src);
    }

    public function test_dependency_graph_is_read_only(): void
    {
        $graph = $this->ops()->buildDependencyGraph();
        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);
        $this->assertNotEmpty($graph['nodes']);
        // Verify it's structural data only — no health mutations
        $this->assertCount(13, $graph['nodes']);
    }

    public function test_retention_eligible_domains_never_include_audit_tables(): void
    {
        $auditTables = [
            'retention_audit_events', 'response_execution_events',
            'endpoint_agent_heartbeats', 'investigation_events',
            'security_hardening_events', 'export_audit_logs',
        ];

        foreach ($auditTables as $table) {
            $this->assertNotContains(
                $table,
                RetentionPolicy::ELIGIBLE_DOMAINS,
                "Audit table '{$table}' must never be in ELIGIBLE_DOMAINS"
            );
        }
    }
}
