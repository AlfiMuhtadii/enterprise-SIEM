<?php

namespace Tests\Feature;

use App\Models\ClusterTopologyReport;
use App\Models\HaValidationRun;
use App\Models\TelemetryDistributionReport;
use App\Models\FailoverCoordinationHistory;
use App\Models\DistributedReplayContinuity;
use App\Models\InfrastructureCostReport;
use App\Models\ClusterLifecycleAudit;
use App\Models\ScaleProfileValidationRun;
use App\Models\EnterpriseScaleOperationsAudit;
use App\Services\EnterpriseScaleHaService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class EnterpriseScaleHaTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private EnterpriseScaleHaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseScaleHaService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return EnterpriseScaleHaService::class;
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_uncontrolled_failover_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseScaleHaService::class, 'executeFailover'));
    }

    public function test_no_destructive_infrastructure_orchestration(): void
    {
        $this->assertFalse(method_exists(EnterpriseScaleHaService::class, 'destroyCluster'));
    }

    public function test_no_unsafe_distributed_automation(): void
    {
        $this->assertFalse(method_exists(EnterpriseScaleHaService::class, 'runUnsafeDistributedTask'));
    }

    public function test_no_hidden_cluster_mutation(): void
    {
        $this->assertFalse(method_exists(EnterpriseScaleHaService::class, 'mutateClusterHidden'));
    }

    public function test_no_uncontrolled_telemetry_redistribution(): void
    {
        $this->assertFalse(method_exists(EnterpriseScaleHaService::class, 'redistributeTelemetryUncontrolled'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EnterpriseScaleHaService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function test_max_cluster_nodes_bounded(): void
    {
        $this->assertSame(50, EnterpriseScaleHaService::MAX_CLUSTER_NODES);
    }

    public function test_max_failover_duration_bounded(): void
    {
        $this->assertSame(1800, EnterpriseScaleHaService::MAX_FAILOVER_DURATION_S);
    }

    public function test_max_scale_endpoints_bounded(): void
    {
        $this->assertSame(500, EnterpriseScaleHaService::MAX_SCALE_ENDPOINTS);
    }

    public function test_ha_pass_threshold_defined(): void
    {
        $this->assertEqualsWithDelta(0.80, EnterpriseScaleHaService::HA_PASS_THRESHOLD, 0.001);
    }

    public function test_max_replay_amplification_bounded(): void
    {
        $this->assertEqualsWithDelta(3.0, EnterpriseScaleHaService::MAX_REPLAY_AMPLIFICATION, 0.001);
    }

    // =========================================================================
    // Cluster topology
    // =========================================================================

    public function test_record_cluster_topology(): void
    {
        $report = $this->service->recordClusterTopology('cluster-01', 'primary', 'healthy');

        $this->assertInstanceOf(ClusterTopologyReport::class, $report);
        $this->assertSame('cluster-01', $report->cluster_id);
        $this->assertSame('primary', $report->cluster_role);
        $this->assertSame('healthy', $report->topology_state);
        $this->assertTrue($report->replay_safe);
        $this->assertTrue($report->is_advisory);
    }

    public function test_node_count_is_bounded(): void
    {
        $report = $this->service->recordClusterTopology('cluster-02', 'secondary', 'healthy', [
            'node_count' => 9999,
        ]);

        $this->assertSame(EnterpriseScaleHaService::MAX_CLUSTER_NODES, $report->node_count);
    }

    public function test_invalid_cluster_role_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordClusterTopology('cluster-03', 'master', 'healthy');
    }

    public function test_invalid_topology_state_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordClusterTopology('cluster-04', 'primary', 'unknown_state');
    }

    public function test_topology_creates_audit(): void
    {
        $this->service->recordClusterTopology('cluster-05', 'arbiter', 'degraded');

        $this->assertDatabaseHas('enterprise_scale_operations_audit', [
            'audit_event_type' => 'cluster_registered',
            'cluster_id'       => 'cluster-05',
        ]);
    }

    // =========================================================================
    // HA validation
    // =========================================================================

    public function test_ha_validation_passing(): void
    {
        $run = $this->service->runHaValidation('cluster-10', 'quorum', [
            'quorum_valid'       => true,
            'replica_continuous' => true,
            'replay_continuous'  => true,
            'failover_ready'     => true,
        ]);

        $this->assertInstanceOf(HaValidationRun::class, $run);
        $this->assertSame('passing', $run->ha_state);
        $this->assertGreaterThanOrEqual(EnterpriseScaleHaService::HA_PASS_THRESHOLD, $run->ha_score);
    }

    public function test_ha_validation_failed(): void
    {
        $run = $this->service->runHaValidation('cluster-11', 'replica_continuity', [
            'quorum_valid'       => false,
            'replica_continuous' => false,
            'replay_continuous'  => false,
            'failover_ready'     => false,
        ]);

        $this->assertSame('failed', $run->ha_state);
        $this->assertEqualsWithDelta(0.0, $run->ha_score, 0.001);
    }

    public function test_ha_validation_degraded(): void
    {
        $run = $this->service->runHaValidation('cluster-12', 'failover_readiness', [
            'quorum_valid'       => true,
            'replica_continuous' => true,
            'replay_continuous'  => false,
            'failover_ready'     => false,
        ]);

        $this->assertSame('degraded', $run->ha_state);
    }

    public function test_invalid_ha_check_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runHaValidation('cluster-13', 'unknown_check');
    }

    public function test_ha_validation_is_advisory(): void
    {
        $run = $this->service->runHaValidation('cluster-14', 'replication_lag');
        $this->assertTrue($run->is_advisory);
    }

    public function test_ha_validation_creates_audit(): void
    {
        $this->service->runHaValidation('cluster-15', 'quorum');

        $this->assertDatabaseHas('enterprise_scale_operations_audit', [
            'audit_event_type' => 'ha_validated',
            'cluster_id'       => 'cluster-15',
        ]);
    }

    // =========================================================================
    // Telemetry distribution
    // =========================================================================

    public function test_record_telemetry_distribution(): void
    {
        $report = $this->service->recordTelemetryDistribution('cluster-20', 'balanced', [
            'ingestion_rate_eps'         => 1000.0,
            'queue_pressure_pct'         => 30.0,
            'cross_cluster_lag_ms'       => 5.0,
            'replay_amplification_ratio' => 1.5,
            'load_balanced'              => true,
        ]);

        $this->assertInstanceOf(TelemetryDistributionReport::class, $report);
        $this->assertTrue($report->load_balanced);
        $this->assertTrue($report->bounded_redistribution);
        $this->assertTrue($report->replay_safe);
    }

    public function test_replay_amplification_is_bounded(): void
    {
        $report = $this->service->recordTelemetryDistribution('cluster-21', 'overloaded', [
            'replay_amplification_ratio' => 99.9,
        ]);

        $this->assertEqualsWithDelta(EnterpriseScaleHaService::MAX_REPLAY_AMPLIFICATION, $report->replay_amplification_ratio, 0.001);
    }

    public function test_queue_pressure_clamped(): void
    {
        $report = $this->service->recordTelemetryDistribution('cluster-22', 'overloaded', [
            'queue_pressure_pct' => 200.0,
        ]);

        $this->assertEqualsWithDelta(100.0, $report->queue_pressure_pct, 0.001);
    }

    public function test_invalid_distribution_state_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordTelemetryDistribution('cluster-23', 'exploded');
    }

    // =========================================================================
    // Failover coordination
    // =========================================================================

    public function test_coordinate_failover(): void
    {
        $history = $this->service->coordinateFailover('cluster-30', 'drill', 'completed', [
            'dependency_ordered' => true,
            'replay_verified'    => true,
            'rollback_ready'     => true,
        ]);

        $this->assertInstanceOf(FailoverCoordinationHistory::class, $history);
        $this->assertFalse($history->uncontrolled_failover);
        $this->assertTrue($history->dependency_ordered);
        $this->assertTrue($history->is_advisory);
    }

    public function test_failover_duration_is_bounded(): void
    {
        $history = $this->service->coordinateFailover('cluster-31', 'simulation', 'completed', [
            'duration_s' => 999999.0,
        ]);

        $this->assertEqualsWithDelta(EnterpriseScaleHaService::MAX_FAILOVER_DURATION_S, $history->duration_s, 0.001);
    }

    public function test_uncontrolled_failover_always_false(): void
    {
        $history = $this->service->coordinateFailover('cluster-32', 'drill', 'aborted');
        $this->assertFalse($history->uncontrolled_failover);
    }

    public function test_invalid_failover_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->coordinateFailover('cluster-33', 'force_takeover', 'completed');
    }

    public function test_failover_creates_audit(): void
    {
        $this->service->coordinateFailover('cluster-34', 'drill', 'planned');

        $this->assertDatabaseHas('enterprise_scale_operations_audit', [
            'audit_event_type' => 'failover_drilled',
            'cluster_id'       => 'cluster-34',
        ]);
    }

    // =========================================================================
    // Distributed replay continuity
    // =========================================================================

    public function test_record_replay_continuity(): void
    {
        $record = $this->service->recordReplayContinuity('cluster-40', 'continuous', [
            'replay_coverage_pct'      => 99.5,
            'gap_count'                => 0,
            'cross_cluster_continuous' => true,
            'durability_verified'      => true,
        ]);

        $this->assertInstanceOf(DistributedReplayContinuity::class, $record);
        $this->assertTrue($record->replay_safe);
        $this->assertTrue($record->is_advisory);
        $this->assertTrue($record->cross_cluster_continuous);
    }

    public function test_amplification_bounded_in_continuity(): void
    {
        $record = $this->service->recordReplayContinuity('cluster-41', 'gap_detected', [
            'amplification_ratio' => 100.0,
        ]);

        $this->assertEqualsWithDelta(EnterpriseScaleHaService::MAX_REPLAY_AMPLIFICATION, $record->amplification_ratio, 0.001);
    }

    public function test_invalid_continuity_state_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordReplayContinuity('cluster-42', 'broken');
    }

    // =========================================================================
    // Infrastructure cost
    // =========================================================================

    public function test_assess_infrastructure_cost(): void
    {
        $report = $this->service->assessInfrastructureCost('cluster-50', 'monthly', [
            'storage_cost_estimate'     => 100.0,
            'ingestion_cost_estimate'   => 50.0,
            'replay_amplification_cost' => 20.0,
            'ha_overhead_cost'          => 30.0,
        ]);

        $this->assertInstanceOf(InfrastructureCostReport::class, $report);
        $this->assertEqualsWithDelta(200.0, $report->total_cost_estimate, 0.001);
        $this->assertTrue($report->is_advisory);
        $this->assertTrue($report->replay_safe);
    }

    public function test_cost_calculation_is_deterministic(): void
    {
        $params = [
            'storage_cost_estimate'     => 100.0,
            'ingestion_cost_estimate'   => 50.0,
            'replay_amplification_cost' => 20.0,
            'ha_overhead_cost'          => 30.0,
        ];

        $r1 = $this->service->assessInfrastructureCost('cluster-51', 'daily', $params);
        $r2 = $this->service->assessInfrastructureCost('cluster-51', 'daily', $params);

        $this->assertEqualsWithDelta($r1->total_cost_estimate, $r2->total_cost_estimate, 0.001);
    }

    public function test_invalid_cost_window_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assessInfrastructureCost('cluster-52', 'yearly');
    }

    // =========================================================================
    // Cluster lifecycle
    // =========================================================================

    public function test_record_cluster_lifecycle(): void
    {
        $audit = $this->service->recordClusterLifecycle('cluster-60', 'registered', 'active', null);

        $this->assertInstanceOf(ClusterLifecycleAudit::class, $audit);
        $this->assertFalse($audit->autonomous_action_taken);
        $this->assertFalse($audit->destructive_action_taken);
        $this->assertTrue($audit->replay_safe);
    }

    public function test_invalid_lifecycle_event_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordClusterLifecycle('cluster-61', 'forcekill', 'dead');
    }

    // =========================================================================
    // Scale profile validation
    // =========================================================================

    public function test_validate_scale_profile_small(): void
    {
        $run = $this->service->validateScaleProfile('small_50', [
            'telemetry_continuity_pct' => 99.0,
            'replay_durability_pct'    => 98.0,
            'analyst_workload_score'   => 0.9,
            'queue_recovery_score'     => 0.85,
        ]);

        $this->assertInstanceOf(ScaleProfileValidationRun::class, $run);
        $this->assertSame(50, $run->endpoint_count);
        $this->assertTrue($run->scale_passed);
        $this->assertTrue($run->bounded_scope);
        $this->assertTrue($run->is_advisory);
    }

    public function test_validate_scale_profile_large(): void
    {
        $run = $this->service->validateScaleProfile('large_500', [
            'telemetry_continuity_pct' => 95.0,
            'replay_durability_pct'    => 92.0,
            'analyst_workload_score'   => 0.88,
            'queue_recovery_score'     => 0.82,
        ]);

        $this->assertSame(500, $run->endpoint_count);
    }

    public function test_scale_profile_endpoint_never_exceeds_max(): void
    {
        $run = $this->service->validateScaleProfile('large_500');
        $this->assertLessThanOrEqual(EnterpriseScaleHaService::MAX_SCALE_ENDPOINTS, $run->endpoint_count);
    }

    public function test_scale_below_threshold_fails(): void
    {
        $run = $this->service->validateScaleProfile('medium_250', [
            'telemetry_continuity_pct' => 10.0,
            'replay_durability_pct'    => 5.0,
            'analyst_workload_score'   => 0.1,
            'queue_recovery_score'     => 0.1,
        ]);

        $this->assertFalse($run->scale_passed);
    }

    public function test_invalid_scale_profile_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateScaleProfile('mega_10000');
    }

    // =========================================================================
    // Audit
    // =========================================================================

    public function test_audit_never_autonomous(): void
    {
        $audit = $this->service->recordAudit('cost_assessed', 'system', 'cluster-70');
        $this->assertFalse($audit->autonomous_action_taken);
        $this->assertFalse($audit->destructive_action_taken);
    }

    public function test_audit_is_replay_safe(): void
    {
        $audit = $this->service->recordAudit('continuity_verified', 'operator', 'cluster-71');
        $this->assertTrue($audit->replay_safe);
    }

    public function test_invalid_audit_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAudit('destroy_infrastructure', 'system', 'cluster-72');
    }

    // =========================================================================
    // Append-only integrity
    // =========================================================================

    public function test_cluster_topology_reports_are_append_only(): void
    {
        $report = $this->service->recordClusterTopology('cluster-80', 'observer', 'healthy');
        $this->assertDatabaseHas('cluster_topology_reports', ['topology_report_id' => $report->topology_report_id]);
    }

    public function test_ha_validation_runs_are_append_only(): void
    {
        $run = $this->service->runHaValidation('cluster-81', 'quorum');
        $this->assertDatabaseHas('ha_validation_runs', ['ha_run_id' => $run->ha_run_id]);
    }

    public function test_failover_coordination_history_is_append_only(): void
    {
        $history = $this->service->coordinateFailover('cluster-82', 'drill', 'planned');
        $this->assertDatabaseHas('failover_coordination_history', ['failover_coordination_id' => $history->failover_coordination_id]);
    }

    public function test_distributed_replay_continuity_is_append_only(): void
    {
        $record = $this->service->recordReplayContinuity('cluster-83', 'continuous');
        $this->assertDatabaseHas('distributed_replay_continuity', ['replay_continuity_id' => $record->replay_continuity_id]);
    }

    public function test_enterprise_scale_audit_is_append_only(): void
    {
        $this->service->recordAudit('scale_validated', 'operator', 'cluster-84');
        $this->assertSame(1, EnterpriseScaleOperationsAudit::where('cluster_id', 'cluster-84')->count());
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();

        $this->assertArrayHasKey('cluster_topology_reports', $stats);
        $this->assertArrayHasKey('healthy_clusters', $stats);
        $this->assertArrayHasKey('ha_validation_runs', $stats);
        $this->assertArrayHasKey('ha_passing', $stats);
        $this->assertArrayHasKey('distribution_reports', $stats);
        $this->assertArrayHasKey('balanced_clusters', $stats);
        $this->assertArrayHasKey('failover_drills', $stats);
        $this->assertArrayHasKey('cost_reports', $stats);
        $this->assertArrayHasKey('scale_validations', $stats);
        $this->assertArrayHasKey('scale_passed', $stats);
        $this->assertArrayHasKey('audit_events', $stats);
    }

    // =========================================================================
    // Threat hunting domains
    // =========================================================================

    public function test_enterprise_scale_hunt_domains_registered(): void
    {
        $svc     = app(ThreatHuntingService::class);
        $domains = $svc->supportedDomains();

        $this->assertContains('cluster_topology_reports', $domains);
        $this->assertContains('ha_validation_runs', $domains);
        $this->assertContains('telemetry_distribution_reports', $domains);
        $this->assertContains('failover_coordination_history', $domains);
        $this->assertContains('infrastructure_cost_reports', $domains);
    }

    public function test_total_hunt_domains_is_140(): void
    {
        $svc = app(ThreatHuntingService::class);
        $this->assertCount(172, $svc->supportedDomains());
    }

    public function test_hunt_cluster_topology_reports(): void
    {
        $this->service->recordClusterTopology('hunt-cluster', 'primary', 'healthy');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('cluster_topology_reports', ['cluster_id' => 'hunt-cluster']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_ha_validation_runs(): void
    {
        $this->service->runHaValidation('ha-cluster', 'quorum');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('ha_validation_runs', ['cluster_id' => 'ha-cluster']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_failover_coordination_history(): void
    {
        $this->service->coordinateFailover('fo-cluster', 'simulation', 'completed');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('failover_coordination_history', ['cluster_id' => 'fo-cluster']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_infrastructure_cost_reports(): void
    {
        $this->service->assessInfrastructureCost('cost-cluster', 'weekly');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('infrastructure_cost_reports', ['cluster_id' => 'cost-cluster']);
        $this->assertCount(1, $results);
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_enterprise_scale_routes_require_auth(): void
    {
        $routes = [
            '/enterprise-scale',
            '/enterprise-scale/topology',
            '/enterprise-scale/ha',
            '/enterprise-scale/distribution',
            '/enterprise-scale/failover',
            '/enterprise-scale/cost',
            '/enterprise-scale/replay',
            '/enterprise-scale/scale',
            '/enterprise-scale/audit',
        ];
        foreach ($routes as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }
}

