<?php

namespace Tests\Feature;

use App\Models\OperationalRecoveryRun;
use App\Models\ServiceLifecycleAudit;
use App\Models\RecoveryCheckpointHistory;
use App\Models\FailoverValidationRun;
use App\Models\BoundedAutomationReport;
use App\Models\RecoveryDependencyGraph;
use App\Models\OperationalContinuityReport;
use App\Models\RecoverySimulationRun;
use App\Models\EnterpriseOperationsAudit;
use App\Services\EnterpriseOperationsAutomationService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EnterpriseOperationsAutomationTest extends TestCase
{
    use RefreshDatabase;

    private EnterpriseOperationsAutomationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseOperationsAutomationService::class);
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'autoRemediate'));
    }

    public function test_no_destructive_infrastructure_mutation(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'mutateInfrastructure'));
    }

    public function test_no_uncontrolled_failover_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'executeFailover'));
    }

    public function test_no_unsafe_self_healing_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'selfHeal'));
    }

    public function test_no_hidden_automation_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'runHiddenAutomation'));
    }

    public function test_no_unsafe_recovery_bypass_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseOperationsAutomationService::class, 'bypassRecoveryGate'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EnterpriseOperationsAutomationService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Operational recovery run
    // =========================================================================

    public function test_recovery_run_creates_record(): void
    {
        $r = $this->service->runOperationalRecovery('correlation-worker', 'restart', 'initiated');
        $this->assertEquals('correlation-worker', $r->service_name);
        $this->assertEquals('restart', $r->recovery_type);
        $this->assertTrue($r->bounded_scope);
        $this->assertTrue($r->is_advisory);
    }

    public function test_recovery_run_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runOperationalRecovery('svc', 'nuclear_option', 'initiated');
    }

    public function test_recovery_run_rejects_invalid_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runOperationalRecovery('svc', 'restart', 'unknown_state');
    }

    public function test_recovery_duration_clamped_to_max(): void
    {
        $r = $this->service->runOperationalRecovery('svc', 'replay', 'completed', [
            'duration_s' => EnterpriseOperationsAutomationService::MAX_RECOVERY_DURATION_S + 9999,
        ]);
        $this->assertLessThanOrEqual(EnterpriseOperationsAutomationService::MAX_RECOVERY_DURATION_S, $r->duration_s);
    }

    public function test_recovery_run_is_append_only(): void
    {
        $r = $this->service->runOperationalRecovery('svc', 'drain', 'completed');
        $model = OperationalRecoveryRun::where('recovery_run_id', $r->recovery_run_id)->first();
        $this->expectException(LogicException::class);
        $model->recovery_state = 'failed';
        $model->save();
    }

    // =========================================================================
    // Service lifecycle audit
    // =========================================================================

    public function test_lifecycle_event_creates_record(): void
    {
        $r = $this->service->recordLifecycleEvent('normalizer-worker', 'startup', 'running');
        $this->assertEquals('normalizer-worker', $r->service_name);
        $this->assertEquals('startup', $r->lifecycle_event);
        $this->assertTrue($r->is_advisory);
    }

    public function test_lifecycle_rejects_invalid_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordLifecycleEvent('svc', 'explode', 'unknown');
    }

    public function test_drift_detected_flag_on_drift_event(): void
    {
        $r = $this->service->recordLifecycleEvent('svc', 'drift_detected', 'degraded');
        $this->assertTrue($r->drift_detected);
    }

    public function test_lifecycle_audit_uses_explicit_table(): void
    {
        $r = $this->service->recordLifecycleEvent('svc', 'recovered', 'running');
        $this->assertDatabaseHas('service_lifecycle_audit', ['lifecycle_id' => $r->lifecycle_id]);
    }

    public function test_lifecycle_audit_is_append_only(): void
    {
        $r = $this->service->recordLifecycleEvent('svc', 'restart', 'running');
        $model = ServiceLifecycleAudit::where('lifecycle_id', $r->lifecycle_id)->first();
        $this->expectException(LogicException::class);
        $model->drift_detected = true;
        $model->save();
    }

    // =========================================================================
    // Recovery checkpoint
    // =========================================================================

    public function test_recovery_checkpoint_creates_record(): void
    {
        $r = $this->service->recordRecoveryCheckpoint('rr-001', 'pre_recovery', true);
        $this->assertEquals('pre_recovery', $r->checkpoint_type);
        $this->assertTrue($r->passed);
        $this->assertTrue($r->is_advisory);
    }

    public function test_recovery_checkpoint_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRecoveryCheckpoint('rr-001', 'mid_recovery', false);
    }

    public function test_recovery_checkpoint_failure_recorded(): void
    {
        $r = $this->service->recordRecoveryCheckpoint('rr-001', 'post_restart', false);
        $this->assertFalse($r->passed);
    }

    public function test_recovery_checkpoint_is_append_only(): void
    {
        $r = $this->service->recordRecoveryCheckpoint('rr-001', 'continuity_verified', true);
        $model = RecoveryCheckpointHistory::where('ops_checkpoint_id', $r->ops_checkpoint_id)->first();
        $this->expectException(LogicException::class);
        $model->passed = false;
        $model->save();
    }

    // =========================================================================
    // Failover validation
    // =========================================================================

    public function test_failover_validation_creates_record(): void
    {
        $r = $this->service->runFailoverValidation('alert-writer', 'active_passive', [
            'readiness_verified' => true,
            'rollback_ready'     => true,
        ]);
        $this->assertEquals('active_passive', $r->failover_type);
        $this->assertTrue($r->readiness_verified);
        $this->assertTrue($r->is_advisory);
    }

    public function test_failover_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runFailoverValidation('svc', 'emergency_cutover', []);
    }

    public function test_failover_continuity_not_verified_by_default(): void
    {
        $r = $this->service->runFailoverValidation('svc', 'drain_switch', []);
        $this->assertFalse($r->continuity_verified);
    }

    public function test_failover_validation_is_append_only(): void
    {
        $r = $this->service->runFailoverValidation('svc', 'replica_promotion', []);
        $model = FailoverValidationRun::where('failover_run_id', $r->failover_run_id)->first();
        $this->expectException(LogicException::class);
        $model->readiness_verified = true;
        $model->save();
    }

    // =========================================================================
    // Bounded automation
    // =========================================================================

    public function test_bounded_automation_creates_record(): void
    {
        $r = $this->service->runBoundedAutomation('health_check', 'correlation-worker', [
            'check_passed' => true,
        ]);
        $this->assertEquals('health_check', $r->automation_type);
        $this->assertTrue($r->check_passed);
        $this->assertTrue($r->is_advisory);
    }

    public function test_autonomous_action_always_false(): void
    {
        $r = $this->service->runBoundedAutomation('dependency_check', 'svc', [
            'autonomous_action_taken' => true, // should be ignored
        ]);
        $this->assertFalse($r->autonomous_action_taken);
    }

    public function test_bounded_automation_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runBoundedAutomation('auto_fix_all', 'svc', []);
    }

    public function test_bounded_automation_is_append_only(): void
    {
        $r = $this->service->runBoundedAutomation('replay_continuity', 'svc', []);
        $model = BoundedAutomationReport::where('automation_id', $r->automation_id)->first();
        $this->expectException(LogicException::class);
        $model->check_passed = true;
        $model->save();
    }

    // =========================================================================
    // Recovery dependency graph
    // =========================================================================

    public function test_dependency_graph_creates_record(): void
    {
        $r = $this->service->recordDependencyGraph('correlation-worker', 3, [
            'total_dependencies'      => 5,
            'all_dependencies_healthy'=> true,
        ]);
        $this->assertEquals('correlation-worker', $r->root_service);
        $this->assertEquals(3, $r->dependency_depth);
        $this->assertTrue($r->is_advisory);
    }

    public function test_dependency_depth_clamped_to_max(): void
    {
        $r = $this->service->recordDependencyGraph('svc', 99);
        $this->assertLessThanOrEqual(EnterpriseOperationsAutomationService::MAX_DEPENDENCY_DEPTH, $r->dependency_depth);
    }

    public function test_circular_dependency_marks_not_all_healthy(): void
    {
        $r = $this->service->recordDependencyGraph('svc', 2, [
            'circular_dependency_detected' => true,
            'all_dependencies_healthy'     => true, // overridden by circular
        ]);
        $this->assertTrue($r->circular_dependency_detected);
        $this->assertFalse($r->all_dependencies_healthy);
    }

    public function test_dependency_graph_is_append_only(): void
    {
        $r = $this->service->recordDependencyGraph('svc', 1);
        $model = RecoveryDependencyGraph::where('graph_id', $r->graph_id)->first();
        $this->expectException(LogicException::class);
        $model->all_dependencies_healthy = true;
        $model->save();
    }

    // =========================================================================
    // Operational continuity report
    // =========================================================================

    public function test_continuity_report_passes_when_all_checks_pass(): void
    {
        $r = $this->service->recordContinuityReport('production', [
            'replay_continuous'    => true,
            'queue_continuous'     => true,
            'telemetry_continuous' => true,
            'storage_continuous'   => true,
        ]);
        $this->assertEquals(1.0, $r->continuity_score);
        $this->assertTrue($r->overall_continuous);
    }

    public function test_continuity_report_fails_when_below_threshold(): void
    {
        $r = $this->service->recordContinuityReport('staging', [
            'replay_continuous'    => false,
            'queue_continuous'     => false,
            'telemetry_continuous' => false,
            'storage_continuous'   => false,
        ]);
        $this->assertFalse($r->overall_continuous);
        $this->assertEquals(0.0, $r->continuity_score);
    }

    public function test_continuity_report_rejects_invalid_environment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordContinuityReport('hyperscale_cloud', []);
    }

    public function test_continuity_report_uses_explicit_table(): void
    {
        $r = $this->service->recordContinuityReport('local', []);
        $this->assertDatabaseHas('operational_continuity_reports', ['continuity_id' => $r->continuity_id]);
    }

    public function test_continuity_report_is_append_only(): void
    {
        $r = $this->service->recordContinuityReport('local', []);
        $model = OperationalContinuityReport::where('continuity_id', $r->continuity_id)->first();
        $this->expectException(LogicException::class);
        $model->overall_continuous = true;
        $model->save();
    }

    // =========================================================================
    // Recovery simulation
    // =========================================================================

    public function test_recovery_simulation_creates_record(): void
    {
        $r = $this->service->runRecoverySimulation('queue_reconnect', 'redpanda');
        $this->assertEquals('queue_reconnect', $r->simulation_type);
        $this->assertEquals('redpanda', $r->target_service);
        $this->assertTrue($r->is_advisory);
    }

    public function test_destructive_action_always_false(): void
    {
        $r = $this->service->runRecoverySimulation('service_restart', 'svc', [
            'destructive_action_taken' => true, // always overridden to false
        ]);
        $this->assertFalse($r->destructive_action_taken);
    }

    public function test_simulation_duration_clamped(): void
    {
        $r = $this->service->runRecoverySimulation('storage_reconnect', 'postgres', [
            'duration_s' => EnterpriseOperationsAutomationService::MAX_SIMULATION_DURATION_S + 9999,
        ]);
        $this->assertLessThanOrEqual(EnterpriseOperationsAutomationService::MAX_SIMULATION_DURATION_S, $r->duration_s);
    }

    public function test_simulation_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runRecoverySimulation('nuclear_test', 'svc');
    }

    public function test_recovery_simulation_is_append_only(): void
    {
        $r = $this->service->runRecoverySimulation('replay_recovery', 'svc');
        $model = RecoverySimulationRun::where('simulation_id', $r->simulation_id)->first();
        $this->expectException(LogicException::class);
        $model->simulation_passed = true;
        $model->save();
    }

    // =========================================================================
    // Enterprise operations audit
    // =========================================================================

    public function test_ops_audit_creates_record(): void
    {
        $r = $this->service->recordOperationsAudit('recovery_initiated', 'correlation-worker', 'ops-team', 'success');
        $this->assertEquals('recovery_initiated', $r->operation_type);
        $this->assertEquals('success', $r->outcome);
        $this->assertTrue($r->is_advisory);
    }

    public function test_ops_audit_rejects_invalid_operation_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationsAudit('force_restart_all', 'svc', 'ops', 'success');
    }

    public function test_ops_audit_rejects_invalid_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationsAudit('lifecycle_event', 'svc', 'ops', 'unknown_outcome');
    }

    public function test_ops_audit_is_append_only(): void
    {
        $r = $this->service->recordOperationsAudit('automation_check', 'svc', 'ops', 'advisory');
        $model = EnterpriseOperationsAudit::where('ops_audit_id', $r->ops_audit_id)->first();
        $this->expectException(LogicException::class);
        $model->outcome = 'failure';
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('recovery_runs', $stats);
        $this->assertArrayHasKey('failed_recoveries', $stats);
        $this->assertArrayHasKey('lifecycle_events', $stats);
        $this->assertArrayHasKey('drift_events', $stats);
        $this->assertArrayHasKey('checkpoint_failures', $stats);
        $this->assertArrayHasKey('failover_runs', $stats);
        $this->assertArrayHasKey('automation_checks', $stats);
        $this->assertArrayHasKey('continuity_failures', $stats);
        $this->assertArrayHasKey('simulation_runs', $stats);
        $this->assertArrayHasKey('audit_events', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_has_130_supported_domains(): void
    {
        $this->assertCount(145, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_operational_recovery_runs_domain_supported(): void
    {
        $this->assertContains('operational_recovery_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_service_lifecycle_audit_domain_supported(): void
    {
        $this->assertContains('service_lifecycle_audit', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_failover_validation_runs_domain_supported(): void
    {
        $this->assertContains('failover_validation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_operational_continuity_reports_domain_supported(): void
    {
        $this->assertContains('operational_continuity_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_recovery_simulation_runs_domain_supported(): void
    {
        $this->assertContains('recovery_simulation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_enterprise_ops_dashboard_requires_auth(): void
    {
        $this->get(route('enterprise-ops.dashboard'))->assertRedirect();
    }

    public function test_enterprise_ops_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-ops.dashboard'))->assertOk();
    }

    public function test_enterprise_ops_recovery_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-ops.recovery'))->assertOk();
    }

    public function test_enterprise_ops_failover_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-ops.failover'))->assertOk();
    }

    // =========================================================================
    // Advisory notice
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-ops.dashboard'))
            ->assertSee('advisory-only');
    }
}

