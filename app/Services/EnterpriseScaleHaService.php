<?php

namespace App\Services;

use App\Models\ClusterTopologyReport;
use App\Models\HaValidationRun;
use App\Models\TelemetryDistributionReport;
use App\Models\FailoverCoordinationHistory;
use App\Models\DistributedReplayContinuity;
use App\Models\InfrastructureCostReport;
use App\Models\ClusterLifecycleAudit;
use App\Models\ScaleProfileValidationRun;
use App\Models\EnterpriseScaleOperationsAudit;
use Illuminate\Support\Str;

class EnterpriseScaleHaService
{
    public const ADVISORY_ONLY             = true;
    public const MAX_CLUSTER_NODES         = 50;
    public const MAX_FAILOVER_DURATION_S   = 1800;
    public const MAX_SCALE_ENDPOINTS       = 500;
    public const HA_PASS_THRESHOLD         = 0.80;
    public const MAX_REPLAY_AMPLIFICATION  = 3.0;
    public const SCALE_PROFILES            = ['small_50', 'medium_250', 'large_500'];
    public const SCALE_ENDPOINT_MAP        = ['small_50' => 50, 'medium_250' => 250, 'large_500' => 500];

    // =========================================================================
    // Cluster topology
    // =========================================================================

    public function recordClusterTopology(
        string $clusterId,
        string $clusterRole,
        string $topologyState,
        array  $params = []
    ): ClusterTopologyReport {
        if (!in_array($clusterRole, ClusterTopologyReport::CLUSTER_ROLES, true)) {
            throw new \InvalidArgumentException("Invalid cluster role: {$clusterRole}");
        }

        if (!in_array($topologyState, ClusterTopologyReport::TOPOLOGY_STATES, true)) {
            throw new \InvalidArgumentException("Invalid topology state: {$topologyState}");
        }

        $nodeCount = min((int) ($params['node_count'] ?? 1), self::MAX_CLUSTER_NODES);

        $report = ClusterTopologyReport::create([
            'topology_report_id' => 'ctr-' . Str::uuid(),
            'cluster_id'         => $clusterId,
            'cluster_role'       => $clusterRole,
            'topology_state'     => $topologyState,
            'node_count'         => $nodeCount,
            'replica_count'      => max(0, (int) ($params['replica_count'] ?? 0)),
            'replication_lag_ms' => max(0.0, (float) ($params['replication_lag_ms'] ?? 0.0)),
            'quorum_achieved'    => $params['quorum_achieved'] ?? false,
            'replay_safe'        => true,
            'is_advisory'        => true,
            'topology_evidence'  => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('cluster_registered', 'operator', $clusterId, [
            'topology_report_id' => $report->topology_report_id,
            'role'               => $clusterRole,
            'state'              => $topologyState,
        ]);

        return $report;
    }

    // =========================================================================
    // HA validation
    // =========================================================================

    public function runHaValidation(
        string $clusterId,
        string $haCheckType,
        array  $params = []
    ): HaValidationRun {
        if (!in_array($haCheckType, HaValidationRun::HA_CHECK_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid HA check type: {$haCheckType}");
        }

        $quorumValid       = $params['quorum_valid']       ?? false;
        $replicaContinuous = $params['replica_continuous'] ?? false;
        $replayContinuous  = $params['replay_continuous']  ?? false;
        $failoverReady     = $params['failover_ready']     ?? false;

        $score = round(
            (($quorumValid ? 1 : 0) + ($replicaContinuous ? 1 : 0)
             + ($replayContinuous ? 1 : 0) + ($failoverReady ? 1 : 0)) / 4,
            2
        );

        $state = match(true) {
            $score >= self::HA_PASS_THRESHOLD => 'passing',
            $score >= 0.50                    => 'degraded',
            $score > 0.0                      => 'recovering',
            default                           => 'failed',
        };

        $run = HaValidationRun::create([
            'ha_run_id'          => 'har-' . Str::uuid(),
            'cluster_id'         => $clusterId,
            'ha_check_type'      => $haCheckType,
            'ha_state'           => $state,
            'ha_score'           => $score,
            'replication_lag_ms' => max(0.0, (float) ($params['replication_lag_ms'] ?? 0.0)),
            'quorum_valid'       => $quorumValid,
            'replica_continuous' => $replicaContinuous,
            'replay_continuous'  => $replayContinuous,
            'failover_ready'     => $failoverReady,
            'is_advisory'        => true,
            'ha_evidence'        => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('ha_validated', 'system', $clusterId, [
            'ha_run_id' => $run->ha_run_id,
            'score'     => $score,
            'state'     => $state,
        ]);

        return $run;
    }

    // =========================================================================
    // Telemetry distribution
    // =========================================================================

    public function recordTelemetryDistribution(
        string $clusterId,
        string $distributionState,
        array  $params = []
    ): TelemetryDistributionReport {
        if (!in_array($distributionState, TelemetryDistributionReport::DISTRIBUTION_STATES, true)) {
            throw new \InvalidArgumentException("Invalid distribution state: {$distributionState}");
        }

        $amplification = min(
            (float) ($params['replay_amplification_ratio'] ?? 1.0),
            self::MAX_REPLAY_AMPLIFICATION
        );

        $report = TelemetryDistributionReport::create([
            'distribution_report_id'     => 'tdr-' . Str::uuid(),
            'cluster_id'                 => $clusterId,
            'distribution_state'         => $distributionState,
            'ingestion_rate_eps'         => max(0.0, (float) ($params['ingestion_rate_eps'] ?? 0.0)),
            'queue_pressure_pct'         => min(100.0, max(0.0, (float) ($params['queue_pressure_pct'] ?? 0.0))),
            'cross_cluster_lag_ms'       => max(0.0, (float) ($params['cross_cluster_lag_ms'] ?? 0.0)),
            'replay_amplification_ratio' => $amplification,
            'load_balanced'              => $params['load_balanced'] ?? false,
            'bounded_redistribution'     => true,
            'replay_safe'                => true,
            'is_advisory'                => true,
            'distribution_evidence'      => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('telemetry_balanced', 'system', $clusterId, [
            'distribution_report_id' => $report->distribution_report_id,
            'state'                  => $distributionState,
        ]);

        return $report;
    }

    // =========================================================================
    // Failover coordination
    // =========================================================================

    public function coordinateFailover(
        string $clusterId,
        string $failoverType,
        string $failoverState,
        array  $params = []
    ): FailoverCoordinationHistory {
        if (!in_array($failoverType, FailoverCoordinationHistory::FAILOVER_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid failover type: {$failoverType}");
        }

        if (!in_array($failoverState, FailoverCoordinationHistory::FAILOVER_STATES, true)) {
            throw new \InvalidArgumentException("Invalid failover state: {$failoverState}");
        }

        $duration = min(
            (float) ($params['duration_s'] ?? 0.0),
            self::MAX_FAILOVER_DURATION_S
        );

        $history = FailoverCoordinationHistory::create([
            'failover_coordination_id' => 'fch-' . Str::uuid(),
            'cluster_id'               => $clusterId,
            'failover_type'            => $failoverType,
            'failover_state'           => $failoverState,
            'duration_s'               => $duration,
            'dependency_ordered'       => $params['dependency_ordered'] ?? false,
            'replay_verified'          => $params['replay_verified']    ?? false,
            'rollback_ready'           => $params['rollback_ready']     ?? false,
            'uncontrolled_failover'    => false,
            'is_advisory'              => true,
            'failover_evidence'        => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('failover_drilled', 'operator', $clusterId, [
            'failover_coordination_id' => $history->failover_coordination_id,
            'type'                     => $failoverType,
            'state'                    => $failoverState,
        ]);

        return $history;
    }

    // =========================================================================
    // Distributed replay continuity
    // =========================================================================

    public function recordReplayContinuity(
        string $clusterId,
        string $continuityState,
        array  $params = []
    ): DistributedReplayContinuity {
        if (!in_array($continuityState, DistributedReplayContinuity::CONTINUITY_STATES, true)) {
            throw new \InvalidArgumentException("Invalid continuity state: {$continuityState}");
        }

        $amplification = min(
            (float) ($params['amplification_ratio'] ?? 1.0),
            self::MAX_REPLAY_AMPLIFICATION
        );

        $record = DistributedReplayContinuity::create([
            'replay_continuity_id'     => 'drc-' . Str::uuid(),
            'cluster_id'               => $clusterId,
            'continuity_state'         => $continuityState,
            'replay_coverage_pct'      => min(100.0, max(0.0, (float) ($params['replay_coverage_pct'] ?? 0.0))),
            'gap_count'                => max(0, (int) ($params['gap_count'] ?? 0)),
            'amplification_ratio'      => $amplification,
            'cross_cluster_continuous' => $params['cross_cluster_continuous'] ?? false,
            'durability_verified'      => $params['durability_verified']      ?? false,
            'replay_safe'              => true,
            'is_advisory'              => true,
            'continuity_evidence'      => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('continuity_verified', 'system', $clusterId, [
            'replay_continuity_id' => $record->replay_continuity_id,
            'state'                => $continuityState,
        ]);

        return $record;
    }

    // =========================================================================
    // Infrastructure cost
    // =========================================================================

    public function assessInfrastructureCost(
        string $clusterId,
        string $costWindow,
        array  $params = []
    ): InfrastructureCostReport {
        if (!in_array($costWindow, InfrastructureCostReport::COST_WINDOWS, true)) {
            throw new \InvalidArgumentException("Invalid cost window: {$costWindow}");
        }

        $storage     = max(0.0, (float) ($params['storage_cost_estimate']     ?? 0.0));
        $ingestion   = max(0.0, (float) ($params['ingestion_cost_estimate']   ?? 0.0));
        $replayAmp   = max(0.0, (float) ($params['replay_amplification_cost'] ?? 0.0));
        $haOverhead  = max(0.0, (float) ($params['ha_overhead_cost']          ?? 0.0));
        $totalCost   = $storage + $ingestion + $replayAmp + $haOverhead;

        $report = InfrastructureCostReport::create([
            'cost_report_id'             => 'icr-' . Str::uuid(),
            'cluster_id'                 => $clusterId,
            'cost_window'                => $costWindow,
            'storage_cost_estimate'      => $storage,
            'ingestion_cost_estimate'    => $ingestion,
            'replay_amplification_cost'  => $replayAmp,
            'ha_overhead_cost'           => $haOverhead,
            'total_cost_estimate'        => $totalCost,
            'utilization_efficiency_pct' => min(100.0, max(0.0, (float) ($params['utilization_efficiency_pct'] ?? 0.0))),
            'is_advisory'                => true,
            'replay_safe'                => true,
            'cost_evidence'              => $params['evidence'] ?? null,
        ]);

        $this->recordAudit('cost_assessed', 'system', $clusterId, [
            'cost_report_id' => $report->cost_report_id,
            'total'          => $totalCost,
            'window'         => $costWindow,
        ]);

        return $report;
    }

    // =========================================================================
    // Cluster lifecycle audit
    // =========================================================================

    public function recordClusterLifecycle(
        string  $clusterId,
        string  $lifecycleEvent,
        string  $currentState,
        ?string $previousState = null,
        array   $params = []
    ): ClusterLifecycleAudit {
        if (!in_array($lifecycleEvent, ClusterLifecycleAudit::LIFECYCLE_EVENTS, true)) {
            throw new \InvalidArgumentException("Invalid lifecycle event: {$lifecycleEvent}");
        }

        return ClusterLifecycleAudit::create([
            'cluster_audit_id'         => 'cla-' . Str::uuid(),
            'cluster_id'               => $clusterId,
            'lifecycle_event'          => $lifecycleEvent,
            'previous_state'           => $previousState,
            'current_state'            => $currentState,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Scale profile validation
    // =========================================================================

    public function validateScaleProfile(
        string $scaleProfile,
        array  $metrics = []
    ): ScaleProfileValidationRun {
        if (!in_array($scaleProfile, self::SCALE_PROFILES, true)) {
            throw new \InvalidArgumentException("Invalid scale profile: {$scaleProfile}");
        }

        $endpointCount = min(
            self::SCALE_ENDPOINT_MAP[$scaleProfile],
            self::MAX_SCALE_ENDPOINTS
        );

        $telemetryContinuity  = min(100.0, max(0.0, (float) ($metrics['telemetry_continuity_pct']  ?? 0.0)));
        $replayDurability     = min(100.0, max(0.0, (float) ($metrics['replay_durability_pct']     ?? 0.0)));
        $analystWorkload      = min(1.0,   max(0.0, (float) ($metrics['analyst_workload_score']    ?? 0.0)));
        $queueRecovery        = min(1.0,   max(0.0, (float) ($metrics['queue_recovery_score']      ?? 0.0)));

        $operationalScore = round(
            ($telemetryContinuity / 100 + $replayDurability / 100 + $analystWorkload + $queueRecovery) / 4,
            3
        );

        $run = ScaleProfileValidationRun::create([
            'scale_run_id'             => 'spv-' . Str::uuid(),
            'scale_profile'            => $scaleProfile,
            'endpoint_count'           => $endpointCount,
            'telemetry_continuity_pct' => $telemetryContinuity,
            'replay_durability_pct'    => $replayDurability,
            'analyst_workload_score'   => $analystWorkload,
            'queue_recovery_score'     => $queueRecovery,
            'operational_score'        => $operationalScore,
            'scale_passed'             => $operationalScore >= self::HA_PASS_THRESHOLD,
            'bounded_scope'            => true,
            'replay_safe'              => true,
            'is_advisory'              => true,
            'scale_evidence'           => $metrics['evidence'] ?? null,
        ]);

        $this->recordAudit('scale_validated', 'system', null, [
            'scale_run_id'     => $run->scale_run_id,
            'profile'          => $scaleProfile,
            'operational_score'=> $operationalScore,
        ]);

        return $run;
    }

    // =========================================================================
    // Audit
    // =========================================================================

    public function recordAudit(
        string  $auditEventType,
        string  $actorType,
        ?string $clusterId = null,
        array   $evidence = []
    ): EnterpriseScaleOperationsAudit {
        if (!in_array($auditEventType, EnterpriseScaleOperationsAudit::AUDIT_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid audit event type: {$auditEventType}");
        }

        if (!in_array($actorType, EnterpriseScaleOperationsAudit::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid actor type: {$actorType}");
        }

        return EnterpriseScaleOperationsAudit::create([
            'scale_audit_id'           => 'esa-' . Str::uuid(),
            'audit_event_type'         => $auditEventType,
            'actor_type'               => $actorType,
            'cluster_id'               => $clusterId,
            'autonomous_action_taken'  => false,
            'destructive_action_taken' => false,
            'replay_safe'              => true,
            'audit_evidence'           => $evidence ?: null,
        ]);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'cluster_topology_reports'      => ClusterTopologyReport::count(),
            'healthy_clusters'              => ClusterTopologyReport::where('topology_state', 'healthy')->count(),
            'ha_validation_runs'            => HaValidationRun::count(),
            'ha_passing'                    => HaValidationRun::where('ha_state', 'passing')->count(),
            'distribution_reports'          => TelemetryDistributionReport::count(),
            'balanced_clusters'             => TelemetryDistributionReport::where('distribution_state', 'balanced')->count(),
            'failover_drills'               => FailoverCoordinationHistory::count(),
            'cost_reports'                  => InfrastructureCostReport::count(),
            'scale_validations'             => ScaleProfileValidationRun::count(),
            'scale_passed'                  => ScaleProfileValidationRun::where('scale_passed', true)->count(),
            'audit_events'                  => EnterpriseScaleOperationsAudit::count(),
        ];
    }
}
