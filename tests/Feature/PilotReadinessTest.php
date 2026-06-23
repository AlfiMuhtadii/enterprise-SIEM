<?php

namespace Tests\Feature;

use App\Models\PilotOnboardingRun;
use App\Models\PilotHealthValidation;
use App\Models\PilotSuccessMetric;
use App\Models\PilotRollbackValidation;
use App\Models\TelemetryOnboardingPressure;
use App\Models\OperatorReadinessReview;
use App\Models\PilotAuditEvent;
use App\Models\OnboardingApprovalRequest;
use App\Models\PilotObservationWindow;
use App\Services\PilotReadinessService;
use App\Services\ThreatHuntingService;
use App\Services\EntityRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotReadinessTest extends TestCase
{
    use RefreshDatabase;

    private PilotReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PilotReadinessService::class);
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_isolate_host(): void
    {
        $this->assertFalse(method_exists($this->service, 'isolateHost'));
    }

    public function test_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists($this->service, 'quarantineHost'));
    }

    public function test_no_execute_shell(): void
    {
        $this->assertFalse(method_exists($this->service, 'executeShell'));
    }

    public function test_no_kill_process(): void
    {
        $this->assertFalse(method_exists($this->service, 'killProcess'));
    }

    public function test_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists($this->service, 'autoRemediate'));
    }

    public function test_no_uncontrolled_onboarding(): void
    {
        $this->assertFalse(method_exists($this->service, 'uncontrolledOnboarding'));
    }

    public function test_no_unrestricted_telemetry_onboarding(): void
    {
        $this->assertFalse(method_exists($this->service, 'unrestrictedTelemetryOnboarding'));
    }

    public function test_no_unsafe_rollback(): void
    {
        $this->assertFalse(method_exists($this->service, 'unsafeRollback'));
    }

    public function test_no_autonomous_deployment(): void
    {
        $this->assertFalse(method_exists($this->service, 'autonomousDeployment'));
    }

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(PilotReadinessService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Onboarding run
    // =========================================================================

    public function test_register_pilot_onboarding_creates_pending_run(): void
    {
        $run = $this->service->registerPilotOnboarding(
            'tenant-001', 'operator-001', 500, 50, 24
        );

        $this->assertSame('pending', $run->status);
        $this->assertSame('tenant-001', $run->tenant_id);
        $this->assertSame(500, $run->max_events_per_second);
        $this->assertSame(50, $run->max_endpoints);
        $this->assertSame(24, $run->pilot_duration_hours);
        $this->assertFalse($run->readiness_checklist_complete);
        $this->assertFalse($run->rollback_drill_complete);
        $this->assertFalse($run->operator_acknowledged);
        $this->assertTrue($run->is_advisory);
    }

    public function test_onboarding_rejects_excessive_eps(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->registerPilotOnboarding('tenant', 'op', 15000, 50, 24);
    }

    public function test_onboarding_rejects_excessive_endpoints(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->registerPilotOnboarding('tenant', 'op', 500, 1500, 24);
    }

    public function test_onboarding_rejects_excessive_duration(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->registerPilotOnboarding('tenant', 'op', 500, 50, 200);
    }

    public function test_onboarding_bounded_at_max_eps(): void
    {
        $run = $this->service->registerPilotOnboarding('tenant', 'op', 10000, 100, 24);
        $this->assertSame(10000, $run->max_events_per_second);
    }

    public function test_onboarding_is_append_only(): void
    {
        $run = $this->service->registerPilotOnboarding('tenant', 'op', 100, 10, 24);
        $this->expectException(\LogicException::class);
        $run->status = 'active';
        $run->save();
    }

    public function test_onboarding_run_id_prefix(): void
    {
        $run = $this->service->registerPilotOnboarding('tenant', 'op', 100, 10, 24);
        $this->assertStringStartsWith('por-', $run->run_id);
    }

    // =========================================================================
    // Health validation
    // =========================================================================

    public function test_health_check_pass_when_metric_meets_threshold(): void
    {
        $v = $this->service->runHealthCheck('run-001', 'tenant-001', 'telemetry', 0.97, 0.95);

        $this->assertTrue($v->check_passed);
        $this->assertSame('pass', $v->verdict);
        $this->assertNull($v->failure_reason);
        $this->assertTrue($v->is_advisory);
    }

    public function test_health_check_fail_when_metric_below_threshold(): void
    {
        $v = $this->service->runHealthCheck('run-001', 'tenant-001', 'queue', 0.50, 0.95);

        $this->assertFalse($v->check_passed);
        $this->assertSame('fail', $v->verdict);
        $this->assertNotNull($v->failure_reason);
    }

    public function test_health_check_degraded_when_slightly_below(): void
    {
        $v = $this->service->runHealthCheck('run-001', 'tenant-001', 'replay', 0.77, 0.95);
        $this->assertSame('degraded', $v->verdict);
    }

    public function test_health_check_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runHealthCheck('run', 'tenant', 'invalid_type', 0.9, 0.95);
    }

    public function test_health_check_is_append_only(): void
    {
        $v = $this->service->runHealthCheck('run', 'tenant', 'worker', 0.99, 0.95);
        $this->expectException(\LogicException::class);
        $v->check_passed = false;
        $v->save();
    }

    public function test_all_check_types_accepted(): void
    {
        foreach (PilotHealthValidation::CHECK_TYPES as $type) {
            $v = $this->service->runHealthCheck('run', 'tenant', $type, 0.99, 0.95);
            $this->assertSame($type, $v->check_type);
        }
    }

    // =========================================================================
    // Success metrics
    // =========================================================================

    public function test_success_metric_target_met(): void
    {
        $m = $this->service->recordSuccessMetric('run', 'tenant', 'telemetry_continuity_pct', 0.97, 0.95, 24);

        $this->assertTrue($m->target_met);
        $this->assertSame('telemetry_continuity_pct', $m->metric_name);
        $this->assertTrue($m->is_advisory);
    }

    public function test_success_metric_target_missed(): void
    {
        $m = $this->service->recordSuccessMetric('run', 'tenant', 'replay_success_pct', 0.90, 0.98, 24);
        $this->assertFalse($m->target_met);
    }

    public function test_success_metric_fp_ratio_lower_is_better(): void
    {
        $m = $this->service->recordSuccessMetric('run', 'tenant', 'fp_ratio', 0.02, 0.05, 24);
        $this->assertTrue($m->target_met);
    }

    public function test_success_metric_fp_ratio_higher_fails(): void
    {
        $m = $this->service->recordSuccessMetric('run', 'tenant', 'fp_ratio', 0.08, 0.05, 24);
        $this->assertFalse($m->target_met);
    }

    public function test_success_metric_rejects_invalid_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSuccessMetric('run', 'tenant', 'invalid_metric', 0.9, 0.95, 24);
    }

    public function test_success_metric_is_append_only(): void
    {
        $m = $this->service->recordSuccessMetric('run', 'tenant', 'endpoint_stability_pct', 0.99, 0.95, 24);
        $this->expectException(\LogicException::class);
        $m->target_met = false;
        $m->save();
    }

    // =========================================================================
    // Rollback validation
    // =========================================================================

    public function test_rollback_validation_pass_all_ok(): void
    {
        $v = $this->service->validateRollback('run', 'tenant', 'manual', true, true, true, true, 'approver-1');

        $this->assertSame('pass', $v->verdict);
        $this->assertTrue($v->rollback_safe);
        $this->assertTrue($v->is_advisory);
    }

    public function test_rollback_validation_fail_on_invalid_checkpoint(): void
    {
        $v = $this->service->validateRollback('run', 'tenant', 'health_failure', false, true, true, true);
        $this->assertSame('fail', $v->verdict);
    }

    public function test_rollback_validation_pending_when_no_approval(): void
    {
        $v = $this->service->validateRollback('run', 'tenant', 'metric_breach', true, false, true, true);
        $this->assertSame('pending_approval', $v->verdict);
    }

    public function test_rollback_rejects_invalid_trigger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateRollback('run', 'tenant', 'invalid_trigger', true, true, true, true);
    }

    public function test_rollback_is_append_only(): void
    {
        $v = $this->service->validateRollback('run', 'tenant', 'manual', true, true, true, true);
        $this->expectException(\LogicException::class);
        $v->verdict = 'fail';
        $v->save();
    }

    // =========================================================================
    // Telemetry pressure
    // =========================================================================

    public function test_pressure_normal_at_low_eps(): void
    {
        $s = $this->service->snapshotTelemetryPressure('run', 'tenant', 500, 100, 50, 10, 1.2);

        $this->assertSame('normal', $s->pressure_level);
        $this->assertTrue($s->pressure_ok);
        $this->assertTrue($s->is_advisory);
    }

    public function test_pressure_critical_at_high_eps(): void
    {
        $s = $this->service->snapshotTelemetryPressure('run', 'tenant', 9000, 1000, 500, 100, 1.5);
        $this->assertSame('critical', $s->pressure_level);
        $this->assertFalse($s->pressure_ok);
    }

    public function test_pressure_elevated_at_mid_eps(): void
    {
        $s = $this->service->snapshotTelemetryPressure('run', 'tenant', 3000, 200, 100, 30, 1.2);
        $this->assertSame('elevated', $s->pressure_level);
    }

    public function test_pressure_fails_on_high_replay_amplification(): void
    {
        $s = $this->service->snapshotTelemetryPressure('run', 'tenant', 100, 10, 5, 5, 4.0);
        $this->assertFalse($s->pressure_ok);
    }

    public function test_pressure_is_append_only(): void
    {
        $s = $this->service->snapshotTelemetryPressure('run', 'tenant', 100, 10, 5, 5, 1.0);
        $this->expectException(\LogicException::class);
        $s->pressure_level = 'critical';
        $s->save();
    }

    // =========================================================================
    // Operator readiness
    // =========================================================================

    public function test_operator_ready_when_all_pass(): void
    {
        $r = $this->service->recordOperatorReadiness('run', 'op-001', 'runbook', true, true, true, true, 30);

        $this->assertTrue($r->operator_ready);
        $this->assertSame('pass', $r->verdict);
        $this->assertSame(30, $r->acknowledgment_latency_seconds);
        $this->assertTrue($r->is_advisory);
    }

    public function test_operator_incomplete_when_some_missing(): void
    {
        $r = $this->service->recordOperatorReadiness('run', 'op-001', 'escalation', true, false, true, false);
        $this->assertFalse($r->operator_ready);
        $this->assertSame('incomplete', $r->verdict);
    }

    public function test_operator_fail_when_all_missing(): void
    {
        $r = $this->service->recordOperatorReadiness('run', 'op-001', 'general', false, false, false, false);
        $this->assertSame('fail', $r->verdict);
    }

    public function test_operator_review_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperatorReadiness('run', 'op', 'invalid_type', true, true, true, true);
    }

    public function test_operator_review_is_append_only(): void
    {
        $r = $this->service->recordOperatorReadiness('run', 'op', 'runbook', true, true, true, true);
        $this->expectException(\LogicException::class);
        $r->operator_ready = false;
        $r->save();
    }

    // =========================================================================
    // Approval request
    // =========================================================================

    public function test_approval_request_created_pending(): void
    {
        $req = $this->service->requestOnboardingApproval('run', 'tenant', 'operator-1');

        $this->assertSame('pending', $req->status);
        $this->assertTrue($req->self_approve_blocked);
        $this->assertTrue($req->is_advisory);
    }

    public function test_self_approve_blocked(): void
    {
        $req = $this->service->requestOnboardingApproval('run', 'tenant', 'operator-1');
        $canSelf = $this->service->canSelfApprove($req, 'operator-1');
        $this->assertFalse($canSelf);
    }

    public function test_different_reviewer_can_approve(): void
    {
        $req = $this->service->requestOnboardingApproval('run', 'tenant', 'operator-1');
        $canApprove = $this->service->canSelfApprove($req, 'operator-2');
        $this->assertTrue($canApprove);
    }

    public function test_approval_request_is_append_only(): void
    {
        $req = $this->service->requestOnboardingApproval('run', 'tenant', 'op-1');
        $this->expectException(\LogicException::class);
        $req->status = 'approved';
        $req->save();
    }

    // =========================================================================
    // Audit event
    // =========================================================================

    public function test_audit_event_records_correctly(): void
    {
        $e = $this->service->recordAuditEvent('run', 'tenant', 'onboarding_started', 'Pilot started', 'op-1');

        $this->assertSame('onboarding_started', $e->event_type);
        $this->assertSame('Pilot started', $e->description);
        $this->assertSame('op-1', $e->actor_id);
        $this->assertTrue($e->is_advisory);
    }

    public function test_audit_event_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAuditEvent('run', 'tenant', 'invalid_event', 'test');
    }

    public function test_audit_event_is_append_only(): void
    {
        $e = $this->service->recordAuditEvent('run', 'tenant', 'health_check', 'check done');
        $this->expectException(\LogicException::class);
        $e->event_type = 'pilot_aborted';
        $e->save();
    }

    // =========================================================================
    // Observation window (mutable)
    // =========================================================================

    public function test_observation_window_is_mutable(): void
    {
        $w = $this->service->createObservationWindow('run', 'tenant', 24, '24h');
        $this->assertSame('pending', $w->status);

        $w->status = 'active';
        $w->save();
        $this->assertSame('active', PilotObservationWindow::find($w->id)->status);
    }

    public function test_observation_window_rejects_invalid_phase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createObservationWindow('run', 'tenant', 24, '1h');
    }

    public function test_observation_window_all_phases_accepted(): void
    {
        foreach (PilotObservationWindow::PHASES as $phase) {
            $w = $this->service->createObservationWindow('run', 'tenant', 24, $phase);
            $this->assertSame($phase, $w->phase);
        }
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function test_dashboard_stats_has_all_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('total_pilots', $stats);
        $this->assertArrayHasKey('active_pilots', $stats);
        $this->assertArrayHasKey('pending_approvals', $stats);
        $this->assertArrayHasKey('health_failures', $stats);
        $this->assertArrayHasKey('rollback_validations', $stats);
        $this->assertArrayHasKey('rollback_fail', $stats);
        $this->assertArrayHasKey('critical_pressure', $stats);
        $this->assertArrayHasKey('metrics_targets_met', $stats);
        $this->assertArrayHasKey('metrics_targets_missed', $stats);
        $this->assertArrayHasKey('operator_ready', $stats);
        $this->assertArrayHasKey('operator_not_ready', $stats);
        $this->assertArrayHasKey('active_windows', $stats);
    }

    // =========================================================================
    // Threat hunting domains
    // =========================================================================

    public function test_pilot_onboarding_runs_domain_supported(): void
    {
        $this->assertContains('pilot_onboarding_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pilot_health_validations_domain_supported(): void
    {
        $this->assertContains('pilot_health_validations', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pilot_success_metrics_domain_supported(): void
    {
        $this->assertContains('pilot_success_metrics', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pilot_rollback_validations_domain_supported(): void
    {
        $this->assertContains('pilot_rollback_validations', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_operator_readiness_reviews_domain_supported(): void
    {
        $this->assertContains('operator_readiness_reviews', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_total_hunt_domains_is_90(): void
    {
        $this->assertCount(161, app(ThreatHuntingService::class)->supportedDomains());
    }

    // =========================================================================
    // Entity risk factors
    // =========================================================================

    public function test_pilot_risk_factors_in_weight_table(): void
    {
        $w = EntityRiskScoringService::WEIGHTS;
        $this->assertArrayHasKey('pilot_health_failure_factor', $w);
        $this->assertArrayHasKey('pilot_onboarding_pressure_factor', $w);
        $this->assertArrayHasKey('pilot_rollback_trigger_factor', $w);
        $this->assertArrayHasKey('operator_readiness_failure_factor', $w);
    }

    public function test_pilot_risk_factors_are_nonzero(): void
    {
        $w = EntityRiskScoringService::WEIGHTS;
        $this->assertGreaterThan(0, $w['pilot_health_failure_factor']);
        $this->assertGreaterThan(0, $w['pilot_onboarding_pressure_factor']);
        $this->assertGreaterThan(0, $w['pilot_rollback_trigger_factor']);
        $this->assertGreaterThan(0, $w['operator_readiness_failure_factor']);
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_pilot_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/pilot-readiness')->assertStatus(200);
    }

    public function test_onboarding_console_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/pilot-readiness/onboarding')->assertStatus(200);
    }

    public function test_stability_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/pilot-readiness/windows')->assertStatus(200);
    }

    public function test_views_contain_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/pilot-readiness')
            ->assertSee('Pilot governance workflows are bounded, replay-safe, and approval-gated');
    }
}


