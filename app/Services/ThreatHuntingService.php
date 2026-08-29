<?php

namespace App\Services;

use App\Models\BaselineAnomalyScore;
use App\Models\EndpointAgent;
use App\Models\EndpointAgentEnrollmentEvent;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentPolicyAssignment;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointExecutionChain;
use App\Models\EndpointNetworkCorrelation;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointPrivilegeEscalation;
use App\Models\EndpointProcessEntry;
use App\Models\EndpointScriptExecution;
use App\Models\Entity;
use App\Models\EntityBehaviorBaseline;
use App\Models\EntityRelationship;
use App\Models\PeerGroupProfile;
use App\Models\ThreatHunt;
use App\Models\ThreatHuntQuery;
use App\Models\ThreatHuntResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Threat Hunting & Investigation Query Engine Phase 1.
 *
 * Advisory-only, non-destructive. All hunts are append-only records.
 * No raw SQL execution, no arbitrary regex, no shell execution.
 * Query fields and operators are strictly allowlisted per domain.
 */
class ThreatHuntingService
{
    public function __construct(private readonly TenantBoundaryService $tenantBoundary) {}

    // -----------------------------------------------------------------------
    // Safety bounds
    // -----------------------------------------------------------------------

    public const MAX_RESULTS = 500;

    public const MAX_QUERY_WINDOW_DAYS = 30;

    public const MAX_GRAPH_DEPTH = 5;

    public const DEFAULT_MAX_RESULTS = 100;

    // -----------------------------------------------------------------------
    // Supported query domains and their allowlisted fields — CODE-STRUCT-DECOMPOSE:
    // moved to ThreatHuntQueryAllowlist (SUPPORTED_DOMAINS/DOMAIN_FIELDS constants +
    // validate()) so the ~1150-line allowlist is independently reviewable/testable.
    // -----------------------------------------------------------------------

    /**
     * Backward-compatible alias: SUPPORTED_DOMAINS is public and referenced
     * externally by ~25 files (controllers/tests) that predate the
     * extraction above. DOMAIN_FIELDS was private with zero external
     * references, so it needed no such alias.
     */
    public const SUPPORTED_DOMAINS = ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS;

    private const DOMAIN_MODEL_MAP = [
        'processes' => EndpointProcessEntry::class,
        'persistence_items' => EndpointPersistenceItem::class,
        'execution_chains' => EndpointExecutionChain::class,
        'beacon_patterns' => EndpointBeaconPattern::class,
        'behavioral_findings' => EndpointBehavioralFinding::class,
        'hosts' => EndpointAgent::class,
        'network_correlations' => EndpointNetworkCorrelation::class,
        'alerts' => null, // handled separately via security_alerts
        'cross_domain_correlations' => \App\Models\CrossDomainCorrelation::class,
        'endpoint_stream_events' => \App\Models\EndpointStreamEvent::class,
        'dns_events' => \App\Models\DnsEvent::class,
        'proxy_events' => \App\Models\ProxyEvent::class,
        'firewall_events' => \App\Models\FirewallEvent::class,
        'network_behavioral_findings' => \App\Models\NetworkBehavioralFinding::class,
        'identity_provider_events' => \App\Models\IdentityProviderEvent::class,
        'saas_audit_events' => \App\Models\SaasAuditEvent::class,
        'notification_events' => \App\Models\NotificationEvent::class,
        'external_case_links' => \App\Models\ExternalCaseLink::class,
        // UEBA Phase 1
        'entity_behavior_baselines' => EntityBehaviorBaseline::class,
        'baseline_anomaly_scores' => BaselineAnomalyScore::class,
        'peer_group_profiles' => PeerGroupProfile::class,
        // Endpoint Fleet Hardening Phase 1
        'endpoint_agents' => EndpointAgent::class,
        'endpoint_agent_heartbeats' => EndpointAgentHeartbeat::class,
        'endpoint_agent_policy_assignments' => EndpointAgentPolicyAssignment::class,
        'endpoint_agent_enrollment_events' => EndpointAgentEnrollmentEvent::class,
        // Low-level endpoint telemetry — Phase 1
        'endpoint_process_executions' => EndpointProcessEntry::class,
        'endpoint_network_connections' => EndpointNetworkCorrelation::class,
        'endpoint_script_executions' => EndpointScriptExecution::class,
        'endpoint_persistence_indicators' => EndpointPersistenceItem::class,
        'endpoint_privilege_escalations' => EndpointPrivilegeEscalation::class,
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions' => \App\Models\DetectionRuleVersion::class,
        'detection_replay_results' => \App\Models\DetectionReplayResult::class,
        'detection_false_positive_reports' => \App\Models\DetectionFalsePositiveReport::class,
        'detection_attack_mappings' => \App\Models\DetectionAttackMapping::class,
        'detection_suppressions' => \App\Models\DetectionSuppression::class,
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots' => \App\Models\TelemetryCapacitySnapshot::class,
        'replay_economics_runs' => \App\Models\ReplayEconomicsRun::class,
        'query_performance_snapshots' => \App\Models\QueryPerformanceSnapshot::class,
        'storage_capacity_snapshots' => \App\Models\StorageCapacitySnapshot::class,
        'capacity_projection_runs' => \App\Models\CapacityProjectionRun::class,
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs' => \App\Models\EvidenceIntegrityRun::class,
        'audit_export_requests' => \App\Models\AuditExportRequest::class,
        'tenant_isolation_validation_runs' => \App\Models\TenantIsolationValidationRun::class,
        'pii_access_audit' => \App\Models\PiiAccessAudit::class,
        'governance_access_reviews' => \App\Models\GovernanceAccessReview::class,
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats' => \App\Models\SystemWorkerHeartbeat::class,
        'stream_consumer_lag_snapshots' => \App\Models\StreamConsumerLagSnapshot::class,
        'duplicate_event_reports' => \App\Models\DuplicateEventReport::class,
        'degraded_mode_events' => \App\Models\DegradedModeEvent::class,
        'recovery_validation_runs' => \App\Models\RecoveryValidationRun::class,
        // Production Readiness / Release Governance Phase 1
        'release_manifests' => \App\Models\ReleaseManifest::class,
        'deployment_readiness_runs' => \App\Models\DeploymentReadinessRun::class,
        'environment_drift_reports' => \App\Models\EnvironmentDriftReport::class,
        'rollback_validation_runs' => \App\Models\RollbackValidationRun::class,
        'go_nogo_decisions' => \App\Models\GoNogoDecision::class,
        // Sensor Hardening Phase 2
        'collector_health_events' => \App\Models\CollectorHealthEvent::class,
        'telemetry_gap_reports' => \App\Models\TelemetryGapReport::class,
        'telemetry_integrity_runs' => \App\Models\TelemetryIntegrityRun::class,
        'package_signature_validations' => \App\Models\PackageSignatureValidation::class,
        'offline_recovery_runs' => \App\Models\OfflineRecoveryRun::class,
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs' => \App\Models\AdversarialValidationRun::class,
        'attack_chain_timelines' => \App\Models\AttackChainTimeline::class,
        'chained_detection_graphs' => \App\Models\ChainedDetectionGraph::class,
        'evasion_resilience_reports' => \App\Models\EvasionResilienceReport::class,
        'cross_host_correlation_runs' => \App\Models\CrossHostCorrelationRun::class,
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits' => \App\Models\TenantIsolationAudit::class,
        'tenant_replay_validation_runs' => \App\Models\TenantReplayValidationRun::class,
        'tenant_graph_isolation_reports' => \App\Models\TenantGraphIsolationReport::class,
        'tenant_boundary_violation_reports' => \App\Models\TenantBoundaryViolationReport::class,
        'tenant_evidence_integrity_reports' => \App\Models\TenantEvidenceIntegrityReport::class,
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs' => \App\Models\SoakValidationRun::class,
        'chaos_simulation_runs' => \App\Models\ChaosSimulationRun::class,
        'recovery_validation_artifacts' => \App\Models\RecoveryValidationArtifact::class,
        'operational_drift_reports' => \App\Models\OperationalDriftReport::class,
        'replay_recovery_runs' => \App\Models\ReplayRecoveryRun::class,
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs' => \App\Models\PilotOnboardingRun::class,
        'pilot_health_validations' => \App\Models\PilotHealthValidation::class,
        'pilot_success_metrics' => \App\Models\PilotSuccessMetric::class,
        'pilot_rollback_validations' => \App\Models\PilotRollbackValidation::class,
        'operator_readiness_reviews' => \App\Models\OperatorReadinessReview::class,
        // Real Pilot Execution Phase 1
        'live_pilot_runs' => \App\Models\LivePilotRun::class,
        'pilot_health_checkpoints' => \App\Models\PilotHealthCheckpoint::class,
        'pilot_operational_reviews' => \App\Models\PilotOperationalReview::class,
        'live_telemetry_validations' => \App\Models\LiveTelemetryValidation::class,
        'production_observation_checkpoints' => \App\Models\ProductionObservationCheckpoint::class,
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots' => \App\Models\OperationalIntelligenceSnapshot::class,
        'analyst_investigation_summaries' => \App\Models\AnalystInvestigationSummary::class,
        'detection_confidence_history' => \App\Models\DetectionConfidenceHistory::class,
        'false_positive_drift_reports' => \App\Models\FalsePositiveDriftReport::class,
        'attack_progression_scores' => \App\Models\AttackProgressionScore::class,
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots' => \App\Models\AnalystWorkloadSnapshot::class,
        'alert_prioritization_scores' => \App\Models\AlertPrioritizationScore::class,
        'false_positive_tuning_reports' => \App\Models\FalsePositiveTuningReport::class,
        'escalation_quality_reviews' => \App\Models\EscalationQualityReview::class,
        'operational_fatigue_indicators' => \App\Models\OperationalFatigueIndicator::class,
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs' => \App\Models\TelemetryScaleValidationRun::class,
        'replay_scale_recovery_runs' => \App\Models\ReplayScaleRecoveryRun::class,
        'analyst_load_stability_reports' => \App\Models\AnalystLoadStabilityReport::class,
        'infrastructure_pressure_runs' => \App\Models\InfrastructurePressureRun::class,
        'telemetry_growth_drift_reports' => \App\Models\TelemetryGrowthDriftReport::class,
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports' => \App\Models\TelemetryTrendReport::class,
        'analyst_behavior_trends' => \App\Models\AnalystBehaviorTrend::class,
        'false_positive_evolution_reports' => \App\Models\FalsePositiveEvolutionReport::class,
        'operational_drift_history' => \App\Models\OperationalDriftHistory::class,
        'governance_reporting_runs' => \App\Models\GovernanceReportingRun::class,
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage' => \App\Models\EndpointFileHashLineage::class,
        'endpoint_module_loads' => \App\Models\EndpointModuleLoad::class,
        'endpoint_registry_timelines' => \App\Models\EndpointRegistryTimeline::class,
        'endpoint_socket_lifecycle' => \App\Models\EndpointSocketLifecycle::class,
        'endpoint_anti_evasion_indicators' => \App\Models\EndpointAntiEvasionIndicator::class,
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports' => \App\Models\DeploymentIntegrityReport::class,
        'rollout_validation_runs' => \App\Models\RolloutValidationRun::class,
        'deployment_upgrade_history' => \App\Models\DeploymentUpgradeHistory::class,
        'deployment_drift_reports' => \App\Models\DeploymentDriftReport::class,
        'environment_validation_reports' => \App\Models\EnvironmentValidationReport::class,
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs' => \App\Models\OperationalRecoveryRun::class,
        'service_lifecycle_audit' => \App\Models\ServiceLifecycleAudit::class,
        'failover_validation_runs' => \App\Models\FailoverValidationRun::class,
        'operational_continuity_reports' => \App\Models\OperationalContinuityReport::class,
        'recovery_simulation_runs' => \App\Models\RecoverySimulationRun::class,
        // Commercial Readiness & Productization Phase 1
        'tenant_onboarding_runs' => \App\Models\TenantOnboardingRun::class,
        'commercial_release_history' => \App\Models\CommercialReleaseHistory::class,
        'support_bundle_exports' => \App\Models\SupportBundleExport::class,
        'deployment_readiness_reports' => \App\Models\DeploymentReadinessReport::class,
        'release_compatibility_reports' => \App\Models\ReleaseCompatibilityReport::class,
        // Enterprise Scale Architecture & HA Governance Phase 1
        'cluster_topology_reports' => \App\Models\ClusterTopologyReport::class,
        'ha_validation_runs' => \App\Models\HaValidationRun::class,
        'telemetry_distribution_reports' => \App\Models\TelemetryDistributionReport::class,
        'failover_coordination_history' => \App\Models\FailoverCoordinationHistory::class,
        'infrastructure_cost_reports' => \App\Models\InfrastructureCostReport::class,
        // Release Candidate Stabilization & Pilot Deployment Preparation Phase 1
        'release_candidate_manifests' => \App\Models\ReleaseCandidateManifest::class,
        'feature_freeze_audit' => \App\Models\FeatureFreezeAudit::class,
        'deployment_artifact_reports' => \App\Models\DeploymentArtifactReport::class,
        'pilot_deployment_preparation_runs' => \App\Models\PilotDeploymentPreparationRun::class,
        'deployment_reproducibility_reports' => \App\Models\DeploymentReproducibilityReport::class,
        // Code-Level XDR Maturity Acceleration Phase 1
        'synthetic_attack_fixtures' => \App\Models\SyntheticAttackFixture::class,
        'detection_quality_scorecards' => \App\Models\DetectionQualityScorecard::class,
        'false_positive_negative_reports' => \App\Models\FalsePositiveNegativeReport::class,
        'telemetry_quality_scorecards' => \App\Models\TelemetryQualityScorecard::class,
        'xdr_maturity_scorecards' => \App\Models\XdrMaturityScorecard::class,
        // Final XDR Readiness Certification Phase 1
        'xdr_readiness_certifications' => \App\Models\XdrReadinessCertification::class,
        'production_acceptance_gates' => \App\Models\ProductionAcceptanceGate::class,
        'operational_limitation_reports' => \App\Models\OperationalLimitationReport::class,
        'production_risk_register' => \App\Models\ProductionRiskRegister::class,
        'go_live_validation_runs' => \App\Models\GoLiveValidationRun::class,
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks' => \App\Models\SoarPlaybook::class,
        'soar_execution_plans' => \App\Models\SoarExecutionPlan::class,
        'soar_execution_results' => \App\Models\SoarExecutionResult::class,
        'soar_approval_requests' => \App\Models\SoarApprovalRequest::class,
        'soar_simulation_results' => \App\Models\SoarSimulationResult::class,
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes' => \App\Models\InvestigationGraphNode::class,
        'investigation_graph_edges' => \App\Models\InvestigationGraphEdge::class,
        'investigation_sessions' => \App\Models\InvestigationSession::class,
        'retrospective_hunt_queries' => \App\Models\RetrospectiveHuntQuery::class,
        'investigation_timeline_events' => \App\Models\InvestigationTimelineEvent::class,
        // Final Demo / Portfolio / Thesis Packaging Phase 1
        'demo_scenario_runs' => \App\Models\DemoScenarioRun::class,
        'demo_readiness_snapshots' => \App\Models\DemoReadinessSnapshot::class,
        'platform_showcase_exports' => \App\Models\PlatformShowcaseExport::class,
        // Shadow Domain Soak Harness — BACKLOG-PROMOTION-018
        'shadow_soak_runs' => \App\Models\ShadowSoakRun::class,
        'shadow_soak_gate_checks' => \App\Models\ShadowSoakGateCheck::class,
        'shadow_soak_evidence_snapshots' => \App\Models\ShadowSoakEvidenceSnapshot::class,
        // Asset Inventory / CMDB — ASSET-INVENTORY
        'asset_inventory' => \App\Models\AssetInventory::class,
        'asset_criticality' => \App\Models\AssetCriticality::class,
        // Data Residency / GDPR Erasure — DATA-RESIDENCY-ERASURE
        'tenant_retention_policies' => \App\Models\TenantRetentionPolicy::class,
        'data_erasure_requests' => \App\Models\DataErasureRequest::class,
    ];

    private const DOMAIN_TIME_COLUMN = [
        'processes' => 'created_at',
        'persistence_items' => 'last_seen_at',
        'execution_chains' => 'detected_at',
        'beacon_patterns' => 'detected_at',
        'behavioral_findings' => 'detected_at',
        'hosts' => 'updated_at',
        'network_correlations' => 'created_at',
        'alerts' => 'detected_at',
        'cross_domain_correlations' => 'created_at',
        'endpoint_stream_events' => 'occurred_at',
        'dns_events' => 'occurred_at',
        'proxy_events' => 'occurred_at',
        'firewall_events' => 'occurred_at',
        'network_behavioral_findings' => 'created_at',
        'identity_provider_events' => 'occurred_at',
        'saas_audit_events' => 'occurred_at',
        'notification_events' => 'created_at',
        'external_case_links' => 'created_at',
        // UEBA Phase 1
        'entity_behavior_baselines' => 'computed_at',
        'baseline_anomaly_scores' => 'scored_at',
        'peer_group_profiles' => 'computed_at',
        // Endpoint Fleet Hardening Phase 1
        'endpoint_agents' => 'last_seen_at',
        'endpoint_agent_heartbeats' => 'heartbeat_at',
        'endpoint_agent_policy_assignments' => 'assigned_at',
        'endpoint_agent_enrollment_events' => 'occurred_at',
        // Low-level endpoint telemetry — Phase 1
        'endpoint_process_executions' => 'created_at',
        'endpoint_network_connections' => 'created_at',
        'endpoint_script_executions' => 'occurred_at',
        'endpoint_persistence_indicators' => 'last_seen_at',
        'endpoint_privilege_escalations' => 'occurred_at',
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions' => 'created_at',
        'detection_replay_results' => 'created_at',
        'detection_false_positive_reports' => 'created_at',
        'detection_attack_mappings' => 'created_at',
        'detection_suppressions' => 'created_at',
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots' => 'created_at',
        'replay_economics_runs' => 'created_at',
        'query_performance_snapshots' => 'created_at',
        'storage_capacity_snapshots' => 'created_at',
        'capacity_projection_runs' => 'created_at',
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs' => 'created_at',
        'audit_export_requests' => 'created_at',
        'tenant_isolation_validation_runs' => 'created_at',
        'pii_access_audit' => 'created_at',
        'governance_access_reviews' => 'created_at',
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats' => 'updated_at',
        'stream_consumer_lag_snapshots' => 'created_at',
        'duplicate_event_reports' => 'created_at',
        'degraded_mode_events' => 'created_at',
        'recovery_validation_runs' => 'created_at',
        // Production Readiness / Release Governance Phase 1
        'release_manifests' => 'created_at',
        'deployment_readiness_runs' => 'created_at',
        'environment_drift_reports' => 'created_at',
        'rollback_validation_runs' => 'created_at',
        'go_nogo_decisions' => 'created_at',
        // Sensor Hardening Phase 2
        'collector_health_events' => 'created_at',
        'telemetry_gap_reports' => 'created_at',
        'telemetry_integrity_runs' => 'created_at',
        'package_signature_validations' => 'created_at',
        'offline_recovery_runs' => 'created_at',
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs' => 'created_at',
        'attack_chain_timelines' => 'occurred_at',
        'chained_detection_graphs' => 'created_at',
        'evasion_resilience_reports' => 'created_at',
        'cross_host_correlation_runs' => 'created_at',
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits' => 'created_at',
        'tenant_replay_validation_runs' => 'created_at',
        'tenant_graph_isolation_reports' => 'created_at',
        'tenant_boundary_violation_reports' => 'created_at',
        'tenant_evidence_integrity_reports' => 'created_at',
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs' => 'created_at',
        'chaos_simulation_runs' => 'created_at',
        'recovery_validation_artifacts' => 'created_at',
        'operational_drift_reports' => 'created_at',
        'replay_recovery_runs' => 'created_at',
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs' => 'created_at',
        'pilot_health_validations' => 'created_at',
        'pilot_success_metrics' => 'created_at',
        'pilot_rollback_validations' => 'created_at',
        'operator_readiness_reviews' => 'created_at',
        // Real Pilot Execution Phase 1
        'live_pilot_runs' => 'created_at',
        'pilot_health_checkpoints' => 'created_at',
        'pilot_operational_reviews' => 'created_at',
        'live_telemetry_validations' => 'created_at',
        'production_observation_checkpoints' => 'created_at',
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots' => 'created_at',
        'analyst_investigation_summaries' => 'created_at',
        'detection_confidence_history' => 'created_at',
        'false_positive_drift_reports' => 'created_at',
        'attack_progression_scores' => 'created_at',
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots' => 'created_at',
        'alert_prioritization_scores' => 'created_at',
        'false_positive_tuning_reports' => 'created_at',
        'escalation_quality_reviews' => 'created_at',
        'operational_fatigue_indicators' => 'created_at',
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs' => 'created_at',
        'replay_scale_recovery_runs' => 'created_at',
        'analyst_load_stability_reports' => 'created_at',
        'infrastructure_pressure_runs' => 'created_at',
        'telemetry_growth_drift_reports' => 'created_at',
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports' => 'created_at',
        'analyst_behavior_trends' => 'created_at',
        'false_positive_evolution_reports' => 'created_at',
        'operational_drift_history' => 'created_at',
        'governance_reporting_runs' => 'created_at',
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage' => 'created_at',
        'endpoint_module_loads' => 'created_at',
        'endpoint_registry_timelines' => 'created_at',
        'endpoint_socket_lifecycle' => 'created_at',
        'endpoint_anti_evasion_indicators' => 'created_at',
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports' => 'created_at',
        'rollout_validation_runs' => 'created_at',
        'deployment_upgrade_history' => 'created_at',
        'deployment_drift_reports' => 'created_at',
        'environment_validation_reports' => 'created_at',
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs' => 'created_at',
        'service_lifecycle_audit' => 'created_at',
        'failover_validation_runs' => 'created_at',
        'operational_continuity_reports' => 'created_at',
        'recovery_simulation_runs' => 'created_at',
        // Commercial Readiness & Productization Phase 1
        'tenant_onboarding_runs' => 'created_at',
        'commercial_release_history' => 'created_at',
        'support_bundle_exports' => 'created_at',
        'deployment_readiness_reports' => 'created_at',
        'release_compatibility_reports' => 'created_at',
        // Enterprise Scale Architecture & HA Governance Phase 1
        'cluster_topology_reports' => 'created_at',
        'ha_validation_runs' => 'created_at',
        'telemetry_distribution_reports' => 'created_at',
        'failover_coordination_history' => 'created_at',
        'infrastructure_cost_reports' => 'created_at',
        // Release Candidate Stabilization & Pilot Deployment Preparation Phase 1
        'release_candidate_manifests' => 'created_at',
        'feature_freeze_audit' => 'created_at',
        'deployment_artifact_reports' => 'created_at',
        'pilot_deployment_preparation_runs' => 'created_at',
        'deployment_reproducibility_reports' => 'created_at',
        // Code-Level XDR Maturity Acceleration Phase 1
        'synthetic_attack_fixtures' => 'created_at',
        'detection_quality_scorecards' => 'created_at',
        'false_positive_negative_reports' => 'created_at',
        'telemetry_quality_scorecards' => 'created_at',
        'xdr_maturity_scorecards' => 'created_at',
        // Final XDR Readiness Certification Phase 1
        'xdr_readiness_certifications' => 'created_at',
        'production_acceptance_gates' => 'created_at',
        'operational_limitation_reports' => 'created_at',
        'production_risk_register' => 'created_at',
        'go_live_validation_runs' => 'created_at',
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks' => 'created_at',
        'soar_execution_plans' => 'created_at',
        'soar_execution_results' => 'created_at',
        'soar_approval_requests' => 'created_at',
        'soar_simulation_results' => 'created_at',
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes' => 'created_at',
        'investigation_graph_edges' => 'created_at',
        'investigation_sessions' => 'created_at',
        'retrospective_hunt_queries' => 'created_at',
        'investigation_timeline_events' => 'occurred_at',
        // Final Demo / Portfolio / Thesis Packaging Phase 1
        'demo_scenario_runs' => 'created_at',
        'demo_readiness_snapshots' => 'created_at',
        'platform_showcase_exports' => 'created_at',
        // Shadow Domain Soak Harness — BACKLOG-PROMOTION-018
        'shadow_soak_runs' => 'started_at',
        'shadow_soak_gate_checks' => 'created_at',
        'shadow_soak_evidence_snapshots' => 'created_at',
    ];

    // -----------------------------------------------------------------------
    // Hunt execution
    // -----------------------------------------------------------------------

    /**
     * Execute a structured hunt, persist it, and return the hunt record.
     * Advisory-only — no side effects on source telemetry.
     */
    public function executeHunt(array $data, ?User $user = null): ThreatHunt
    {
        $domain = $data['query_domain'] ?? 'processes';
        $filters = $data['query_filters'] ?? [];
        $timeStart = $data['time_range_start'] ?? null;
        $timeEnd = $data['time_range_end'] ?? null;
        $maxResults = min((int) ($data['max_results'] ?? self::DEFAULT_MAX_RESULTS), self::MAX_RESULTS);
        $title = $data['title'] ?? 'Untitled Hunt';
        $traceId = $data['trace_id'] ?? (string) Str::uuid();
        $scope = $data['replay_scope'] ?? ThreatHunt::SCOPE_LIVE;
        $tenantId = $data['tenant_id'] ?? null;

        // Validate and clamp time range
        [$timeStart, $timeEnd] = $this->validateTimeRange($timeStart, $timeEnd);

        // Validate domain + filters (throws on invalid input)
        $this->validateQueryFilters($domain, $filters);
        $this->assertTenantDomainSupported($domain, $tenantId);

        // Execute before persisting so append-only hunt rows can be written once
        // with their final status and result count.
        $results = $this->executeQuery($domain, $filters, $timeStart, $timeEnd, $maxResults, $tenantId);
        $resultType = $this->domainToResultType($domain);
        $resultCount = $results->count();
        $status = $resultCount > 0 ? ThreatHunt::STATUS_COMPLETED : ThreatHunt::STATUS_EMPTY;

        return DB::transaction(function () use (
            $data,
            $domain,
            $filters,
            $maxResults,
            $resultCount,
            $resultType,
            $results,
            $scope,
            $status,
            $tenantId,
            $timeEnd,
            $timeStart,
            $title,
            $traceId,
            $user,
        ): ThreatHunt {
            $hunt = ThreatHunt::create([
                'hunt_id' => ThreatHunt::generateHuntId(),
                'title' => substr($title, 0, 255),
                'description' => $data['description'] ?? null,
                'created_by' => $user?->id,
                'executed_at' => now(),
                'replay_scope' => $scope,
                'status' => $status,
                'result_count' => $resultCount,
                'trace_id' => $traceId,
                'tenant_id' => $tenantId,
            ]);

            ThreatHuntQuery::create([
                'hunt_id' => $hunt->id,
                'query_domain' => $domain,
                'query_filters' => $filters,
                'time_range_start' => $timeStart,
                'time_range_end' => $timeEnd,
                'max_results' => $maxResults,
                'tenant_id' => $tenantId,
            ]);

            $now = now();
            $insertRows = [];
            foreach ($results as $record) {
                $recordArr = is_array($record)
                    ? $record
                    : (method_exists($record, 'toArray') ? $record->toArray() : (array) $record);
                $insertRows[] = [
                    'hunt_id' => $hunt->id,
                    'result_type' => $resultType,
                    'result_source_id' => $recordArr['id'] ?? null,
                    'result_data' => json_encode($this->sanitizeResultData($recordArr)),
                    'trace_id' => $traceId,
                    'created_at' => $now,
                    'tenant_id' => $tenantId,
                ];
            }

            if ($insertRows !== []) {
                DB::table('threat_hunt_results')->insert($insertRows);
            }

            return $hunt->fresh();
        });
    }

    /**
     * Execute a query against the specified domain.
     * All filtering is done via Eloquent query builder — no raw SQL.
     */
    public function executeQuery(
        string $domain,
        array $filters,
        ?string $timeStart,
        ?string $timeEnd,
        int $maxResults = self::DEFAULT_MAX_RESULTS,
        ?string $tenantId = null
    ): Collection {
        $this->validateQueryFilters($domain, $filters);
        $this->assertTenantDomainSupported($domain, $tenantId);
        $maxResults = min($maxResults, self::MAX_RESULTS);

        if ($domain === 'alerts') {
            return $this->queryAlerts($filters, $timeStart, $timeEnd, $maxResults, $tenantId);
        }

        $modelClass = self::DOMAIN_MODEL_MAP[$domain];
        $timeColumn = self::DOMAIN_TIME_COLUMN[$domain] ?? 'created_at';

        $query = $modelClass::query();
        if ($tenantId !== null) {
            $table = $query->getModel()->getTable();
            $query->where($table.'.tenant_id', $tenantId);
        }

        // Apply allowlisted filters
        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? '';

            if (! isset(ThreatHuntQueryAllowlist::DOMAIN_FIELDS[$domain][$field])) {
                continue;
            }
            if (! in_array($operator, ThreatHuntQueryAllowlist::DOMAIN_FIELDS[$domain][$field], true)) {
                continue;
            }

            if ($operator === 'contains') {
                $query->where($field, 'like', '%'.addcslashes((string) $value, '%_\\').'%');
            } else {
                $query->where($field, $operator, $value);
            }
        }

        // Apply time range
        if ($timeStart) {
            $query->where($timeColumn, '>=', $timeStart);
        }
        if ($timeEnd) {
            $query->where($timeColumn, '<=', $timeEnd);
        }

        return $query->orderByDesc($timeColumn)->limit($maxResults)->get();
    }

    // -----------------------------------------------------------------------
    // Behavioral pivots
    // -----------------------------------------------------------------------

    /**
     * Pivot on a host: return processes, persistence items, findings, beacon patterns.
     */
    public function pivotHost(string $agentId, ?string $tenantId = null): array
    {
        $agent = EndpointAgent::where('agent_id', $agentId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->first();
        if (! $agent) {
            return ['error' => 'agent_not_found'];
        }

        $latestSnapshot = \App\Models\EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')->first();

        return [
            'agent' => $agent->only(['agent_id', 'hostname', 'platform', 'health_state']),
            'process_count' => $latestSnapshot?->process_count ?? 0,
            'shell_count' => $latestSnapshot?->shell_count ?? 0,
            'persistence_items' => EndpointPersistenceItem::where('agent_id', $agent->id)->count(),
            'findings' => EndpointBehavioralFinding::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(10)
                ->get(['finding_id', 'finding_type', 'severity', 'confidence', 'detected_at'])->toArray(),
            'beacon_patterns' => EndpointBeaconPattern::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(5)
                ->get(['pattern_id', 'process_name', 'remote_ip', 'connection_count'])->toArray(),
        ];
    }

    /**
     * Pivot on a process name: return all occurrences, ancestry, outbound activity.
     */
    public function pivotProcess(string $processName, ?int $agentId = null, ?string $tenantId = null): array
    {
        $query = EndpointProcessEntry::where('process_name', $processName);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }
        $this->scopeEndpointChild($query, $tenantId);

        $entries = $query->orderByDesc('created_at')->limit(20)->get();
        $outbound = EndpointNetworkCorrelation::where('process_name', $processName)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->when($tenantId !== null, fn ($q) => $q->whereIn('agent_id', $this->tenantAgentIds($tenantId)))
            ->orderByDesc('created_at')->limit(20)->get();

        return [
            'process_name' => $processName,
            'occurrences' => $entries->map(fn ($e) => [
                'pid' => $e->pid,
                'parent_name' => $e->parent_process_name,
                'user' => $e->user,
                'is_shell' => $e->is_shell,
                'is_suspicious' => $e->is_suspicious,
                'duration_s' => $e->duration_seconds,
            ])->all(),
            'outbound_connections' => $outbound->map(fn ($c) => [
                'remote_ip' => $c->remote_ip,
                'remote_port' => $c->remote_port,
                'proto' => $c->proto,
                'confidence' => $c->correlation_confidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a persistence item: return related processes and findings.
     */
    public function pivotPersistence(string $itemKey, ?int $agentId = null, ?string $tenantId = null): array
    {
        $query = EndpointPersistenceItem::where('item_key', $itemKey);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }
        $this->scopeEndpointChild($query, $tenantId);
        $item = $query->first();
        if (! $item) {
            return ['error' => 'persistence_item_not_found'];
        }

        $relatedFindings = EndpointBehavioralFinding::where('agent_id', $item->agent_id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION)
            ->orderByDesc('detected_at')->limit(10)->get();

        return [
            'item_key' => $item->item_key,
            'item_type' => $item->item_type,
            'item_name' => $item->item_name,
            'is_new' => $item->is_new,
            'first_seen' => $item->first_seen_at?->toIso8601String(),
            'last_seen' => $item->last_seen_at?->toIso8601String(),
            'related_findings' => $relatedFindings->map(fn ($f) => [
                'finding_id' => $f->finding_id,
                'confidence' => $f->confidence,
                'evidence' => $f->evidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a trace_id: return all telemetry correlated by trace.
     */
    public function pivotTrace(string $traceId, ?string $tenantId = null): array
    {
        $sanitizedTrace = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $traceId), 0, 120);
        if (! $sanitizedTrace) {
            return ['error' => 'invalid_trace_id'];
        }

        return [
            'trace_id' => $sanitizedTrace,
            'snapshots' => \App\Models\EndpointProcessSnapshot::where('trace_id', $sanitizedTrace)
                ->when($tenantId !== null, fn ($q) => $q->whereIn('agent_id', $this->tenantAgentIds($tenantId)))
                ->get(['snapshot_id', 'agent_id', 'collected_at', 'process_count'])->toArray(),
            'findings' => EndpointBehavioralFinding::where('trace_id', $sanitizedTrace)
                ->when($tenantId !== null, fn ($q) => $q->whereIn('agent_id', $this->tenantAgentIds($tenantId)))
                ->get(['finding_id', 'finding_type', 'severity'])->toArray(),
            'chains' => EndpointExecutionChain::where('trace_id', $sanitizedTrace)
                ->when($tenantId !== null, fn ($q) => $q->whereIn('agent_id', $this->tenantAgentIds($tenantId)))
                ->get(['chain_id', 'chain_score', 'chain_length'])->toArray(),
        ];
    }

    /**
     * Pivot on an entity: return its relationships via bounded graph traversal.
     */
    public function pivotEntity(int $entityId, int $depth = 2, ?string $tenantId = null): array
    {
        $depth = min($depth, self::MAX_GRAPH_DEPTH);
        $entity = Entity::whereKey($entityId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->first();
        if (! $entity) {
            return ['error' => 'entity_not_found'];
        }

        return [
            'entity' => $entity->only(['id', 'entity_type', 'entity_key', 'display_name', 'risk_score']),
            'graph' => $this->graphTraversal($entityId, $depth, $tenantId),
        ];
    }

    // -----------------------------------------------------------------------
    // Graph traversal — depth-limited BFS, deterministic
    // -----------------------------------------------------------------------

    /**
     * Depth-limited BFS traversal of the entity relationship graph.
     * Deterministic (ordered by relationship ID), replay-safe (read-only).
     */
    public function graphTraversal(int $rootId, int $depth = 3, ?string $tenantId = null): array
    {
        $depth = min($depth, self::MAX_GRAPH_DEPTH);
        $visited = [$rootId => true];
        $nodes = [];
        $edges = [];
        $queue = [[$rootId, 0]];

        while (! empty($queue)) {
            [$nodeId, $level] = array_shift($queue);

            if ($level >= $depth) {
                continue;
            }

            $entity = Entity::whereKey($nodeId)
                ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
                ->first();
            if ($entity) {
                $nodes[$nodeId] = $entity->only(['id', 'entity_type', 'entity_key', 'display_name']);
            }

            // Get relationships (capped at 20 per node to prevent explosion)
            $rels = EntityRelationship::where('source_entity_id', $nodeId)
                ->where(function ($query) use ($nodeId) {
                    $query->where('source_entity_id', $nodeId)->orWhere('target_entity_id', $nodeId);
                })
                ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
                ->orderBy('id')
                ->limit(20)
                ->get();

            foreach ($rels as $rel) {
                $edgeKey = min($rel->source_entity_id, $rel->target_entity_id)
                    .':'.max($rel->source_entity_id, $rel->target_entity_id)
                    .':'.$rel->relationship_type;

                if (! isset($edges[$edgeKey])) {
                    $edges[$edgeKey] = [
                        'source' => $rel->source_entity_id,
                        'target' => $rel->target_entity_id,
                        'type' => $rel->relationship_type,
                        'count' => $rel->observation_count,
                    ];
                }

                $otherId = $rel->source_entity_id === $nodeId
                    ? $rel->target_entity_id
                    : $rel->source_entity_id;

                if (! isset($visited[$otherId])) {
                    $visited[$otherId] = true;
                    $queue[] = [$otherId, $level + 1];
                }
            }
        }

        return [
            'root_id' => $rootId,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'depth' => $depth,
            'node_count' => count($nodes),
        ];
    }

    // -----------------------------------------------------------------------
    // Replay — re-execute a historical hunt with same parameters
    // -----------------------------------------------------------------------

    /**
     * Replay a historical hunt with the same query parameters.
     * Creates a new hunt record (append-only — never mutates the original).
     */
    public function replayHunt(ThreatHunt $originalHunt, ?User $user = null): ThreatHunt
    {
        $originalQuery = $originalHunt->queries()->first();
        if (! $originalQuery) {
            throw new \InvalidArgumentException("Hunt {$originalHunt->hunt_id} has no query to replay.");
        }

        return $this->executeHunt([
            'title' => "Replay of {$originalHunt->hunt_id}: {$originalHunt->title}",
            'description' => "Retrospective replay. Original hunt: {$originalHunt->hunt_id}",
            'query_domain' => $originalQuery->query_domain,
            'query_filters' => $originalQuery->query_filters ?? [],
            'time_range_start' => $originalQuery->time_range_start?->toIso8601String(),
            'time_range_end' => $originalQuery->time_range_end?->toIso8601String(),
            'max_results' => $originalQuery->max_results,
            'replay_scope' => ThreatHunt::SCOPE_REPLAY,
            'trace_id' => (string) Str::uuid(),
            'tenant_id' => $originalHunt->tenant_id,
        ], $user);
    }

    // -----------------------------------------------------------------------
    // Query validation (safety enforcement)
    // -----------------------------------------------------------------------

    /**
     * Validate that all filters use allowlisted fields and operators.
     * Throws \InvalidArgumentException on any violation.
     * NEVER allows raw SQL expressions, field injection, or unsupported domains.
     *
     * Delegates to ThreatHuntQueryAllowlist (CODE-STRUCT-DECOMPOSE) — kept as a
     * thin passthrough so every existing caller's method signature is unchanged.
     */
    public function validateQueryFilters(string $domain, array $filters): void
    {
        ThreatHuntQueryAllowlist::validate($domain, $filters);
    }

    // -----------------------------------------------------------------------
    // Hunt history queries
    // -----------------------------------------------------------------------

    /** Return all supported hunt domain names. */
    public function supportedDomains(): array
    {
        return ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS;
    }

    /**
     * Lightweight hunt shorthand — accepts key-value filters and converts to field-operator-value format.
     * Advisory-only, replay-safe wrapper around executeQuery().
     */
    public function hunt(string $domain, array $kvFilters = [], int $limit = 500): Collection
    {
        $filters = [];
        foreach ($kvFilters as $field => $value) {
            $filters[] = ['field' => $field, 'operator' => '=', 'value' => $value];
        }

        return $this->executeQuery($domain, $filters, null, null, min($limit, self::MAX_RESULTS));
    }

    public function getHuntHistory(int $limit = 50, ?string $tenantId = null): Collection
    {
        return ThreatHunt::with('creator')
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('executed_at')
            ->limit($limit)
            ->get();
    }

    public function getHuntWithResults(string $huntId, ?string $tenantId = null): ?ThreatHunt
    {
        return ThreatHunt::where('hunt_id', $huntId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->with(['queries', 'results'])
            ->first();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateTimeRange(?string $start, ?string $end): array
    {
        $startCarbon = $start ? \Carbon\Carbon::parse($start) : null;
        $endCarbon = $end ? \Carbon\Carbon::parse($end) : null;

        if ($startCarbon && $endCarbon) {
            $windowDays = $startCarbon->diffInDays($endCarbon, false);
            if ($windowDays > self::MAX_QUERY_WINDOW_DAYS) {
                $startCarbon = $endCarbon->copy()->subDays(self::MAX_QUERY_WINDOW_DAYS);
            }
        }

        return [
            $startCarbon?->toDateTimeString(),
            $endCarbon?->toDateTimeString(),
        ];
    }

    private function domainToResultType(string $domain): string
    {
        return match ($domain) {
            'processes' => ThreatHuntResult::TYPE_PROCESS_ENTRY,
            'persistence_items' => ThreatHuntResult::TYPE_PERSISTENCE_ITEM,
            'behavioral_findings' => ThreatHuntResult::TYPE_BEHAVIORAL_FINDING,
            'execution_chains' => ThreatHuntResult::TYPE_EXECUTION_CHAIN,
            'beacon_patterns' => ThreatHuntResult::TYPE_BEACON_PATTERN,
            'network_correlations' => ThreatHuntResult::TYPE_NETWORK_CORRELATION,
            'hosts' => ThreatHuntResult::TYPE_HOST,
            'cross_domain_correlations' => 'cross_domain_correlation',
            default => 'generic',
        };
    }

    private function sanitizeResultData(array $data): array
    {
        // Remove very large fields to keep snapshot storage bounded
        unset($data['chain_steps'], $data['evidence']);

        return array_filter($data, fn ($v) => ! is_array($v) || count($v) < 50);
    }

    private function tenantAgentIds(?string $tenantId): Collection
    {
        if ($tenantId === null) {
            return collect();
        }

        return EndpointAgent::where('tenant_id', $tenantId)->pluck('id');
    }

    private function scopeEndpointChild(Builder $query, ?string $tenantId): void
    {
        if ($tenantId !== null) {
            $query->whereIn('agent_id', $this->tenantAgentIds($tenantId));
        }
    }

    private function assertTenantDomainSupported(string $domain, ?string $tenantId): void
    {
        if ($tenantId === null || $domain === 'alerts') {
            return;
        }
        $modelClass = self::DOMAIN_MODEL_MAP[$domain];
        $table = (new $modelClass)->getTable();
        if (! $this->tenantBoundary->tableHasIsolation($table)) {
            throw new \InvalidArgumentException("Hunt domain '{$domain}' is unavailable in tenant mode because '{$table}' has no enforced tenant boundary");
        }
    }

    private function queryAlerts(array $filters, ?string $timeStart, ?string $timeEnd, int $limit, ?string $tenantId = null): Collection
    {
        $query = \Illuminate\Support\Facades\DB::table('security_alerts');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $allowed = ThreatHuntQueryAllowlist::DOMAIN_FIELDS['alerts'] ?? [];

        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? '';

            if (! isset($allowed[$field])) {
                continue;
            }
            if (! in_array($operator, $allowed[$field], true)) {
                continue;
            }

            if ($operator === 'contains') {
                $query->where($field, 'like', '%'.addcslashes((string) $value, '%_\\').'%');
            } else {
                $query->where($field, $operator, $value);
            }
        }

        if ($timeStart) {
            $query->where('detected_at', '>=', $timeStart);
        }
        if ($timeEnd) {
            $query->where('detected_at', '<=', $timeEnd);
        }

        return collect($query->orderByDesc('detected_at')->limit($limit)->get());
    }

    // -----------------------------------------------------------------------
    // Cross-domain pivot methods — Phase 1 (2026-05-18)
    // All read-only. Advisory-only. No mutations.
    // -----------------------------------------------------------------------

    public function pivotIdentityToHost(string $actorKey): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9@.\-_]/', '', $actorKey), 0, 255);
        if (! $sanitized) {
            return ['error' => 'invalid_actor_key'];
        }

        $correlations = \App\Models\CrossDomainCorrelation::where('actor_key', $sanitized)
            ->orderByDesc('created_at')->limit(20)
            ->get(['correlation_id', 'correlation_type', 'confidence_score',
                'attack_stages', 'involved_hosts', 'time_window_start', 'created_at']);

        $alerts = DB::table('security_alerts')
            ->where('actor_key', $sanitized)
            ->orderByDesc('detected_at')->limit(10)
            ->get(['alert_id', 'alert_type', 'severity', 'detected_at'])->all();

        return [
            'actor_key' => $sanitized,
            'correlations' => $correlations->all(),
            'identity_alerts' => $alerts,
            'advisory_note' => 'Advisory-only. Cross-domain pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotMultiHostDestination(string $ip): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9:.\-]/', '', $ip), 0, 45);
        if (! $sanitized) {
            return ['error' => 'invalid_ip'];
        }

        $beacons = EndpointBeaconPattern::where('remote_ip', $sanitized)
            ->orderByDesc('detected_at')->limit(20)
            ->get(['pattern_id', 'agent_id', 'process_name', 'connection_count', 'detected_at'])->all();

        $correlations = \App\Models\CrossDomainCorrelation::where('primary_entity_key', $sanitized)
            ->where('correlation_type', \App\Models\CrossDomainCorrelation::TYPE_MULTI_HOST)
            ->orderByDesc('created_at')->limit(10)
            ->get(['correlation_id', 'confidence_score', 'involved_hosts', 'created_at'])->all();

        return [
            'ip' => $sanitized,
            'beacons' => $beacons,
            'correlations' => $correlations,
            'advisory_note' => 'Advisory-only. Multi-host destination pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotAttackStage(string $stage): array
    {
        if (! in_array($stage, \App\Models\AttackStageTimeline::STAGES, true)) {
            return ['error' => 'invalid_stage'];
        }

        $timelines = DB::table('attack_stage_timelines')
            ->where('stage', $stage)
            ->orderByDesc('created_at')->limit(30)
            ->get(['timeline_id', 'correlation_id', 'stage_confidence', 'first_seen_at', 'last_seen_at'])->all();

        $corrIds = array_unique(array_column($timelines, 'correlation_id'));
        $correlations = $corrIds
            ? \App\Models\CrossDomainCorrelation::whereIn('id', $corrIds)
                ->get(['correlation_id', 'correlation_type', 'actor_key', 'confidence_score', 'created_at'])->all()
            : [];

        return [
            'stage' => $stage,
            'timelines' => $timelines,
            'correlations' => $correlations,
            'advisory_note' => 'Advisory-only. Attack stage pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotCrossDomainTrace(string $traceId): array
    {
        return app(\App\Services\CrossDomainCorrelationService::class)->stitchCrossDomainTrace($traceId);
    }

    // -----------------------------------------------------------------------
    // Streaming pivot methods — live hunt pivots, advisory-only
    // No autonomous hunt-triggered response. No auto-containment.
    // -----------------------------------------------------------------------

    /**
     * Pivot stream events by process name — short-window correlation.
     */
    public function pivotStreamByProcess(string $processName, int $windowMinutes = 60): array
    {
        $since = now()->subMinutes($windowMinutes);
        $events = \App\Models\EndpointStreamEvent::where('process_name', $processName)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('sequence_id')
            ->limit(200)
            ->get();

        $agents = $events->pluck('agent_id')->unique()->values()->toArray();
        $types = $events->groupBy('event_type')->map->count();

        return [
            'process_name' => $processName,
            'window_minutes' => $windowMinutes,
            'event_count' => $events->count(),
            'agents_affected' => $agents,
            'event_type_breakdown' => $types,
            'advisory_only' => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Pivot stream events by trace ID — real-time trace stitching.
     */
    public function pivotStreamTrace(string $traceId): array
    {
        $events = \App\Models\EndpointStreamEvent::where('trace_id', $traceId)
            ->orderBy('sequence_id')
            ->limit(500)
            ->get();

        $sequenceRange = $events->isEmpty()
            ? null
            : ['min' => $events->first()->sequence_id, 'max' => $events->last()->sequence_id];

        return [
            'trace_id' => $traceId,
            'event_count' => $events->count(),
            'sequence_range' => $sequenceRange,
            'event_types' => $events->pluck('event_type')->unique()->values()->toArray(),
            'agents' => $events->pluck('agent_id')->unique()->values()->toArray(),
            'events' => $events->take(50)->toArray(),
            'advisory_only' => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Rapid beacon investigation — identify repeated execution patterns in streaming events.
     */
    public function pivotStreamBeaconInvestigation(string $agentId, int $windowMinutes = 30): array
    {
        $streamSvc = app(\App\Services\EndpointStreamingService::class);
        $findings = $streamSvc->detectStreamBeaconPattern($agentId, $windowMinutes * 60);
        $gaps = $streamSvc->detectSequenceGaps($agentId);

        return [
            'agent_id' => $agentId,
            'window_minutes' => $windowMinutes,
            'beacon_findings' => $findings,
            'sequence_gaps' => $gaps,
            'advisory_only' => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Stream execution chain inspection — short-window execution chain via streaming events.
     */
    public function pivotStreamExecutionChain(string $agentId, int $windowSeconds = 300): array
    {
        $since = now()->subSeconds($windowSeconds);
        $events = \App\Models\EndpointStreamEvent::where('agent_id', $agentId)
            ->whereIn('event_type', [
                \App\Models\EndpointStreamEvent::TYPE_PROCESS_STARTED,
                \App\Models\EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED,
            ])
            ->where('occurred_at', '>=', $since)
            ->orderBy('sequence_id')
            ->get();

        // Build parent→child chain map
        $chainMap = [];
        foreach ($events as $ev) {
            $ppid = $ev->parent_pid;
            $pid = $ev->process_pid;
            if ($ppid && $pid) {
                $chainMap[$ppid][] = [
                    'pid' => $pid,
                    'name' => $ev->process_name,
                    'type' => $ev->event_type,
                    'occurred' => $ev->occurred_at?->toIso8601String(),
                    'sequence' => $ev->sequence_id,
                ];
            }
        }

        return [
            'agent_id' => $agentId,
            'window_seconds' => $windowSeconds,
            'total_events' => $events->count(),
            'execution_chain' => $chainMap,
            'advisory_only' => true,
            'no_autonomous_response' => true,
        ];
    }
}
