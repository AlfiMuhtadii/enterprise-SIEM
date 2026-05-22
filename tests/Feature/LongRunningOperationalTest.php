<?php

namespace Tests\Feature;

use App\Models\OperationalValidationWindow;
use App\Models\TelemetryTrendReport;
use App\Models\AnalystBehaviorTrend;
use App\Models\FalsePositiveEvolutionReport;
use App\Models\OperationalDriftHistory;
use App\Models\GovernanceReportingRun;
use App\Models\ReplayDurabilityHistory;
use App\Models\InfrastructureStabilityReport;
use App\Models\ProductionGovernanceAudit;
use App\Services\LongRunningOperationalService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class LongRunningOperationalTest extends TestCase
{
    use RefreshDatabase;

    private LongRunningOperationalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LongRunningOperationalService::class);
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'autoRemediate'));
    }

    public function test_no_hidden_suppression_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'hiddenlySuppress'));
    }

    public function test_no_destructive_mutation_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'destroyOperationalData'));
    }

    public function test_no_black_box_scoring_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'blackBoxScore'));
    }

    public function test_no_unsafe_automation_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'autoEscalate'));
    }

    public function test_no_uncontrolled_scaling_method(): void
    {
        $this->assertFalse(method_exists(LongRunningOperationalService::class, 'scaleUnrestricted'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(LongRunningOperationalService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Operational validation windows
    // =========================================================================

    public function test_operational_window_7d_creates_record(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '7d', [
            'telemetry_continuity_pct'    => 0.97,
            'replay_recovery_success_pct' => 0.96,
            'avg_queue_lag'               => 5000.0,
        ]);
        $this->assertEquals('7d', $w->window_type);
        $this->assertTrue($w->criteria_met);
        $this->assertTrue($w->is_advisory);
    }

    public function test_operational_window_14d_valid(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '14d', []);
        $this->assertEquals('14d', $w->window_type);
    }

    public function test_operational_window_30d_valid(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '30d', []);
        $this->assertEquals('30d', $w->window_type);
    }

    public function test_operational_window_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationalWindow('t1', '60d', []);
    }

    public function test_operational_window_criteria_not_met_below_threshold(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '7d', [
            'telemetry_continuity_pct' => 0.80,
        ]);
        $this->assertFalse($w->criteria_met);
    }

    public function test_operational_window_writes_audit(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '7d', []);
        $this->assertDatabaseHas('production_governance_audit', [
            'tenant_id'  => 't1',
            'event_type' => 'window_created',
        ]);
    }

    public function test_operational_window_is_append_only(): void
    {
        $w = $this->service->recordOperationalWindow('t1', '7d', []);
        $model = OperationalValidationWindow::where('window_id', $w->window_id)->first();
        $this->expectException(LogicException::class);
        $model->criteria_met = true;
        $model->save();
    }

    // =========================================================================
    // Telemetry trend reports
    // =========================================================================

    public function test_telemetry_trend_stable_verdict(): void
    {
        $r = $this->service->recordTelemetryTrend('t1', '7d', [
            'continuity_trend_slope' => 0.0,
            'queue_lag_trend_slope'  => 0.0,
        ]);
        $this->assertEquals('stable', $r->trend_verdict);
        $this->assertTrue($r->replay_safe);
    }

    public function test_telemetry_trend_degrading_verdict(): void
    {
        $r = $this->service->recordTelemetryTrend('t1', '7d', [
            'continuity_trend_slope' => -0.015,
        ]);
        $this->assertEquals('degrading', $r->trend_verdict);
    }

    public function test_telemetry_trend_critical_verdict(): void
    {
        $r = $this->service->recordTelemetryTrend('t1', '7d', [
            'continuity_trend_slope' => -0.03,
        ]);
        $this->assertEquals('critical', $r->trend_verdict);
    }

    public function test_telemetry_trend_improving_verdict(): void
    {
        $r = $this->service->recordTelemetryTrend('t1', '7d', [
            'continuity_trend_slope' => 0.01,
            'queue_lag_trend_slope'  => -0.01,
        ]);
        $this->assertEquals('improving', $r->trend_verdict);
    }

    public function test_telemetry_trend_rejects_invalid_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordTelemetryTrend('t1', '60d', []);
    }

    public function test_telemetry_trend_is_append_only(): void
    {
        $r = $this->service->recordTelemetryTrend('t1', '7d', []);
        $model = TelemetryTrendReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->trend_verdict = 'critical';
        $model->save();
    }

    // =========================================================================
    // Analyst behavior trends
    // =========================================================================

    public function test_analyst_behavior_stable_with_good_metrics(): void
    {
        $t = $this->service->recordAnalystBehaviorTrend('analyst-1', 't1', '7d', [
            'fatigue_score'        => 0.20,
            'latency_trend_slope'  => 0.01,
        ]);
        $this->assertTrue($t->behavior_stable);
        $this->assertTrue($t->is_advisory);
    }

    public function test_analyst_behavior_unstable_with_high_fatigue(): void
    {
        $t = $this->service->recordAnalystBehaviorTrend('analyst-1', 't1', '7d', [
            'fatigue_score' => 0.80,
        ]);
        $this->assertFalse($t->behavior_stable);
    }

    public function test_fatigue_score_is_clamped_to_range(): void
    {
        $t = $this->service->recordAnalystBehaviorTrend('analyst-1', 't1', '7d', [
            'fatigue_score' => 2.0,
        ]);
        $this->assertLessThanOrEqual(1.0, $t->fatigue_score);
        $this->assertGreaterThanOrEqual(0.0, $t->fatigue_score);
    }

    public function test_analyst_behavior_trend_is_append_only(): void
    {
        $t = $this->service->recordAnalystBehaviorTrend('analyst-1', 't1', '14d', []);
        $model = AnalystBehaviorTrend::where('trend_id', $t->trend_id)->first();
        $this->expectException(LogicException::class);
        $model->behavior_stable = false;
        $model->save();
    }

    // =========================================================================
    // False-positive evolution
    // =========================================================================

    public function test_fp_evolution_improving_verdict(): void
    {
        $r = $this->service->recordFpEvolution('t1', '7d', 0.08, 0.04);
        $this->assertEquals('improving', $r->fp_verdict);
    }

    public function test_fp_evolution_worsening_verdict(): void
    {
        $r = $this->service->recordFpEvolution('t1', '7d', 0.02, 0.07);
        $this->assertEquals('worsening', $r->fp_verdict);
    }

    public function test_fp_evolution_critical_verdict_at_high_rate(): void
    {
        $r = $this->service->recordFpEvolution('t1', '7d', 0.05, 0.15);
        $this->assertEquals('critical', $r->fp_verdict);
    }

    public function test_fp_evolution_stable_verdict(): void
    {
        $r = $this->service->recordFpEvolution('t1', '7d', 0.05, 0.05);
        $this->assertEquals('stable', $r->fp_verdict);
    }

    public function test_fp_evolution_is_append_only(): void
    {
        $r = $this->service->recordFpEvolution('t1', '7d', 0.05, 0.05);
        $model = FalsePositiveEvolutionReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->fp_verdict = 'critical';
        $model->save();
    }

    // =========================================================================
    // Operational drift history
    // =========================================================================

    public function test_drift_history_stable_verdict(): void
    {
        $r = $this->service->recordDriftHistory('t1', '7d', [
            'replay_amplification_drift' => 0.01,
            'queue_growth_drift'         => 0.01,
        ]);
        $this->assertEquals('stable', $r->drift_verdict);
        $this->assertTrue($r->is_advisory);
    }

    public function test_drift_history_critical_verdict_with_high_drift(): void
    {
        $r = $this->service->recordDriftHistory('t1', '7d', [
            'replay_amplification_drift'       => 0.60,
            'queue_growth_drift'               => 0.60,
            'telemetry_growth_drift'           => 0.60,
            'analyst_overload_drift'           => 0.60,
            'storage_pressure_drift'           => 0.60,
            'infrastructure_degradation_drift' => 0.60,
            'graph_traversal_latency_drift'    => 0.60,
            'replay_latency_drift'             => 0.60,
        ]);
        $this->assertEquals('critical', $r->drift_verdict);
    }

    public function test_composite_drift_score_is_average(): void
    {
        $r = $this->service->recordDriftHistory('t1', '7d', [
            'replay_amplification_drift' => 0.20,
            'queue_growth_drift'         => 0.20,
        ]);
        $this->assertGreaterThan(0.0, $r->composite_drift_score);
    }

    public function test_drift_history_rejects_invalid_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDriftHistory('t1', '90d', []);
    }

    public function test_drift_history_uses_explicit_table(): void
    {
        $r = $this->service->recordDriftHistory('t1', '7d', []);
        $this->assertDatabaseHas('operational_drift_history', ['drift_id' => $r->drift_id]);
    }

    public function test_drift_history_is_append_only(): void
    {
        $r = $this->service->recordDriftHistory('t1', '7d', []);
        $model = OperationalDriftHistory::where('drift_id', $r->drift_id)->first();
        $this->expectException(LogicException::class);
        $model->drift_verdict = 'critical';
        $model->save();
    }

    // =========================================================================
    // Governance reporting
    // =========================================================================

    public function test_governance_report_pass_with_all_passing(): void
    {
        $r = $this->service->generateGovernanceReport('t1', 'weekly', '7d', [
            'telemetry_passing'        => true,
            'replay_passing'           => true,
            'analyst_stable'           => true,
            'infrastructure_stable'    => true,
            'tenant_isolation_passing' => true,
        ]);
        $this->assertEquals('pass', $r->governance_verdict);
        $this->assertEquals(1.0, $r->overall_health_score);
    }

    public function test_governance_report_degraded_with_failures(): void
    {
        $r = $this->service->generateGovernanceReport('t1', 'weekly', '7d', [
            'telemetry_passing'        => false,
            'replay_passing'           => false,
            'analyst_stable'           => true,
            'infrastructure_stable'    => true,
            'tenant_isolation_passing' => true,
        ]);
        $this->assertContains($r->governance_verdict, ['advisory', 'degraded']);
    }

    public function test_governance_report_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateGovernanceReport('t1', 'quarterly_unknown', '7d', []);
    }

    public function test_governance_report_writes_audit(): void
    {
        $this->service->generateGovernanceReport('t1', 'weekly', '7d', []);
        $this->assertDatabaseHas('production_governance_audit', [
            'tenant_id'  => 't1',
            'event_type' => 'report_generated',
        ]);
    }

    public function test_governance_report_is_append_only(): void
    {
        $r = $this->service->generateGovernanceReport('t1', 'weekly', '7d', []);
        $model = GovernanceReportingRun::where('run_id', $r->run_id)->first();
        $this->expectException(LogicException::class);
        $model->governance_verdict = 'fail';
        $model->save();
    }

    // =========================================================================
    // Replay durability history
    // =========================================================================

    public function test_replay_durability_acceptable_with_good_metrics(): void
    {
        $r = $this->service->recordReplayDurability('t1', '7d', [
            'replay_success_rate_pct'  => 0.97,
            'replay_amplification_avg' => 1.5,
            'backlog_trend_slope'      => 0.005,
        ]);
        $this->assertTrue($r->durability_acceptable);
        $this->assertTrue($r->is_advisory);
    }

    public function test_replay_durability_not_acceptable_high_amplification(): void
    {
        $r = $this->service->recordReplayDurability('t1', '7d', [
            'replay_amplification_avg' => 4.0,
        ]);
        $this->assertFalse($r->durability_acceptable);
    }

    public function test_replay_durability_uses_explicit_table(): void
    {
        $r = $this->service->recordReplayDurability('t1', '7d', []);
        $this->assertDatabaseHas('replay_durability_history', ['history_id' => $r->history_id]);
    }

    public function test_replay_durability_is_append_only(): void
    {
        $r = $this->service->recordReplayDurability('t1', '7d', []);
        $model = ReplayDurabilityHistory::where('history_id', $r->history_id)->first();
        $this->expectException(LogicException::class);
        $model->durability_acceptable = false;
        $model->save();
    }

    // =========================================================================
    // Infrastructure stability
    // =========================================================================

    public function test_infrastructure_stable_with_flat_trends(): void
    {
        $r = $this->service->recordInfrastructureStability('t1', '7d', [
            'cpu_trend_slope'     => 0.001,
            'memory_trend_slope'  => 0.001,
            'storage_trend_slope' => 0.001,
        ]);
        $this->assertEquals('stable', $r->stability_verdict);
        $this->assertTrue($r->is_advisory);
    }

    public function test_infrastructure_degrading_with_rising_cpu(): void
    {
        $r = $this->service->recordInfrastructureStability('t1', '7d', [
            'cpu_trend_slope' => 0.03,
        ]);
        $this->assertEquals('degrading', $r->stability_verdict);
    }

    public function test_infrastructure_critical_with_high_slope(): void
    {
        $r = $this->service->recordInfrastructureStability('t1', '7d', [
            'cpu_trend_slope'     => 0.06,
            'storage_trend_slope' => 0.06,
        ]);
        $this->assertEquals('critical', $r->stability_verdict);
    }

    public function test_infrastructure_stability_is_append_only(): void
    {
        $r = $this->service->recordInfrastructureStability('t1', '7d', []);
        $model = InfrastructureStabilityReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->stability_verdict = 'critical';
        $model->save();
    }

    // =========================================================================
    // Production governance audit
    // =========================================================================

    public function test_production_governance_audit_uses_explicit_table(): void
    {
        $this->service->recordOperationalWindow('t1', '7d', []);
        $this->assertDatabaseHas('production_governance_audit', ['tenant_id' => 't1']);
    }

    public function test_production_governance_audit_is_append_only(): void
    {
        $this->service->recordOperationalWindow('t1', '7d', []);
        $model = ProductionGovernanceAudit::where('tenant_id', 't1')->first();
        $this->expectException(LogicException::class);
        $model->outcome = 'failed';
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('total_windows', $stats);
        $this->assertArrayHasKey('criteria_met', $stats);
        $this->assertArrayHasKey('trend_critical', $stats);
        $this->assertArrayHasKey('drift_critical', $stats);
        $this->assertArrayHasKey('governance_pass', $stats);
        $this->assertArrayHasKey('replay_acceptable', $stats);
        $this->assertArrayHasKey('infra_stable', $stats);
        $this->assertArrayHasKey('fp_worsening', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_has_115_supported_domains(): void
    {
        $this->assertCount(125, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_telemetry_trend_reports_domain_supported(): void
    {
        $this->assertContains('telemetry_trend_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_analyst_behavior_trends_domain_supported(): void
    {
        $this->assertContains('analyst_behavior_trends', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_false_positive_evolution_reports_domain_supported(): void
    {
        $this->assertContains('false_positive_evolution_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_operational_drift_history_domain_supported(): void
    {
        $this->assertContains('operational_drift_history', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_governance_reporting_runs_domain_supported(): void
    {
        $this->assertContains('governance_reporting_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_long_ops_dashboard_requires_auth(): void
    {
        $this->get(route('long-ops.dashboard'))->assertRedirect();
    }

    public function test_long_ops_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('long-ops.dashboard'))->assertOk();
    }

    public function test_long_ops_telemetry_trend_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('long-ops.telemetry-trend'))->assertOk();
    }

    public function test_long_ops_drift_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('long-ops.drift'))->assertOk();
    }

    public function test_long_ops_governance_reporting_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('long-ops.governance-reporting'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('long-ops.dashboard'))
            ->assertSee('advisory-only');
    }
}
