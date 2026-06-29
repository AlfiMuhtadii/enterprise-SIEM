<?php

namespace Tests\Feature;

use App\Models\LivePilotRun;
use App\Models\PilotEndpointEnrollment;
use App\Models\PilotHealthCheckpoint;
use App\Models\PilotOperationalReview;
use App\Models\PilotDriftReview;
use App\Models\PilotRollbackAudit;
use App\Models\LiveTelemetryValidation;
use App\Models\ProductionObservationCheckpoint;
use App\Models\PilotExecutionAudit;
use App\Services\PilotExecutionService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class PilotExecutionTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private PilotExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PilotExecutionService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return PilotExecutionService::class;
    }

    // =========================================================================
    // Hard constraint: no forbidden methods
    // =========================================================================

    public function test_no_uncontrolled_onboarding_method(): void
    {
        $this->assertFalse(method_exists(PilotExecutionService::class, 'onboardUnbounded'));
    }

    public function test_no_unrestricted_tenant_scaling_method(): void
    {
        $this->assertFalse(method_exists(PilotExecutionService::class, 'scaleUnrestricted'));
    }

    public function test_no_destructive_rollback_method(): void
    {
        $this->assertFalse(method_exists(PilotExecutionService::class, 'destroyPilotData'));
    }

    public function test_no_hidden_operational_mutation_method(): void
    {
        $this->assertFalse(method_exists(PilotExecutionService::class, 'mutateOperationalState'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(PilotExecutionService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Bounded enrollment enforcement
    // =========================================================================

    public function test_activate_pilot_enforces_minimum_endpoint_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->activatePilot('t1', 'Test', 4, 'admin');
    }

    public function test_activate_pilot_enforces_maximum_endpoint_count(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->activatePilot('t1', 'Test', 21, 'admin');
    }

    public function test_activate_pilot_min_boundary_succeeds(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot A', PilotExecutionService::MIN_ENDPOINTS, 'admin');
        $this->assertEquals(PilotExecutionService::MIN_ENDPOINTS, $run->target_endpoint_count);
    }

    public function test_activate_pilot_max_boundary_succeeds(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot B', PilotExecutionService::MAX_ENDPOINTS, 'admin');
        $this->assertEquals(PilotExecutionService::MAX_ENDPOINTS, $run->target_endpoint_count);
    }

    public function test_activate_pilot_rejects_invalid_observation_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->activatePilot('t1', 'Test', 10, 'admin', 96);
    }

    public function test_activate_pilot_stores_advisory_flag(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot C', 10, 'admin');
        $this->assertTrue($run->is_advisory);
    }

    public function test_activate_pilot_writes_audit_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot D', 10, 'admin');
        $this->assertDatabaseHas('pilot_execution_audit', [
            'run_id'     => $run->run_id,
            'event_type' => 'activation',
            'outcome'    => 'success',
        ]);
    }

    // =========================================================================
    // Endpoint enrollment
    // =========================================================================

    public function test_enroll_endpoint_creates_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $enrollment = $this->service->enrollEndpoint($run->run_id, 't1', 'ep-001', 'host-1');
        $this->assertDatabaseHas('pilot_endpoint_enrollments', [
            'run_id'   => $run->run_id,
            'hostname' => 'host-1',
            'status'   => 'enrolled',
        ]);
        $this->assertTrue($enrollment->is_advisory);
    }

    public function test_enroll_endpoint_respects_target_count_limit(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 5, 'admin');
        for ($i = 1; $i <= 5; $i++) {
            $this->service->enrollEndpoint($run->run_id, 't1', "ep-{$i}", "host-{$i}");
        }
        $this->expectException(\OverflowException::class);
        $this->service->enrollEndpoint($run->run_id, 't1', 'ep-extra', 'host-extra');
    }

    public function test_enroll_endpoint_writes_audit_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->service->enrollEndpoint($run->run_id, 't1', 'ep-001', 'host-1');
        $this->assertDatabaseHas('pilot_execution_audit', [
            'run_id'     => $run->run_id,
            'event_type' => 'enrollment',
        ]);
    }

    // =========================================================================
    // Health checkpoint
    // =========================================================================

    public function test_health_checkpoint_passes_with_good_metrics(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordHealthCheckpoint($run->run_id, 't1', '24h', [
            'telemetry_continuity_pct'    => 0.97,
            'replay_recovery_success_pct' => 0.96,
            'endpoint_stability_pct'      => 0.95,
            'tenant_isolation_pass_rate'  => 1.0,
            'false_positive_ratio'        => 0.02,
            'drift_stability_pct'         => 0.98,
            'rollback_readiness_score'    => 0.85,
        ]);
        $this->assertTrue($cp->health_ok);
    }

    public function test_health_checkpoint_fails_below_threshold(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordHealthCheckpoint($run->run_id, 't1', '24h', [
            'telemetry_continuity_pct' => 0.80,
        ]);
        $this->assertFalse($cp->health_ok);
    }

    public function test_health_checkpoint_rejects_invalid_type(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordHealthCheckpoint($run->run_id, 't1', 'weekly', []);
    }

    public function test_health_checkpoint_stores_advisory_flag(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordHealthCheckpoint($run->run_id, 't1', 'manual', []);
        $this->assertTrue($cp->is_advisory);
    }

    // =========================================================================
    // Live telemetry validation
    // =========================================================================

    public function test_telemetry_validation_passes_with_good_metrics(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $v = $this->service->recordTelemetryValidation($run->run_id, 't1', [
            'telemetry_continuity_pct' => 0.98,
            'replay_continuity_pct'    => 0.97,
            'queue_lag'                => 100,
            'duplicate_event_rate'     => 0.001,
            'telemetry_gap_rate'       => 0.01,
            'storage_pressure_pct'     => 0.50,
            'worker_healthy'           => true,
        ]);
        $this->assertTrue($v->validation_passed);
    }

    public function test_telemetry_validation_fails_when_queue_lag_too_high(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $v = $this->service->recordTelemetryValidation($run->run_id, 't1', [
            'queue_lag' => 100_000,
        ]);
        $this->assertFalse($v->validation_passed);
    }

    public function test_telemetry_validation_is_advisory(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $v = $this->service->recordTelemetryValidation($run->run_id, 't1', []);
        $this->assertTrue($v->is_advisory);
    }

    // =========================================================================
    // Observation checkpoint
    // =========================================================================

    public function test_observation_checkpoint_24h_window(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordObservationCheckpoint($run->run_id, 't1', '24h', [
            'telemetry_continuity_pct'    => 0.97,
            'replay_recovery_success_pct' => 0.96,
            'drift_stability_pct'         => 0.98,
            'rollback_readiness_score'    => 0.85,
        ]);
        $this->assertTrue($cp->criteria_met);
        $this->assertEquals('24h', $cp->window_type);
    }

    public function test_observation_checkpoint_48h_window(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordObservationCheckpoint($run->run_id, 't1', '48h', []);
        $this->assertEquals('48h', $cp->window_type);
    }

    public function test_observation_checkpoint_72h_window(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordObservationCheckpoint($run->run_id, 't1', '72h', []);
        $this->assertEquals('72h', $cp->window_type);
    }

    public function test_observation_checkpoint_rejects_invalid_window(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordObservationCheckpoint($run->run_id, 't1', '96h', []);
    }

    public function test_observation_checkpoint_criteria_not_met_below_threshold(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordObservationCheckpoint($run->run_id, 't1', '24h', [
            'telemetry_continuity_pct' => 0.80,
        ]);
        $this->assertFalse($cp->criteria_met);
    }

    // =========================================================================
    // Operational review
    // =========================================================================

    public function test_operational_review_daily_creates_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $review = $this->service->recordOperationalReview(
            $run->run_id, 't1', 'daily', 'analyst-1', 'acknowledged', 'All clear'
        );
        $this->assertEquals('daily', $review->review_type);
        $this->assertEquals('acknowledged', $review->verdict);
        $this->assertTrue($review->is_advisory);
    }

    public function test_operational_review_rejects_invalid_type(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationalReview($run->run_id, 't1', 'weekly_unknown', 'analyst-1', 'acknowledged');
    }

    public function test_operational_review_rejects_invalid_verdict(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationalReview($run->run_id, 't1', 'daily', 'analyst-1', 'rejected_invalid');
    }

    public function test_operational_review_writes_audit(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->service->recordOperationalReview($run->run_id, 't1', 'escalation', 'analyst-2', 'escalated');
        $this->assertDatabaseHas('pilot_execution_audit', [
            'run_id'     => $run->run_id,
            'event_type' => 'review',
        ]);
    }

    // =========================================================================
    // Drift review
    // =========================================================================

    public function test_drift_review_creates_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $dr = $this->service->recordDriftReview(
            $run->run_id, 't1', 'telemetry', 0.03, 'low', 'stable', 'analyst-1'
        );
        $this->assertEquals('telemetry', $dr->drift_type);
        $this->assertEquals('stable', $dr->verdict);
        $this->assertTrue($dr->is_advisory);
    }

    public function test_drift_review_rejects_invalid_type(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDriftReview($run->run_id, 't1', 'unknown_drift', 0.1, 'low', 'stable', 'analyst-1');
    }

    public function test_drift_review_rollback_triggered_is_false_by_default(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $dr = $this->service->recordDriftReview($run->run_id, 't1', 'queue', 0.05, 'medium', 'monitoring', 'analyst-1');
        $this->assertFalse($dr->rollback_triggered);
    }

    // =========================================================================
    // Rollback audit ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â no destructive action
    // =========================================================================

    public function test_rollback_audit_destructive_action_always_false(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $audit = $this->service->recordRollbackAudit(
            $run->run_id, 't1', 'threshold_breach', 'analyst-1'
        );
        $this->assertFalse($audit->destructive_action);
    }

    public function test_rollback_audit_isolation_preserved_always_true(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $audit = $this->service->recordRollbackAudit(
            $run->run_id, 't1', 'operator_request', 'analyst-1'
        );
        $this->assertTrue($audit->isolation_preserved);
    }

    public function test_rollback_audit_requires_approval_by_default(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $audit = $this->service->recordRollbackAudit(
            $run->run_id, 't1', 'health_fail', 'analyst-1'
        );
        $this->assertFalse($audit->rollback_approved);
        $this->assertNull($audit->approved_by);
        $this->assertEquals('pending_approval', $audit->status);
    }

    public function test_rollback_audit_rejects_invalid_trigger(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRollbackAudit($run->run_id, 't1', 'unknown_trigger', 'analyst-1');
    }

    public function test_rollback_audit_writes_audit_record(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->service->recordRollbackAudit($run->run_id, 't1', 'drift_critical', 'analyst-1');
        $this->assertDatabaseHas('pilot_execution_audit', [
            'run_id'     => $run->run_id,
            'event_type' => 'rollback',
            'outcome'    => 'pending',
        ]);
    }

    // =========================================================================
    // Pilot health scoring
    // =========================================================================

    public function test_score_pilot_health_returns_advisory_flag(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $score = $this->service->scorePilotHealth($run);
        $this->assertTrue($score['is_advisory']);
    }

    public function test_score_pilot_health_returns_false_without_checkpoint(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $score = $this->service->scorePilotHealth($run);
        $this->assertFalse($score['health_ok']);
    }

    public function test_score_pilot_health_returns_true_with_good_checkpoint(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->service->recordHealthCheckpoint($run->run_id, 't1', '24h', [
            'telemetry_continuity_pct'    => 0.97,
            'replay_recovery_success_pct' => 0.96,
            'endpoint_stability_pct'      => 0.95,
            'tenant_isolation_pass_rate'  => 1.0,
            'false_positive_ratio'        => 0.02,
            'drift_stability_pct'         => 0.98,
        ]);
        $score = $this->service->scorePilotHealth($run);
        $this->assertTrue($score['health_ok']);
    }

    // =========================================================================
    // Append-only enforcement
    // =========================================================================

    public function test_live_pilot_run_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $model = LivePilotRun::where('run_id', $run->run_id)->first();
        $this->expectException(LogicException::class);
        $model->status = 'completed';
        $model->save();
    }

    public function test_pilot_endpoint_enrollment_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $e = $this->service->enrollEndpoint($run->run_id, 't1', 'ep-1', 'host-1');
        $model = PilotEndpointEnrollment::where('enrollment_id', $e->enrollment_id)->first();
        $this->expectException(LogicException::class);
        $model->status = 'withdrawn';
        $model->save();
    }

    public function test_pilot_health_checkpoint_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordHealthCheckpoint($run->run_id, 't1', '24h', []);
        $model = PilotHealthCheckpoint::where('checkpoint_id', $cp->checkpoint_id)->first();
        $this->expectException(LogicException::class);
        $model->health_ok = true;
        $model->save();
    }

    public function test_pilot_operational_review_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $r = $this->service->recordOperationalReview($run->run_id, 't1', 'daily', 'a1', 'acknowledged');
        $model = PilotOperationalReview::where('review_id', $r->review_id)->first();
        $this->expectException(LogicException::class);
        $model->verdict = 'closed';
        $model->save();
    }

    public function test_pilot_drift_review_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $d = $this->service->recordDriftReview($run->run_id, 't1', 'memory', 0.01, 'low', 'stable', 'a1');
        $model = PilotDriftReview::where('drift_review_id', $d->drift_review_id)->first();
        $this->expectException(LogicException::class);
        $model->verdict = 'escalated';
        $model->save();
    }

    public function test_pilot_rollback_audit_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $rb = $this->service->recordRollbackAudit($run->run_id, 't1', 'threshold_breach', 'a1');
        $model = PilotRollbackAudit::where('rollback_id', $rb->rollback_id)->first();
        $this->expectException(LogicException::class);
        $model->status = 'approved';
        $model->save();
    }

    public function test_live_telemetry_validation_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $v = $this->service->recordTelemetryValidation($run->run_id, 't1', []);
        $model = LiveTelemetryValidation::where('validation_id', $v->validation_id)->first();
        $this->expectException(LogicException::class);
        $model->validation_passed = true;
        $model->save();
    }

    public function test_production_observation_checkpoint_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $cp = $this->service->recordObservationCheckpoint($run->run_id, 't1', '24h', []);
        $model = ProductionObservationCheckpoint::where('checkpoint_id', $cp->checkpoint_id)->first();
        $this->expectException(LogicException::class);
        $model->criteria_met = true;
        $model->save();
    }

    public function test_pilot_execution_audit_is_append_only(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $model = PilotExecutionAudit::where('run_id', $run->run_id)->first();
        $this->expectException(LogicException::class);
        $model->outcome = 'failure';
        $model->save();
    }

    // =========================================================================
    // Telemetry continuity threshold constants
    // =========================================================================

    public function test_min_telemetry_continuity_is_95_pct(): void
    {
        $this->assertEquals(0.95, PilotExecutionService::MIN_TELEMETRY_CONTINUITY_PCT);
    }

    public function test_min_replay_recovery_is_95_pct(): void
    {
        $this->assertEquals(0.95, PilotExecutionService::MIN_REPLAY_RECOVERY_SUCCESS_PCT);
    }

    public function test_min_endpoint_stability_is_90_pct(): void
    {
        $this->assertEquals(0.90, PilotExecutionService::MIN_ENDPOINT_STABILITY_PCT);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('active_pilots', $stats);
        $this->assertArrayHasKey('total_enrollments', $stats);
        $this->assertArrayHasKey('health_ok', $stats);
        $this->assertArrayHasKey('drift_escalated', $stats);
        $this->assertArrayHasKey('rollback_pending', $stats);
        $this->assertArrayHasKey('checkpoints_criteria_met', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(177, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_live_pilot_runs_domain_is_supported(): void
    {
        $this->assertContains('live_pilot_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pilot_health_checkpoints_domain_is_supported(): void
    {
        $this->assertContains('pilot_health_checkpoints', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_pilot_operational_reviews_domain_is_supported(): void
    {
        $this->assertContains('pilot_operational_reviews', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_live_telemetry_validations_domain_is_supported(): void
    {
        $this->assertContains('live_telemetry_validations', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_production_observation_checkpoints_domain_is_supported(): void
    {
        $this->assertContains('production_observation_checkpoints', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_pilot_execution_dashboard_requires_auth(): void
    {
        $this->get(route('pilot-execution.dashboard'))->assertRedirect();
    }

    public function test_pilot_execution_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('pilot-execution.dashboard'))->assertOk();
    }

    public function test_pilot_execution_enrollment_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('pilot-execution.enrollment'))->assertOk();
    }

    public function test_pilot_execution_telemetry_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('pilot-execution.telemetry'))->assertOk();
    }

    public function test_pilot_execution_health_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('pilot-execution.health'))->assertOk();
    }

    public function test_pilot_execution_rollback_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('pilot-execution.rollback'))->assertOk();
    }

    // =========================================================================
    // Observation window types
    // =========================================================================

    public function test_supported_window_types_are_24h_48h_72h(): void
    {
        $this->assertEquals(['24h', '48h', '72h'], ProductionObservationCheckpoint::WINDOW_TYPES);
    }

    // =========================================================================
    // Drift reconstruction
    // =========================================================================

    public function test_drift_review_preserves_snapshot_for_reconstruction(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $snapshot = ['queue_lag_before' => 100, 'queue_lag_after' => 5000, 'worker_restarts' => 2];
        $dr = $this->service->recordDriftReview($run->run_id, 't1', 'queue', 50.0, 'high', 'escalated', 'analyst-1', false, $snapshot);
        $this->assertEquals($snapshot, $dr->snapshot);
    }

    // =========================================================================
    // Tenant isolation during live pilot
    // =========================================================================

    public function test_isolation_preserved_on_rollback_audit(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $audit = $this->service->recordRollbackAudit($run->run_id, 't1', 'threshold_breach', 'analyst-1');
        $this->assertTrue($audit->isolation_preserved);
    }

    // =========================================================================
    // Pilot metric consistency
    // =========================================================================

    public function test_all_pilot_models_have_is_advisory_set_to_true(): void
    {
        $run = $this->service->activatePilot('t1', 'Pilot', 10, 'admin');
        $this->service->enrollEndpoint($run->run_id, 't1', 'ep-1', 'host-1');
        $this->service->recordHealthCheckpoint($run->run_id, 't1', 'manual', []);
        $this->service->recordTelemetryValidation($run->run_id, 't1', []);
        $this->service->recordObservationCheckpoint($run->run_id, 't1', '48h', []);
        $this->service->recordOperationalReview($run->run_id, 't1', 'daily', 'a1', 'acknowledged');
        $this->service->recordDriftReview($run->run_id, 't1', 'schema', 0.01, 'low', 'stable', 'a1');
        $this->service->recordRollbackAudit($run->run_id, 't1', 'operator_request', 'a1');

        $this->assertEquals(1, PilotEndpointEnrollment::where('is_advisory', true)->count());
        $this->assertEquals(1, PilotHealthCheckpoint::where('is_advisory', true)->count());
        $this->assertEquals(1, LiveTelemetryValidation::where('is_advisory', true)->count());
        $this->assertEquals(1, ProductionObservationCheckpoint::where('is_advisory', true)->count());
        $this->assertEquals(1, PilotOperationalReview::where('is_advisory', true)->count());
        $this->assertEquals(1, PilotDriftReview::where('is_advisory', true)->count());
        $this->assertEquals(1, PilotRollbackAudit::where('is_advisory', true)->count());
    }
}

