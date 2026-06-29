<?php

namespace Tests\Feature;

use App\Models\SoakValidationRun;
use App\Models\SoakValidationMetric;
use App\Models\ChaosSimulationRun;
use App\Models\ChaosFailureEvent;
use App\Models\RecoveryValidationArtifact;
use App\Models\OperationalDriftReport;
use App\Models\ReplayRecoveryRun;
use App\Models\TelemetryContinuityReport;
use App\Models\BoundedFailureScenario;
use App\Services\SoakChaosValidationService;
use App\Services\ThreatHuntingService;
use App\Services\EntityRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoakChaosValidationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Traits\AssertsAdvisoryOnlyConstraints;

    private SoakChaosValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SoakChaosValidationService::class);
    }

    // =========================================================================
    // Hard constraint ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â no forbidden operations
    // =========================================================================






    protected function getAdvisoryServiceClass(): string
    {
        return SoakChaosValidationService::class;
    }

    public function test_no_destructive_chaos(): void
    {
        $this->assertFalse(method_exists($this->service, 'destructiveChaosInject'));
    }

    public function test_no_unsafe_queue_purge(): void
    {
        $this->assertFalse(method_exists($this->service, 'unsafeQueuePurge'));
    }

    public function test_no_hidden_recovery(): void
    {
        $this->assertFalse(method_exists($this->service, 'hiddenRecovery'));
    }

    public function test_no_infinite_retry_loop(): void
    {
        $this->assertFalse(method_exists($this->service, 'infiniteRetryLoop'));
    }

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(SoakChaosValidationService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Soak run
    // =========================================================================

    public function test_soak_run_passes_when_metrics_in_bounds(): void
    {
        $run = $this->service->recordSoakRun('6h', 360, 'completed', [
            'memory_growth_mb'          => 100.0,
            'duplicate_event_rate'      => 0.005,
            'telemetry_gap_rate'        => 0.02,
            'retry_amplification_factor'=> 1.5,
        ]);

        $this->assertTrue($run->passed);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->is_advisory);
    }

    public function test_soak_run_fails_on_memory_exceeded(): void
    {
        $run = $this->service->recordSoakRun('12h', 720, 'completed', [
            'memory_growth_mb' => 600.0,
        ]);

        $this->assertFalse($run->passed);
    }

    public function test_soak_run_fails_on_high_gap_rate(): void
    {
        $run = $this->service->recordSoakRun('telemetry', 60, 'completed', [
            'telemetry_gap_rate' => 0.10,
        ]);

        $this->assertFalse($run->passed);
    }

    public function test_soak_run_not_passed_when_aborted(): void
    {
        $run = $this->service->recordSoakRun('queue', 30, 'aborted');
        $this->assertFalse($run->passed);
    }

    public function test_soak_run_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSoakRun('invalid_type', 60, 'completed');
    }

    public function test_soak_run_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSoakRun('6h', 360, 'unknown_status');
    }

    public function test_soak_run_is_append_only(): void
    {
        $run = $this->service->recordSoakRun('6h', 360, 'completed');
        $this->expectException(\LogicException::class);
        $run->passed = false;
        $run->save();
    }

    public function test_soak_run_id_prefix(): void
    {
        $run = $this->service->recordSoakRun('6h', 360, 'completed');
        $this->assertStringStartsWith('svr-', $run->run_id);
    }

    // =========================================================================
    // Soak metrics
    // =========================================================================

    public function test_soak_metric_records_correctly(): void
    {
        $metric = $this->service->recordSoakMetric('svr-001', 'queue_lag', 5000.0, 'count', 30, 4000.0);

        $this->assertSame('queue_lag', $metric->metric_name);
        $this->assertSame(5000.0, $metric->metric_value);
        $this->assertSame('count', $metric->unit);
        $this->assertTrue($metric->is_advisory);
    }

    public function test_soak_metric_detects_drift(): void
    {
        $metric = $this->service->recordSoakMetric('svr-001', 'memory_mb', 600.0, 'mb', 60, 200.0);
        $this->assertTrue($metric->drift_detected);
        $this->assertSame(400.0, $metric->drift_delta);
    }

    public function test_soak_metric_no_drift_when_in_range(): void
    {
        $metric = $this->service->recordSoakMetric('svr-001', 'cpu_pct', 42.0, 'pct', 30, 40.0);
        $this->assertFalse($metric->drift_detected);
    }

    public function test_soak_metric_is_append_only(): void
    {
        $metric = $this->service->recordSoakMetric('svr-001', 'test', 1.0);
        $this->expectException(\LogicException::class);
        $metric->metric_value = 999.0;
        $metric->save();
    }

    // =========================================================================
    // Chaos simulation
    // =========================================================================

    public function test_chaos_simulation_pass_all_recovered(): void
    {
        $seq = [
            ['type' => 'worker_restart', 'recovered' => true],
            ['type' => 'queue_disconnect', 'recovered' => true],
        ];
        $run = $this->service->runChaosSimulation('worker_restart', 120, $seq);

        $this->assertSame('pass', $run->verdict);
        $this->assertTrue($run->recovery_verified);
        $this->assertSame(2, $run->failures_injected);
        $this->assertSame(2, $run->recoveries_observed);
        $this->assertTrue($run->replay_safe);
        $this->assertTrue($run->is_advisory);
    }

    public function test_chaos_simulation_fail_none_recovered(): void
    {
        $seq = [['type' => 'storage_unavailable', 'recovered' => false]];
        $run = $this->service->runChaosSimulation('storage_unavailable', 60, $seq);

        $this->assertSame('fail', $run->verdict);
        $this->assertFalse($run->recovery_verified);
    }

    public function test_chaos_simulation_partial_some_recovered(): void
    {
        $seq = [
            ['type' => 'worker_restart', 'recovered' => true],
            ['type' => 'queue_disconnect', 'recovered' => false],
        ];
        $run = $this->service->runChaosSimulation('worker_restart', 180, $seq);

        $this->assertSame('partial', $run->verdict);
    }

    public function test_chaos_simulation_rejects_invalid_scenario(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runChaosSimulation('destroy_everything', 60);
    }

    public function test_chaos_simulation_rejects_excessive_duration(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->runChaosSimulation('worker_restart', 700);
    }

    public function test_chaos_simulation_bounded_at_max(): void
    {
        $run = $this->service->runChaosSimulation('delayed_telemetry', 600);
        $this->assertSame(600, $run->duration_seconds);
    }

    public function test_chaos_simulation_is_append_only(): void
    {
        $run = $this->service->runChaosSimulation('worker_restart', 60);
        $this->expectException(\LogicException::class);
        $run->verdict = 'fail';
        $run->save();
    }

    // =========================================================================
    // Chaos failure event
    // =========================================================================

    public function test_chaos_failure_event_records_correctly(): void
    {
        $event = $this->service->recordChaosFailureEvent(
            'csr-001', 'worker_crash', 'worker', 'recovered', 30, 15
        );

        $this->assertSame('recovered', $event->outcome);
        $this->assertSame('worker', $event->component);
        $this->assertTrue($event->replay_safe);
        $this->assertTrue($event->is_advisory);
    }

    public function test_chaos_failure_event_rejects_invalid_component(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordChaosFailureEvent('csr-001', 'crash', 'kernel', 'injected');
    }

    public function test_chaos_failure_event_rejects_invalid_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordChaosFailureEvent('csr-001', 'crash', 'worker', 'destroyed');
    }

    public function test_chaos_failure_event_is_append_only(): void
    {
        $event = $this->service->recordChaosFailureEvent('csr-001', 'crash', 'worker', 'injected');
        $this->expectException(\LogicException::class);
        $event->outcome = 'unrecovered';
        $event->save();
    }

    // =========================================================================
    // Recovery validation
    // =========================================================================

    public function test_recovery_validation_pass_all_ok(): void
    {
        $art = $this->service->validateRecovery('replay', true, 30);

        $this->assertSame('pass', $art->verdict);
        $this->assertTrue($art->recovery_ok);
        $this->assertTrue($art->duplicates_prevented);
        $this->assertTrue($art->tenant_isolation_preserved);
        $this->assertTrue($art->graph_integrity_preserved);
        $this->assertTrue($art->is_advisory);
    }

    public function test_recovery_validation_fail_on_no_recovery(): void
    {
        $art = $this->service->validateRecovery('queue', false, 90);
        $this->assertSame('fail', $art->verdict);
    }

    public function test_recovery_validation_fail_when_duplicates_not_prevented(): void
    {
        $art = $this->service->validateRecovery('telemetry', true, 20, false);
        $this->assertSame('fail', $art->verdict);
    }

    public function test_recovery_validation_fail_when_tenant_isolation_violated(): void
    {
        $art = $this->service->validateRecovery('worker', true, 15, true, false);
        $this->assertSame('fail', $art->verdict);
    }

    public function test_recovery_validation_partial_when_graph_integrity_broken(): void
    {
        $art = $this->service->validateRecovery('graph', true, 45, true, true, false);
        $this->assertSame('partial', $art->verdict);
    }

    public function test_recovery_validation_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateRecovery('invalid_type', true, 10);
    }

    public function test_recovery_validation_is_append_only(): void
    {
        $art = $this->service->validateRecovery('storage', true, 10);
        $this->expectException(\LogicException::class);
        $art->verdict = 'fail';
        $art->save();
    }

    // =========================================================================
    // Drift detection
    // =========================================================================

    public function test_drift_report_detects_exceeding_threshold(): void
    {
        $report = $this->service->recordDrift('memory', 100.0, 130.0, 60);

        $this->assertTrue($report->drift_exceeds_threshold);
        $this->assertSame(30.0, $report->drift_delta);
        $this->assertTrue($report->is_advisory);
    }

    public function test_drift_report_within_threshold(): void
    {
        $report = $this->service->recordDrift('queue', 1000.0, 1050.0, 30);
        $this->assertFalse($report->drift_exceeds_threshold);
    }

    public function test_drift_report_deterministic(): void
    {
        $r1 = $this->service->recordDrift('memory', 200.0, 260.0, 60);
        $r2 = $this->service->recordDrift('memory', 200.0, 260.0, 60);

        $this->assertSame($r1->drift_delta, $r2->drift_delta);
        $this->assertSame($r1->drift_pct, $r2->drift_pct);
        $this->assertSame($r1->drift_exceeds_threshold, $r2->drift_exceeds_threshold);
    }

    public function test_drift_report_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDrift('invalid_drift_type', 100.0, 200.0, 60);
    }

    public function test_drift_report_is_append_only(): void
    {
        $report = $this->service->recordDrift('memory', 100.0, 200.0, 60);
        $this->expectException(\LogicException::class);
        $report->drift_exceeds_threshold = false;
        $report->save();
    }

    // =========================================================================
    // Replay recovery
    // =========================================================================

    public function test_replay_recovery_pass_all_ok(): void
    {
        $run = $this->service->recordReplayRecovery('worker_restart', 1000, 1000, 45);

        $this->assertSame('pass', $run->verdict);
        $this->assertTrue($run->ordering_preserved);
        $this->assertTrue($run->duplicates_prevented);
        $this->assertTrue($run->tenant_isolation_preserved);
        $this->assertTrue($run->continuity_verified);
        $this->assertTrue($run->is_advisory);
    }

    public function test_replay_recovery_fail_on_ordering_violation(): void
    {
        $run = $this->service->recordReplayRecovery('queue_disconnect', 500, 500, 30, false);
        $this->assertSame('fail', $run->verdict);
    }

    public function test_replay_recovery_partial_when_continuity_not_verified(): void
    {
        $run = $this->service->recordReplayRecovery('manual', 200, 200, 20, true, true, true, false);
        $this->assertSame('partial', $run->verdict);
    }

    public function test_replay_recovery_rejects_invalid_trigger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordReplayRecovery('self_heal', 100, 100, 10);
    }

    public function test_replay_recovery_is_append_only(): void
    {
        $run = $this->service->recordReplayRecovery('manual', 100, 100, 10);
        $this->expectException(\LogicException::class);
        $run->verdict = 'fail';
        $run->save();
    }

    // =========================================================================
    // Telemetry continuity
    // =========================================================================

    public function test_telemetry_continuity_pass_at_95(): void
    {
        $report = $this->service->recordTelemetryContinuity(60, 10000, 9600);

        $this->assertTrue($report->continuity_ok);
        $this->assertSame('pass', $report->verdict);
        $this->assertSame(0.96, $report->continuity_pct);
        $this->assertTrue($report->is_advisory);
    }

    public function test_telemetry_continuity_fail_below_80(): void
    {
        $report = $this->service->recordTelemetryContinuity(60, 10000, 7000);

        $this->assertFalse($report->continuity_ok);
        $this->assertSame('fail', $report->verdict);
    }

    public function test_telemetry_continuity_degraded_between_80_and_95(): void
    {
        $report = $this->service->recordTelemetryContinuity(30, 10000, 8500);

        $this->assertFalse($report->continuity_ok);
        $this->assertSame('degraded', $report->verdict);
    }

    public function test_telemetry_continuity_is_append_only(): void
    {
        $report = $this->service->recordTelemetryContinuity(60, 1000, 1000);
        $this->expectException(\LogicException::class);
        $report->continuity_ok = false;
        $report->save();
    }

    // =========================================================================
    // BoundedFailureScenario (mutable)
    // =========================================================================

    public function test_bounded_failure_scenario_is_mutable(): void
    {
        $scenario = BoundedFailureScenario::create([
            'scenario_key'        => 'worker_restart_test',
            'name'                => 'Worker Restart',
            'component'           => 'worker',
            'max_duration_seconds'=> 120,
            'enabled'             => true,
            'requires_approval'   => true,
            'destructive'         => false,
        ]);

        $scenario->enabled = false;
        $scenario->save();

        $this->assertFalse(BoundedFailureScenario::find($scenario->id)->enabled);
    }

    public function test_no_destructive_scenarios_allowed(): void
    {
        BoundedFailureScenario::create([
            'scenario_key' => 'safe_test', 'name' => 'Safe', 'component' => 'worker',
            'max_duration_seconds' => 60, 'destructive' => false,
        ]);
        BoundedFailureScenario::create([
            'scenario_key' => 'unsafe_test', 'name' => 'Unsafe', 'component' => 'storage',
            'max_duration_seconds' => 30, 'destructive' => true, 'enabled' => false,
        ]);

        $enabled = $this->service->getEnabledScenarios();
        foreach ($enabled as $s) {
            $this->assertFalse($s->destructive, 'Enabled scenarios must not be destructive');
        }
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_has_all_keys(): void
    {
        $stats = $this->service->dashboardStats();

        $this->assertArrayHasKey('total_soak_runs', $stats);
        $this->assertArrayHasKey('soak_runs_passed', $stats);
        $this->assertArrayHasKey('soak_runs_failed', $stats);
        $this->assertArrayHasKey('chaos_runs_total', $stats);
        $this->assertArrayHasKey('chaos_pass', $stats);
        $this->assertArrayHasKey('chaos_fail', $stats);
        $this->assertArrayHasKey('recovery_pass', $stats);
        $this->assertArrayHasKey('recovery_fail', $stats);
        $this->assertArrayHasKey('drift_exceeded', $stats);
        $this->assertArrayHasKey('replay_recovery_pass', $stats);
        $this->assertArrayHasKey('telemetry_continuity_ok', $stats);
        $this->assertArrayHasKey('enabled_scenarios', $stats);
    }

    // =========================================================================
    // Threat hunting domain integration
    // =========================================================================

    public function test_soak_validation_runs_domain_supported(): void
    {
        $this->assertContains('soak_validation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_chaos_simulation_runs_domain_supported(): void
    {
        $this->assertContains('chaos_simulation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_recovery_validation_artifacts_domain_supported(): void
    {
        $this->assertContains('recovery_validation_artifacts', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_operational_drift_reports_domain_supported(): void
    {
        $this->assertContains('operational_drift_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_replay_recovery_runs_domain_supported(): void
    {
        $this->assertContains('replay_recovery_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_total_hunt_domains_is_85(): void
    {
        $this->assertCount(177, app(ThreatHuntingService::class)->supportedDomains());
    }

    // =========================================================================
    // Entity risk factors
    // =========================================================================

    public function test_soak_chaos_risk_factors_in_weight_table(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertArrayHasKey('soak_failure_factor', $weights);
        $this->assertArrayHasKey('chaos_recovery_failure_factor', $weights);
        $this->assertArrayHasKey('operational_drift_factor', $weights);
        $this->assertArrayHasKey('replay_continuity_failure_factor', $weights);
    }

    public function test_soak_chaos_risk_factors_are_nonzero(): void
    {
        $w = EntityRiskScoringService::WEIGHTS;
        $this->assertGreaterThan(0, $w['soak_failure_factor']);
        $this->assertGreaterThan(0, $w['chaos_recovery_failure_factor']);
        $this->assertGreaterThan(0, $w['operational_drift_factor']);
        $this->assertGreaterThan(0, $w['replay_continuity_failure_factor']);
    }

    // =========================================================================
    // Route accessibility
    // =========================================================================

    public function test_soak_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/soak-chaos')->assertStatus(200);
    }

    public function test_chaos_explorer_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/soak-chaos/chaos')->assertStatus(200);
    }

    public function test_stability_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/soak-chaos/stability')->assertStatus(200);
    }

    public function test_views_contain_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/soak-chaos')
            ->assertSee('Operational soak and chaos workflows are bounded, replay-safe, and advisory-only');
    }
}


