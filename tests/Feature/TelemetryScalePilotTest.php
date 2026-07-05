<?php

namespace Tests\Feature;

use App\Models\TelemetryScaleValidationRun;
use App\Models\TelemetryScaleMetric;
use App\Models\ReplayScaleRecoveryRun;
use App\Models\AnalystLoadStabilityReport;
use App\Models\InfrastructurePressureRun;
use App\Models\TelemetryGrowthDriftReport;
use App\Models\ScaleObservationWindow;
use App\Models\QueueRecoveryValidationReport;
use App\Models\ScalePilotAudit;
use App\Services\TelemetryScalePilotService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class TelemetryScalePilotTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private TelemetryScalePilotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TelemetryScalePilotService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return TelemetryScalePilotService::class;
    }

    // =========================================================================
    // Hard constraint: no forbidden methods
    // =========================================================================

    public function test_no_unrestricted_scaling_method(): void
    {
        $this->assertFalse(method_exists(TelemetryScalePilotService::class, 'scaleUnrestricted'));
    }

    public function test_no_hidden_throttling_method(): void
    {
        $this->assertFalse(method_exists(TelemetryScalePilotService::class, 'hiddenlyThrottle'));
    }

    public function test_no_destructive_infrastructure_method(): void
    {
        $this->assertFalse(method_exists(TelemetryScalePilotService::class, 'destroyInfrastructure'));
    }

    public function test_no_unsafe_replay_amplification_method(): void
    {
        $this->assertFalse(method_exists(TelemetryScalePilotService::class, 'amplifyReplayUnsafe'));
    }

    public function test_no_uncontrolled_onboarding_method(): void
    {
        $this->assertFalse(method_exists(TelemetryScalePilotService::class, 'onboardUnbounded'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(TelemetryScalePilotService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Bounded endpoint enforcement
    // =========================================================================

    public function test_scale_validation_below_min_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->startScaleValidation('t1', TelemetryScalePilotService::MIN_ENDPOINTS - 1);
    }

    public function test_scale_validation_above_max_throws(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->startScaleValidation('t1', TelemetryScalePilotService::MAX_ENDPOINTS + 1);
    }

    public function test_scale_validation_at_min_boundary_succeeds(): void
    {
        $run = $this->service->startScaleValidation('t1', TelemetryScalePilotService::MIN_ENDPOINTS);
        $this->assertEquals(TelemetryScalePilotService::MIN_ENDPOINTS, $run->endpoint_count);
        $this->assertEquals('scale_50', $run->scale_profile);
    }

    public function test_scale_validation_at_max_boundary_succeeds(): void
    {
        $run = $this->service->startScaleValidation('t1', TelemetryScalePilotService::MAX_ENDPOINTS);
        $this->assertEquals(TelemetryScalePilotService::MAX_ENDPOINTS, $run->endpoint_count);
        $this->assertEquals('scale_100', $run->scale_profile);
    }

    public function test_scale_profile_75_for_midrange(): void
    {
        $run = $this->service->startScaleValidation('t1', 75);
        $this->assertEquals('scale_75', $run->scale_profile);
    }

    public function test_scale_validation_stores_advisory_flag(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->assertTrue($run->is_advisory);
    }

    public function test_scale_validation_writes_audit_record(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->assertDatabaseHas('scale_pilot_audit', [
            'run_id'     => $run->run_id,
            'event_type' => 'run_started',
            'outcome'    => 'success',
        ]);
    }

    // =========================================================================
    // Complete scale validation
    // =========================================================================

    public function test_complete_scale_validation_passes_with_good_metrics(): void
    {
        $run = $this->service->completeScaleValidation('run-001', 't1', [
            'endpoint_count'           => 50,
            'scale_profile'            => 'scale_50',
            'telemetry_continuity_pct' => 0.97,
            'duplicate_rate'           => 0.005,
            'queue_lag'                => 1000,
            'storage_pressure_pct'     => 0.50,
        ]);
        $this->assertTrue($run->validation_passed);
        $this->assertEquals('completed', $run->status);
    }

    public function test_complete_scale_validation_fails_below_continuity(): void
    {
        $run = $this->service->completeScaleValidation('run-001', 't1', [
            'endpoint_count'           => 50,
            'scale_profile'            => 'scale_50',
            'telemetry_continuity_pct' => 0.80,
        ]);
        $this->assertFalse($run->validation_passed);
    }

    public function test_scale_validation_run_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $model = TelemetryScaleValidationRun::where('run_id', $run->run_id)->first();
        $this->expectException(LogicException::class);
        $model->validation_passed = true;
        $model->save();
    }

    // =========================================================================
    // Scale metrics
    // =========================================================================

    public function test_record_scale_metric_creates_record(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $m = $this->service->recordScaleMetric($run->run_id, 't1', 'queue_lag', 500.0, 200.0);
        $this->assertEquals('queue_lag', $m->metric_type);
        $this->assertTrue($m->within_bounds);
        $this->assertTrue($m->is_advisory);
    }

    public function test_record_scale_metric_rejects_invalid_type(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordScaleMetric($run->run_id, 't1', 'unknown_metric', 100.0);
    }

    public function test_queue_lag_above_max_is_out_of_bounds(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $m = $this->service->recordScaleMetric(
            $run->run_id, 't1', 'queue_lag', TelemetryScalePilotService::MAX_QUEUE_LAG + 1
        );
        $this->assertFalse($m->within_bounds);
    }

    public function test_drift_pct_is_calculated(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $m = $this->service->recordScaleMetric($run->run_id, 't1', 'throughput', 1100.0, 1000.0);
        $this->assertEqualsWithDelta(0.10, $m->drift_pct, 0.001);
    }

    public function test_scale_metric_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $m = $this->service->recordScaleMetric($run->run_id, 't1', 'throughput', 500.0);
        $model = TelemetryScaleMetric::where('metric_id', $m->metric_id)->first();
        $this->expectException(LogicException::class);
        $model->value = 999.0;
        $model->save();
    }

    // =========================================================================
    // Replay scale recovery
    // =========================================================================

    public function test_replay_recovery_successful_when_backlog_reduced(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordReplayRecovery($run->run_id, 't1', 1000, 100, 30.0, 1.5);
        $this->assertTrue($r->recovery_successful);
        $this->assertTrue($r->amplification_bounded);
        $this->assertTrue($r->duplicate_protected);
    }

    public function test_replay_recovery_not_successful_when_amplification_exceeds_max(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordReplayRecovery(
            $run->run_id, 't1', 1000, 100, 30.0,
            TelemetryScalePilotService::MAX_REPLAY_AMPLIFICATION + 0.5
        );
        $this->assertFalse($r->amplification_bounded);
        $this->assertFalse($r->recovery_successful);
    }

    public function test_replay_recovery_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordReplayRecovery($run->run_id, 't1', 500, 50, 10.0, 1.0);
        $model = ReplayScaleRecoveryRun::where('recovery_id', $r->recovery_id)->first();
        $this->expectException(LogicException::class);
        $model->recovery_successful = false;
        $model->save();
    }

    // =========================================================================
    // Analyst load stability
    // =========================================================================

    public function test_analyst_load_stable_with_normal_metrics(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordAnalystLoadStability($run->run_id, 't1', [
            'alert_throughput_per_hour'          => 50.0,
            'avg_acknowledgment_latency_seconds' => 120.0,
            'escalation_backlog'                 => 5,
            'fatigue_detected'                   => false,
            'queue_growth_rate'                  => 0.5,
        ]);
        $this->assertTrue($r->workload_stable);
        $this->assertTrue($r->is_advisory);
    }

    public function test_analyst_load_unstable_with_fatigue(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordAnalystLoadStability($run->run_id, 't1', [
            'fatigue_detected' => true,
        ]);
        $this->assertFalse($r->workload_stable);
    }

    public function test_analyst_load_stability_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordAnalystLoadStability($run->run_id, 't1', []);
        $model = AnalystLoadStabilityReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->workload_stable = false;
        $model->save();
    }

    // =========================================================================
    // Infrastructure pressure
    // =========================================================================

    public function test_infrastructure_pressure_within_bounds_with_good_metrics(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordInfrastructurePressure($run->run_id, 't1', [
            'cpu_usage_pct'       => 0.60,
            'memory_growth_mb'    => 100.0,
            'storage_pressure_pct'=> 0.50,
            'query_latency_ms'    => 100.0,
        ]);
        $this->assertTrue($r->pressure_within_bounds);
        $this->assertTrue($r->is_advisory);
    }

    public function test_infrastructure_pressure_out_of_bounds_high_cpu(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordInfrastructurePressure($run->run_id, 't1', [
            'cpu_usage_pct' => TelemetryScalePilotService::MAX_CPU_PCT + 0.05,
        ]);
        $this->assertFalse($r->pressure_within_bounds);
    }

    public function test_infrastructure_pressure_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordInfrastructurePressure($run->run_id, 't1', []);
        $model = InfrastructurePressureRun::where('pressure_id', $r->pressure_id)->first();
        $this->expectException(LogicException::class);
        $model->pressure_within_bounds = false;
        $model->save();
    }

    // =========================================================================
    // Drift reports
    // =========================================================================

    public function test_drift_report_creates_record_with_correct_severity(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordDriftReport($run->run_id, 't1', 'queue_lag', 1500.0, 1000.0);
        $this->assertEquals('queue_lag', $r->drift_dimension);
        $this->assertEqualsWithDelta(0.50, $r->drift_magnitude, 0.001);
        $this->assertEquals('critical', $r->drift_severity);
        $this->assertFalse($r->drift_bounded);
    }

    public function test_drift_report_low_severity_for_small_drift(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordDriftReport($run->run_id, 't1', 'telemetry_growth', 105.0, 100.0);
        $this->assertEquals('low', $r->drift_severity);
        $this->assertTrue($r->drift_bounded);
    }

    public function test_drift_report_rejects_invalid_dimension(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDriftReport($run->run_id, 't1', 'unknown_dimension', 100.0, 80.0);
    }

    public function test_drift_report_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordDriftReport($run->run_id, 't1', 'storage_growth', 110.0, 100.0);
        $model = TelemetryGrowthDriftReport::where('drift_id', $r->drift_id)->first();
        $this->expectException(LogicException::class);
        $model->drift_severity = 'critical';
        $model->save();
    }

    // =========================================================================
    // Observation windows (mutable)
    // =========================================================================

    public function test_observation_window_24h_opens(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $w = $this->service->openObservationWindow($run->run_id, 't1', 24);
        $this->assertEquals(24, $w->window_hours);
        $this->assertEquals('active', $w->status);
        $this->assertTrue($w->bounded_window);
    }

    public function test_observation_window_48h_and_72h_valid(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $w48 = $this->service->openObservationWindow($run->run_id, 't1', 48);
        $w72 = $this->service->openObservationWindow($run->run_id, 't1', 72);
        $this->assertEquals(48, $w48->window_hours);
        $this->assertEquals(72, $w72->window_hours);
    }

    public function test_observation_window_invalid_hours_throws(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->openObservationWindow($run->run_id, 't1', 96);
    }

    public function test_observation_window_is_mutable(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $w = $this->service->openObservationWindow($run->run_id, 't1', 24);
        $closed = $this->service->closeObservationWindow($w, [
            'telemetry_continuity_pct'    => 0.97,
            'replay_recovery_success_pct' => 0.96,
            'drift_stability_pct'         => 0.98,
        ]);
        $this->assertEquals('completed', $closed->status);
        $this->assertTrue($closed->criteria_met);
    }

    public function test_observation_window_criteria_not_met_below_threshold(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $w = $this->service->openObservationWindow($run->run_id, 't1', 24);
        $closed = $this->service->closeObservationWindow($w, [
            'telemetry_continuity_pct' => 0.80,
        ]);
        $this->assertFalse($closed->criteria_met);
    }

    // =========================================================================
    // Queue recovery validation
    // =========================================================================

    public function test_queue_recovery_successful(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordQueueRecovery($run->run_id, 't1', 5000, 200, 45.0, true);
        $this->assertTrue($r->recovery_successful);
        $this->assertTrue($r->duplicate_protected);
        $this->assertTrue($r->continuity_after_reconnect);
    }

    public function test_queue_recovery_fails_when_amplification_unsafe(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordQueueRecovery($run->run_id, 't1', 5000, 200, 45.0, false);
        $this->assertFalse($r->replay_amplification_safe);
        $this->assertFalse($r->recovery_successful);
    }

    public function test_queue_recovery_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $r = $this->service->recordQueueRecovery($run->run_id, 't1', 1000, 100, 10.0);
        $model = QueueRecoveryValidationReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->recovery_successful = false;
        $model->save();
    }

    // =========================================================================
    // Scale pilot audit
    // =========================================================================

    public function test_scale_pilot_audit_uses_explicit_table(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->assertDatabaseHas('scale_pilot_audit', ['run_id' => $run->run_id]);
    }

    public function test_scale_pilot_audit_is_append_only(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $model = ScalePilotAudit::where('run_id', $run->run_id)->first();
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
        $this->assertArrayHasKey('scale_runs', $stats);
        $this->assertArrayHasKey('passed_runs', $stats);
        $this->assertArrayHasKey('active_windows', $stats);
        $this->assertArrayHasKey('recovery_successful', $stats);
        $this->assertArrayHasKey('pressure_bounded', $stats);
        $this->assertArrayHasKey('drift_critical', $stats);
        $this->assertArrayHasKey('workload_stable', $stats);
        $this->assertArrayHasKey('queue_recovered', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(177, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_telemetry_scale_validation_runs_domain_supported(): void
    {
        $this->assertContains('telemetry_scale_validation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_replay_scale_recovery_runs_domain_supported(): void
    {
        $this->assertContains('replay_scale_recovery_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_analyst_load_stability_reports_domain_supported(): void
    {
        $this->assertContains('analyst_load_stability_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_infrastructure_pressure_runs_domain_supported(): void
    {
        $this->assertContains('infrastructure_pressure_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_telemetry_growth_drift_reports_domain_supported(): void
    {
        $this->assertContains('telemetry_growth_drift_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_scale_pilot_dashboard_requires_auth(): void
    {
        $this->get(route('scale-pilot.dashboard'))->assertRedirect();
    }

    public function test_scale_pilot_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('scale-pilot.dashboard'))->assertOk();
    }

    public function test_scale_pilot_continuity_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('scale-pilot.continuity'))->assertOk();
    }

    public function test_scale_pilot_drift_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('scale-pilot.drift'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('scale-pilot.dashboard'))
            ->assertSee('advisory-only');
    }

    // =========================================================================
    // SIM-LAYER-REALITY-GATE: simulated/computed labelling
    // =========================================================================

    public function test_scale_validation_run_is_labelled_simulated_computed(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $this->assertTrue($run->is_simulated);
        $this->assertSame('computed', $run->evidence_basis);
    }

    public function test_scale_metric_is_labelled_simulated_computed(): void
    {
        $run = $this->service->startScaleValidation('t1', 50);
        $m = $this->service->recordScaleMetric($run->run_id, 't1', 'queue_lag', 500.0, 200.0);
        $this->assertTrue($m->is_simulated);
        $this->assertSame('computed', $m->evidence_basis);
    }
}

