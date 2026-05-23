<?php

namespace App\Services;

use App\Models\BaselineAnomalyScore;
use App\Models\EndpointPrivilegeEscalation;
use App\Models\EndpointScriptExecution;
use App\Models\BaselineObservation;
use App\Models\EndpointAgent;
use App\Models\EndpointAgentEnrollmentEvent;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentPolicyAssignment;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointExecutionChain;
use App\Models\EndpointNetworkCorrelation;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessEntry;
use App\Models\Entity;
use App\Models\EntityBehaviorBaseline;
use App\Models\EntityRelationship;
use App\Models\PeerGroupProfile;
use App\Models\SecurityAlert;
use App\Models\ThreatHunt;
use App\Models\ThreatHuntQuery;
use App\Models\ThreatHuntResult;
use App\Models\User;
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
    // -----------------------------------------------------------------------
    // Safety bounds
    // -----------------------------------------------------------------------

    public const MAX_RESULTS            = 500;
    public const MAX_QUERY_WINDOW_DAYS  = 30;
    public const MAX_GRAPH_DEPTH        = 5;
    public const DEFAULT_MAX_RESULTS    = 100;

    // -----------------------------------------------------------------------
    // Supported query domains and their allowlisted fields
    // -----------------------------------------------------------------------

    public const SUPPORTED_DOMAINS = [
        'processes', 'persistence_items', 'execution_chains',
        'beacon_patterns', 'behavioral_findings', 'hosts',
        'network_correlations', 'alerts',
        'cross_domain_correlations',
        'endpoint_stream_events',
        'dns_events',
        'proxy_events',
        'firewall_events',
        'network_behavioral_findings',
        'identity_provider_events',
        'saas_audit_events',
        'notification_events',
        'external_case_links',
        // UEBA Phase 1 — behavioral baseline analytics domains
        'entity_behavior_baselines',
        'baseline_anomaly_scores',
        'peer_group_profiles',
        // Endpoint Fleet Hardening Phase 1 — operational management domains
        'endpoint_agents',
        'endpoint_agent_heartbeats',
        'endpoint_agent_policy_assignments',
        'endpoint_agent_enrollment_events',
        // Low-level endpoint telemetry domains — Phase 1
        'endpoint_process_executions',
        'endpoint_network_connections',
        'endpoint_script_executions',
        'endpoint_persistence_indicators',
        'endpoint_privilege_escalations',
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions',
        'detection_replay_results',
        'detection_false_positive_reports',
        'detection_attack_mappings',
        'detection_suppressions',
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes',
        'investigation_graph_edges',
        'investigation_sessions',
        'retrospective_hunt_queries',
        'investigation_timeline_events',
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks',
        'soar_execution_plans',
        'soar_execution_results',
        'soar_approval_requests',
        'soar_simulation_results',
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots',
        'replay_economics_runs',
        'query_performance_snapshots',
        'storage_capacity_snapshots',
        'capacity_projection_runs',
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs',
        'audit_export_requests',
        'tenant_isolation_validation_runs',
        'pii_access_audit',
        'governance_access_reviews',
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats',
        'stream_consumer_lag_snapshots',
        'duplicate_event_reports',
        'degraded_mode_events',
        'recovery_validation_runs',
        // Production Readiness / Release Governance Phase 1
        'release_manifests',
        'deployment_readiness_runs',
        'environment_drift_reports',
        'rollback_validation_runs',
        'go_nogo_decisions',
        // Sensor Hardening Phase 2
        'collector_health_events',
        'telemetry_gap_reports',
        'telemetry_integrity_runs',
        'package_signature_validations',
        'offline_recovery_runs',
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs',
        'attack_chain_timelines',
        'chained_detection_graphs',
        'evasion_resilience_reports',
        'cross_host_correlation_runs',
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits',
        'tenant_replay_validation_runs',
        'tenant_graph_isolation_reports',
        'tenant_boundary_violation_reports',
        'tenant_evidence_integrity_reports',
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs',
        'chaos_simulation_runs',
        'recovery_validation_artifacts',
        'operational_drift_reports',
        'replay_recovery_runs',
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs',
        'pilot_health_validations',
        'pilot_success_metrics',
        'pilot_rollback_validations',
        'operator_readiness_reviews',
        // Real Pilot Execution Phase 1
        'live_pilot_runs',
        'pilot_health_checkpoints',
        'pilot_operational_reviews',
        'live_telemetry_validations',
        'production_observation_checkpoints',
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots',
        'analyst_investigation_summaries',
        'detection_confidence_history',
        'false_positive_drift_reports',
        'attack_progression_scores',
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots',
        'alert_prioritization_scores',
        'false_positive_tuning_reports',
        'escalation_quality_reviews',
        'operational_fatigue_indicators',
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs',
        'replay_scale_recovery_runs',
        'analyst_load_stability_reports',
        'infrastructure_pressure_runs',
        'telemetry_growth_drift_reports',
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports',
        'analyst_behavior_trends',
        'false_positive_evolution_reports',
        'operational_drift_history',
        'governance_reporting_runs',
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage',
        'endpoint_module_loads',
        'endpoint_registry_timelines',
        'endpoint_socket_lifecycle',
        'endpoint_anti_evasion_indicators',
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports',
        'rollout_validation_runs',
        'deployment_upgrade_history',
        'deployment_drift_reports',
        'environment_validation_reports',
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs',
        'service_lifecycle_audit',
        'failover_validation_runs',
        'operational_continuity_reports',
        'recovery_simulation_runs',
    ];

    private const DOMAIN_FIELDS = [
        'processes' => [
            'process_name'        => ['=', 'contains'],
            'parent_process_name' => ['=', 'contains'],
            'command_line'        => ['contains'],
            'user'                => ['='],
            'is_shell'            => ['='],
            'is_long_lived'       => ['='],
            'is_suspicious'       => ['='],
            'duration_seconds'    => ['>=', '<=', '>'],
            'trace_id'            => ['='],
        ],
        'persistence_items' => [
            'item_type'  => ['='],
            'item_key'   => ['=', 'contains'],
            'item_name'  => ['contains'],
            'item_path'  => ['contains'],
            'is_new'     => ['='],
        ],
        'execution_chains' => [
            'chain_length'         => ['>=', '<='],
            'chain_score'          => ['>='],
            'involves_shell'       => ['='],
            'involves_outbound'    => ['='],
            'involves_persistence' => ['='],
            'trace_id'             => ['='],
        ],
        'beacon_patterns' => [
            'process_name'              => ['=', 'contains'],
            'remote_ip'                 => ['='],
            'remote_port'               => ['='],
            'connection_count'          => ['>='],
            'destination_reuse_score'   => ['>='],
        ],
        'behavioral_findings' => [
            'finding_type' => ['='],
            'severity'     => ['='],
            'confidence'   => ['>='],
            'title'        => ['contains'],
            'trace_id'     => ['='],
        ],
        'hosts' => [
            'hostname'     => ['=', 'contains'],
            'agent_id'     => ['='],
            'platform'     => ['='],
            'health_state' => ['='],
        ],
        'network_correlations' => [
            'process_name'           => ['=', 'contains'],
            'remote_ip'              => ['='],
            'remote_port'            => ['='],
            'correlation_confidence' => ['>='],
        ],
        'alerts' => [
            'alert_type'  => ['=', 'contains'],
            'actor_key'   => ['='],
            'source_ip'   => ['='],
            'severity'    => ['='],
        ],
        'cross_domain_correlations' => [
            'correlation_type'     => ['='],
            'actor_key'            => ['='],
            'primary_entity_key'   => ['=', 'contains'],
            'primary_entity_type'  => ['='],
            'confidence_score'     => ['>='],
            'trace_id'             => ['='],
        ],
        'endpoint_stream_events' => [
            'event_type'     => ['='],
            'agent_id'       => ['='],
            'host_id'        => ['=', 'contains'],
            'process_name'   => ['=', 'contains'],
            'parent_name'    => ['=', 'contains'],
            'connection_dest'=> ['=', 'contains'],
            'connection_port'=> ['='],
            'persistence_key'=> ['=', 'contains'],
            'persistence_type'=> ['='],
            'sequence_id'    => ['>=', '<='],
            'trace_id'       => ['='],
        ],
        'dns_events' => [
            'host_id'        => ['=', 'contains'],
            'queried_domain' => ['=', 'contains'],
            'query_type'     => ['='],
            'response_code'  => ['='],
            'tld'            => ['='],
            'source_ip'      => ['='],
            'user'           => ['='],
            'is_nxdomain'    => ['='],
            'trace_id'       => ['='],
        ],
        'proxy_events' => [
            'host_id'        => ['=', 'contains'],
            'domain'         => ['=', 'contains'],
            'http_method'    => ['='],
            'status_code'    => ['=', '>=', '<='],
            'source_ip'      => ['='],
            'user'           => ['='],
            'is_denied'      => ['='],
            'trace_id'       => ['='],
        ],
        'firewall_events' => [
            'host_id'          => ['=', 'contains'],
            'source_ip'        => ['='],
            'destination_ip'   => ['='],
            'destination_port' => ['='],
            'action'           => ['='],
            'protocol'         => ['='],
            'is_deny'          => ['='],
            'user'             => ['='],
            'rule_name'        => ['=', 'contains'],
            'trace_id'         => ['='],
        ],
        'network_behavioral_findings' => [
            'finding_type'   => ['='],
            'source_domain'  => ['='],
            'host_id'        => ['=', 'contains'],
            'target_domain'  => ['=', 'contains'],
            'target_ip'      => ['='],
            'severity'       => ['='],
            'user'           => ['='],
            'trace_id'       => ['='],
        ],
        'identity_provider_events' => [
            'provider'            => ['='],
            'event_type'          => ['='],
            'user_email'          => ['=', 'contains'],
            'source_ip'           => ['='],
            'geo_country'         => ['='],
            'mfa_used'            => ['='],
            'is_failed'           => ['='],
            'is_suspicious'       => ['='],
            'risk_score'          => ['>='],
            'failed_attempt_count'=> ['>='],
            'trace_id'            => ['='],
        ],
        'saas_audit_events' => [
            'provider'       => ['='],
            'actor_email'    => ['=', 'contains'],
            'actor_ip'       => ['='],
            'action'         => ['=', 'contains'],
            'resource_type'  => ['='],
            'target_email'   => ['=', 'contains'],
            'is_suspicious'  => ['='],
            'risk_score'     => ['>='],
            'source_country' => ['='],
            'trace_id'       => ['='],
        ],
        'notification_events' => [
            'notification_type' => ['='],
            'severity'          => ['='],
            'channel'           => ['='],
            'requires_analyst_approval' => ['='],
            'trace_id'          => ['='],
        ],
        'external_case_links' => [
            'investigation_id'   => ['='],
            'external_ticket_id' => ['=', 'contains'],
            'link_direction'     => ['='],
            'sync_advisory_only' => ['='],
            'auto_closed'        => ['='],
            'trace_id'           => ['='],
        ],
        // Endpoint Fleet Hardening Phase 1 domains
        'endpoint_agents' => [
            'agent_id'      => ['='],
            'hostname'      => ['=', 'contains'],
            'host_id'       => ['='],
            'platform'      => ['='],
            'os_family'     => ['='],
            'agent_version' => ['=', 'contains'],
            'health_state'  => ['='],
            'status'        => ['='],
            'ip_address'    => ['='],
        ],
        'endpoint_agent_heartbeats' => [
            'agent_id'        => ['='],
            'health_state'    => ['='],
            'signature_valid' => ['='],
            'ip_address'      => ['='],
        ],
        'endpoint_agent_policy_assignments' => [
            'agent_id'          => ['='],
            'policy_id'         => ['='],
            'policy_version'    => ['=', 'contains'],
            'config_hash'       => ['='],
            'assignment_reason' => ['='],
            'applied_to_agent'  => ['='],
            'trace_id'          => ['='],
        ],
        'endpoint_agent_enrollment_events' => [
            'agent_id'      => ['='],
            'event_type'    => ['='],
            'agent_version' => ['=', 'contains'],
            'platform'      => ['='],
            'successful'    => ['='],
            'trace_id'      => ['='],
        ],
        // UEBA Phase 1 domains
        'entity_behavior_baselines' => [
            'entity_key'      => ['=', 'contains'],
            'entity_type'     => ['='],
            'dimension'       => ['='],
            'baseline_mean'   => ['>=', '<='],
            'baseline_stddev' => ['>='],
            'sample_count'    => ['>=', '<='],
            'peer_group_key'  => ['='],
            'advisory_only'   => ['='],
        ],
        'baseline_anomaly_scores' => [
            'entity_key'         => ['=', 'contains'],
            'entity_type'        => ['='],
            'anomaly_type'       => ['='],
            'dimension'          => ['='],
            'z_score'            => ['>=', '<='],
            'percentile_rank'    => ['>=', '<='],
            'confidence'         => ['>='],
            'scoring_method'     => ['='],
            'peer_group_key'     => ['='],
            'is_advisory'        => ['='],
            'trace_ids'          => ['contains'],
        ],
        'peer_group_profiles' => [
            'peer_group_key' => ['=', 'contains'],
            'group_type'     => ['='],
            'group_label'    => ['=', 'contains'],
            'entity_count'   => ['>=', '<='],
            'advisory_only'  => ['='],
        ],
        // Low-level endpoint telemetry domains — Phase 1
        'endpoint_process_executions' => [
            'process_name'        => ['=', 'contains'],
            'parent_process_name' => ['=', 'contains'],
            'command_line'        => ['contains'],
            'user'                => ['='],
            'is_shell'            => ['='],
            'is_suspicious'       => ['='],
            'trace_id'            => ['='],
        ],
        'endpoint_network_connections' => [
            'process_name'            => ['=', 'contains'],
            'remote_ip'               => ['='],
            'remote_port'             => ['='],
            'correlation_confidence'  => ['>='],
            'trace_id'                => ['='],
        ],
        'endpoint_script_executions' => [
            'process_name'        => ['=', 'contains'],
            'parent_process_name' => ['=', 'contains'],
            'script_source'       => ['='],
            'is_encoded'          => ['='],
            'user'                => ['='],
            'telemetry_source'    => ['='],
            'script_hash'         => ['='],
            'trace_id'            => ['='],
        ],
        'endpoint_persistence_indicators' => [
            'item_type'  => ['='],
            'item_key'   => ['=', 'contains'],
            'item_name'  => ['contains'],
            'item_path'  => ['contains'],
            'is_new'     => ['='],
        ],
        'endpoint_privilege_escalations' => [
            'process_name'    => ['=', 'contains'],
            'escalation_type' => ['='],
            'original_user'   => ['='],
            'escalated_user'  => ['='],
            'telemetry_source'=> ['='],
            'confidence'      => ['>='],
            'trace_id'        => ['='],
        ],
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions' => [
            'rule_id'      => ['=', 'contains'],
            'version'      => ['='],
            'rule_hash'    => ['='],
            'stage'        => ['='],
            'status'       => ['='],
            'shadow_only'  => ['='],
            'owner'        => ['=', 'contains'],
        ],
        'detection_replay_results' => [
            'rule_id'                => ['=', 'contains'],
            'pack_id'                => ['='],
            'passed'                 => ['='],
            'evidence_mismatch'      => ['='],
            'trace_id_missing'       => ['='],
            'unexpected_enforcement' => ['='],
            'runner'                 => ['='],
        ],
        'detection_false_positive_reports' => [
            'rule_id'                => ['=', 'contains'],
            'reason_type'            => ['='],
            'alert_id'               => ['='],
            'trace_id'               => ['='],
            'recommends_suppression' => ['='],
            'analyst_verdict'        => ['='],
        ],
        'detection_attack_mappings' => [
            'rule_id'       => ['=', 'contains'],
            'tactic'        => ['=', 'contains'],
            'technique_id'  => ['=', 'contains'],
            'confidence'    => ['>='],
            'mapping_source'=> ['='],
            'is_active'     => ['='],
        ],
        'detection_suppressions' => [
            'rule_id'        => ['=', 'contains'],
            'scope'          => ['='],
            'scope_value'    => ['=', 'contains'],
            'approval_state' => ['='],
            'is_active'      => ['='],
        ],
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots' => [
            'topic'          => ['=', 'contains'],
            'pressure_state' => ['='],
            'window_duration'=> ['='],
            'events_per_sec' => ['>='],
        ],
        'replay_economics_runs' => [
            'consumer_group'         => ['='],
            'topic'                  => ['=', 'contains'],
            'concurrency_state'      => ['='],
            'is_bounded'             => ['='],
            'replay_amplification_ratio' => ['>='],
        ],
        'query_performance_snapshots' => [
            'backend'           => ['='],
            'latency_state'     => ['='],
            'slow_query_count'  => ['>='],
            'p95_latency_ms'    => ['>='],
        ],
        'storage_capacity_snapshots' => [
            'backend'        => ['='],
            'capacity_state' => ['='],
            'shard_pressure_pct' => ['>='],
        ],
        'capacity_projection_runs' => [
            'scope'                  => ['='],
            'queue_pressure_forecast'=> ['='],
            'deterministic'          => ['='],
        ],
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs' => [
            'scope'           => ['='],
            'scope_id'        => ['=', 'contains'],
            'status'          => ['='],
            'triggered_by'    => ['=', 'contains'],
            'integrity_failures'=> ['>='],
        ],
        'audit_export_requests' => [
            'scope'       => ['='],
            'status'      => ['='],
            'pii_masked'  => ['='],
            'tenant_scope'=> ['=', 'contains'],
        ],
        'tenant_isolation_validation_runs' => [
            'tenant_scope'         => ['=', 'contains'],
            'check_type'           => ['='],
            'status'               => ['='],
            'cross_tenant_detected'=> ['='],
        ],
        'pii_access_audit' => [
            'field_name'    => ['=', 'contains'],
            'pii_category'  => ['='],
            'access_context'=> ['='],
            'was_masked'    => ['='],
        ],
        'governance_access_reviews' => [
            'subject_user'  => ['=', 'contains'],
            'privileged_role'=> ['='],
            'review_status' => ['='],
            'is_stale'      => ['='],
        ],
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats' => [
            'service_name'  => ['=', 'contains'],
            'consumer_group'=> ['='],
            'health_state'  => ['='],
            'is_stale'      => ['='],
            'is_stalled'    => ['='],
            'current_lag'   => ['>='],
        ],
        'stream_consumer_lag_snapshots' => [
            'consumer_group' => ['='],
            'topic'          => ['=', 'contains'],
            'pressure_state' => ['='],
            'trend'          => ['='],
            'dlq_growing'    => ['='],
            'lag_count'      => ['>='],
        ],
        'duplicate_event_reports' => [
            'source_topic'  => ['='],
            'trace_id'      => ['='],
            'severity'      => ['='],
            'suppressed'    => ['='],
        ],
        'degraded_mode_events' => [
            'service_name'    => ['='],
            'degraded_reason' => ['='],
            'event_type'      => ['='],
            'auto_cleared'    => ['='],
        ],
        'recovery_validation_runs' => [
            'scenario'               => ['='],
            'status'                 => ['='],
            'trace_propagation_ok'   => ['='],
            'replay_idempotency_ok'  => ['='],
        ],
        // Production Readiness / Release Governance Phase 1
        'release_manifests' => [
            'release_id'      => ['='],
            'release_version' => ['=', 'contains'],
            'status'          => ['='],
            'created_by'      => ['='],
        ],
        'deployment_readiness_runs' => [
            'release_id'      => ['='],
            'overall_verdict' => ['='],
            'triggered_by'    => ['='],
            'rollback_ready'  => ['='],
        ],
        'environment_drift_reports' => [
            'drift_type'  => ['='],
            'component'   => ['=', 'contains'],
            'severity'    => ['='],
            'is_blocking' => ['='],
            'detected_by' => ['='],
        ],
        'rollback_validation_runs' => [
            'release_id'              => ['='],
            'rollback_target_version' => ['='],
            'verdict'                 => ['='],
            'replay_safe'             => ['='],
        ],
        'go_nogo_decisions' => [
            'release_id'  => ['='],
            'decision'    => ['='],
            'decided_by'  => ['='],
            'request_id'  => ['='],
        ],
        // Sensor Hardening Phase 2
        'collector_health_events' => [
            'agent_id'      => ['='],
            'host_id'       => ['=', 'contains'],
            'health_state'  => ['='],
            'event_type'    => ['='],
        ],
        'telemetry_gap_reports' => [
            'agent_id'      => ['='],
            'host_id'       => ['=', 'contains'],
            'recovered'     => ['='],
            'gap_reason'    => ['=', 'contains'],
        ],
        'telemetry_integrity_runs' => [
            'agent_id'    => ['='],
            'verdict'     => ['='],
            'replay_safe' => ['='],
        ],
        'package_signature_validations' => [
            'package_name'   => ['=', 'contains'],
            'verdict'        => ['='],
            'signature_valid'=> ['='],
            'signer'         => ['=', 'contains'],
        ],
        'offline_recovery_runs' => [
            'agent_id'        => ['='],
            'recovery_verdict'=> ['='],
            'replay_complete' => ['='],
        ],
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs' => [
            'scenario_name'   => ['=', 'contains'],
            'attack_tactic'   => ['='],
            'attack_technique'=> ['='],
            'verdict'         => ['='],
            'detected'        => ['='],
            'triggered_by'    => ['='],
        ],
        'attack_chain_timelines' => [
            'tactic'       => ['='],
            'technique_id' => ['='],
            'host_id'      => ['=', 'contains'],
            'actor'        => ['=', 'contains'],
            'event_type'   => ['='],
            'chain_id'     => ['='],
        ],
        'chained_detection_graphs' => [
            'chain_type'   => ['='],
            'host_id'      => ['=', 'contains'],
            'actor'        => ['=', 'contains'],
            'status'       => ['='],
            'triggered_by' => ['='],
        ],
        'evasion_resilience_reports' => [
            'evasion_type'        => ['='],
            'target_rule_id'      => ['=', 'contains'],
            'detection_survived'  => ['='],
            'tested_by'           => ['='],
        ],
        'cross_host_correlation_runs' => [
            'correlation_type'     => ['='],
            'propagation_detected' => ['='],
            'triggered_by'         => ['='],
        ],
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits' => [
            'tenant_id'    => ['=', 'contains'],
            'scope'        => ['='],
            'verdict'      => ['='],
            'isolation_ok' => ['='],
        ],
        'tenant_replay_validation_runs' => [
            'tenant_id'             => ['=', 'contains'],
            'replay_id'             => ['=', 'contains'],
            'verdict'               => ['='],
            'cross_tenant_detected' => ['='],
            'replay_isolated'       => ['='],
        ],
        'tenant_graph_isolation_reports' => [
            'tenant_id'                   => ['=', 'contains'],
            'graph_id'                    => ['=', 'contains'],
            'verdict'                     => ['='],
            'isolation_ok'                => ['='],
            'cross_tenant_edges_detected' => ['='],
        ],
        'tenant_boundary_violation_reports' => [
            'tenant_id'      => ['=', 'contains'],
            'violation_type' => ['='],
            'severity'       => ['='],
        ],
        'tenant_evidence_integrity_reports' => [
            'tenant_id'       => ['=', 'contains'],
            'verdict'         => ['='],
            'cross_tenant_refs' => ['='],
        ],
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs' => [
            'soak_type'   => ['='],
            'status'      => ['='],
            'passed'      => ['='],
        ],
        'chaos_simulation_runs' => [
            'scenario'          => ['='],
            'verdict'           => ['='],
            'recovery_verified' => ['='],
            'replay_safe'       => ['='],
        ],
        'recovery_validation_artifacts' => [
            'recovery_type'              => ['='],
            'verdict'                    => ['='],
            'duplicates_prevented'       => ['='],
            'tenant_isolation_preserved' => ['='],
        ],
        'operational_drift_reports' => [
            'drift_type'              => ['='],
            'drift_exceeds_threshold' => ['='],
        ],
        'replay_recovery_runs' => [
            'trigger'              => ['='],
            'verdict'              => ['='],
            'ordering_preserved'   => ['='],
            'continuity_verified'  => ['='],
        ],
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs' => [
            'tenant_id'  => ['=', 'contains'],
            'status'     => ['='],
        ],
        'pilot_health_validations' => [
            'tenant_id'    => ['=', 'contains'],
            'check_type'   => ['='],
            'check_passed' => ['='],
            'verdict'      => ['='],
        ],
        'pilot_success_metrics' => [
            'tenant_id'   => ['=', 'contains'],
            'metric_name' => ['='],
            'target_met'  => ['='],
        ],
        'pilot_rollback_validations' => [
            'tenant_id'     => ['=', 'contains'],
            'trigger'       => ['='],
            'verdict'       => ['='],
            'rollback_safe' => ['='],
        ],
        'operator_readiness_reviews' => [
            'operator_id'   => ['=', 'contains'],
            'review_type'   => ['='],
            'operator_ready'=> ['='],
            'verdict'       => ['='],
        ],
        // Real Pilot Execution Phase 1
        'live_pilot_runs' => [
            'tenant_id'           => ['=', 'contains'],
            'status'              => ['='],
            'activation_approved' => ['='],
            'rollback_ready'      => ['='],
        ],
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots' => [
            'tenant_id'     => ['=', 'contains'],
            'snapshot_type' => ['='],
            'coverage_score'=> ['='],
        ],
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots' => [
            'analyst_id'        => ['=', 'contains'],
            'tenant_id'         => ['=', 'contains'],
            'overload_indicator'=> ['='],
        ],
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs' => [
            'tenant_id'        => ['=', 'contains'],
            'scale_profile'    => ['='],
            'validation_passed'=> ['='],
        ],
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports' => [
            'tenant_id'      => ['=', 'contains'],
            'window_type'    => ['='],
            'trend_verdict'  => ['='],
        ],
        'analyst_behavior_trends' => [
            'analyst_id'      => ['=', 'contains'],
            'tenant_id'       => ['=', 'contains'],
            'window_type'     => ['='],
            'behavior_stable' => ['='],
        ],
        'false_positive_evolution_reports' => [
            'tenant_id'  => ['=', 'contains'],
            'window_type'=> ['='],
            'fp_verdict' => ['='],
        ],
        'operational_drift_history' => [
            'tenant_id'     => ['=', 'contains'],
            'window_type'   => ['='],
            'drift_verdict' => ['='],
        ],
        'governance_reporting_runs' => [
            'tenant_id'         => ['=', 'contains'],
            'report_type'       => ['='],
            'window_type'       => ['='],
            'governance_verdict'=> ['='],
        ],
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage' => [
            'endpoint_id'            => ['=', 'contains'],
            'tenant_id'              => ['=', 'contains'],
            'file_hash_sha256'       => ['=', 'contains'],
            'process_name'           => ['=', 'contains'],
            'suspicious_propagation' => ['='],
        ],
        'endpoint_module_loads' => [
            'endpoint_id'       => ['=', 'contains'],
            'tenant_id'         => ['=', 'contains'],
            'module_name'       => ['=', 'contains'],
            'process_name'      => ['=', 'contains'],
            'is_signed'         => ['='],
            'suspicious_lineage'=> ['='],
        ],
        'endpoint_registry_timelines' => [
            'endpoint_id'        => ['=', 'contains'],
            'tenant_id'          => ['=', 'contains'],
            'key_category'       => ['='],
            'modification_type'  => ['='],
            'process_name'       => ['=', 'contains'],
            'suspicious_lineage' => ['='],
        ],
        'endpoint_socket_lifecycle' => [
            'endpoint_id'    => ['=', 'contains'],
            'tenant_id'      => ['=', 'contains'],
            'process_name'   => ['=', 'contains'],
            'protocol'       => ['='],
            'state'          => ['='],
            'suspicious_port'=> ['='],
        ],
        'endpoint_anti_evasion_indicators' => [
            'endpoint_id'      => ['=', 'contains'],
            'tenant_id'        => ['=', 'contains'],
            'indicator_type'   => ['='],
            'severity'         => ['='],
            'evasion_confirmed'=> ['='],
        ],
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports' => [
            'package_id'                => ['=', 'contains'],
            'version'                   => ['=', 'contains'],
            'validation_passed'         => ['='],
            'hash_match'                => ['='],
            'dependency_drift_detected' => ['='],
        ],
        'rollout_validation_runs' => [
            'rollout_id'         => ['=', 'contains'],
            'stage'              => ['='],
            'rollout_success'    => ['='],
            'checkpoint_passed'  => ['='],
            'bounded_concurrency'=> ['='],
        ],
        'deployment_upgrade_history' => [
            'from_version'           => ['=', 'contains'],
            'to_version'             => ['=', 'contains'],
            'migration_passed'       => ['='],
            'rollback_compatible'    => ['='],
            'compatibility_verified' => ['='],
        ],
        'deployment_drift_reports' => [
            'service'          => ['=', 'contains'],
            'expected_version' => ['=', 'contains'],
            'actual_version'   => ['=', 'contains'],
            'drift_detected'   => ['='],
            'drift_severity'   => ['='],
        ],
        'environment_validation_reports' => [
            'environment'      => ['='],
            'services_healthy' => ['='],
            'queue_available'  => ['='],
            'storage_available'=> ['='],
            'overall_valid'    => ['='],
        ],
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs' => [
            'service_name'     => ['=', 'contains'],
            'recovery_type'    => ['='],
            'recovery_state'   => ['='],
            'replay_continuous'=> ['='],
            'bounded_scope'    => ['='],
        ],
        'service_lifecycle_audit' => [
            'service_name'         => ['=', 'contains'],
            'lifecycle_event'      => ['='],
            'current_state'        => ['=', 'contains'],
            'drift_detected'       => ['='],
            'dependency_satisfied' => ['='],
        ],
        'failover_validation_runs' => [
            'service_name'        => ['=', 'contains'],
            'failover_type'       => ['='],
            'readiness_verified'  => ['='],
            'continuity_verified' => ['='],
            'rollback_ready'      => ['='],
        ],
        'operational_continuity_reports' => [
            'environment'          => ['='],
            'replay_continuous'    => ['='],
            'queue_continuous'     => ['='],
            'overall_continuous'   => ['='],
        ],
        'recovery_simulation_runs' => [
            'simulation_type'         => ['='],
            'target_service'          => ['=', 'contains'],
            'simulation_passed'       => ['='],
            'destructive_action_taken'=> ['='],
            'replay_safe'             => ['='],
        ],
        'replay_scale_recovery_runs' => [
            'tenant_id'             => ['=', 'contains'],
            'recovery_successful'   => ['='],
            'amplification_bounded' => ['='],
        ],
        'analyst_load_stability_reports' => [
            'tenant_id'      => ['=', 'contains'],
            'fatigue_detected'=> ['='],
            'workload_stable' => ['='],
        ],
        'infrastructure_pressure_runs' => [
            'tenant_id'              => ['=', 'contains'],
            'pressure_within_bounds' => ['='],
        ],
        'telemetry_growth_drift_reports' => [
            'tenant_id'       => ['=', 'contains'],
            'drift_dimension' => ['='],
            'drift_severity'  => ['='],
            'drift_bounded'   => ['='],
        ],
        'alert_prioritization_scores' => [
            'tenant_id'     => ['=', 'contains'],
            'rule_id'       => ['=', 'contains'],
            'priority_tier' => ['='],
        ],
        'false_positive_tuning_reports' => [
            'tenant_id'     => ['=', 'contains'],
            'rule_id'       => ['=', 'contains'],
            'tuning_action' => ['='],
            'analyst_id'    => ['=', 'contains'],
        ],
        'escalation_quality_reviews' => [
            'tenant_id'   => ['=', 'contains'],
            'reviewed_by' => ['=', 'contains'],
            'verdict'     => ['='],
            'quality_tier'=> ['='],
        ],
        'operational_fatigue_indicators' => [
            'analyst_id'      => ['=', 'contains'],
            'tenant_id'       => ['=', 'contains'],
            'fatigue_detected'=> ['='],
            'fatigue_severity'=> ['='],
        ],
        'analyst_investigation_summaries' => [
            'tenant_id'    => ['=', 'contains'],
            'analyst_id'   => ['=', 'contains'],
            'verdict'      => ['='],
            'attack_tactic'=> ['=', 'contains'],
        ],
        'detection_confidence_history' => [
            'rule_id'           => ['=', 'contains'],
            'tenant_id'         => ['=', 'contains'],
            'confidence_source' => ['='],
            'replay_consistent' => ['='],
        ],
        'false_positive_drift_reports' => [
            'rule_id'        => ['=', 'contains'],
            'tenant_id'      => ['=', 'contains'],
            'drift_direction'=> ['='],
            'suppression_recommended' => ['='],
        ],
        'attack_progression_scores' => [
            'tenant_id'         => ['=', 'contains'],
            'attack_chain_id'   => ['=', 'contains'],
            'chained_confirmed' => ['='],
            'replay_validated'  => ['='],
        ],
        'pilot_health_checkpoints' => [
            'tenant_id'             => ['=', 'contains'],
            'checkpoint_type'       => ['='],
            'health_ok'             => ['='],
        ],
        'pilot_operational_reviews' => [
            'tenant_id'   => ['=', 'contains'],
            'review_type' => ['='],
            'verdict'     => ['='],
            'reviewed_by' => ['=', 'contains'],
        ],
        'live_telemetry_validations' => [
            'tenant_id'         => ['=', 'contains'],
            'validation_passed' => ['='],
            'worker_healthy'    => ['='],
        ],
        'production_observation_checkpoints' => [
            'tenant_id'   => ['=', 'contains'],
            'window_type' => ['='],
            'criteria_met'=> ['='],
        ],
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks' => [
            'status'                  => ['='],
            'execution_scope'         => ['='],
            'simulation_required'     => ['='],
            'dual_approval_required'  => ['='],
            'name'                    => ['=', 'contains'],
        ],
        'soar_execution_plans' => [
            'status'                  => ['='],
            'playbook_id'             => ['='],
            'target_entity_id'        => ['=', 'contains'],
            'blast_radius_score'      => ['>='],
            'simulation_completed'    => ['='],
            'rollback_ready'          => ['='],
        ],
        'soar_execution_results' => [
            'plan_id'     => ['='],
            'result_type' => ['='],
            'success'     => ['='],
            'is_simulation'=> ['='],
            'is_advisory' => ['='],
        ],
        'soar_approval_requests' => [
            'plan_id'       => ['='],
            'approval_type' => ['='],
            'decision'      => ['='],
        ],
        'soar_simulation_results' => [
            'plan_id'            => ['='],
            'blast_radius_score' => ['>='],
            'rollback_ready'     => ['='],
            'is_advisory'        => ['='],
        ],
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes' => [
            'node_type'        => ['='],
            'investigation_id' => ['='],
            'session_id'       => ['='],
            'source_domain'    => ['='],
            'observable_value' => ['=', 'contains'],
            'risk_score'       => ['>='],
            'is_pivot_origin'  => ['='],
            'is_advisory'      => ['='],
        ],
        'investigation_graph_edges' => [
            'investigation_id'  => ['='],
            'session_id'        => ['='],
            'from_node_id'      => ['='],
            'to_node_id'        => ['='],
            'relationship_type' => ['='],
            'confidence'        => ['>='],
        ],
        'investigation_sessions' => [
            'investigation_id' => ['='],
            'status'           => ['='],
            'focus_node_type'  => ['='],
            'hop_depth'        => ['=', '>='],
        ],
        'retrospective_hunt_queries' => [
            'investigation_id' => ['='],
            'domain'           => ['='],
            'status'           => ['='],
            'is_advisory'      => ['='],
            'replay_safe'      => ['='],
        ],
        'investigation_timeline_events' => [
            'investigation_id' => ['='],
            'event_category'   => ['='],
            'mitre_technique'  => ['=', 'contains'],
            'mitre_tactic'     => ['='],
            'actor_entity_id'  => ['='],
            'target_entity_id' => ['='],
            'confidence'       => ['>='],
        ],
    ];

    private const DOMAIN_MODEL_MAP = [
        'processes'           => EndpointProcessEntry::class,
        'persistence_items'   => EndpointPersistenceItem::class,
        'execution_chains'    => EndpointExecutionChain::class,
        'beacon_patterns'     => EndpointBeaconPattern::class,
        'behavioral_findings' => EndpointBehavioralFinding::class,
        'hosts'               => EndpointAgent::class,
        'network_correlations'=> EndpointNetworkCorrelation::class,
        'alerts'                   => null, // handled separately via security_alerts
        'cross_domain_correlations'=> \App\Models\CrossDomainCorrelation::class,
        'endpoint_stream_events'      => \App\Models\EndpointStreamEvent::class,
        'dns_events'                  => \App\Models\DnsEvent::class,
        'proxy_events'                => \App\Models\ProxyEvent::class,
        'firewall_events'             => \App\Models\FirewallEvent::class,
        'network_behavioral_findings' => \App\Models\NetworkBehavioralFinding::class,
        'identity_provider_events'    => \App\Models\IdentityProviderEvent::class,
        'saas_audit_events'           => \App\Models\SaasAuditEvent::class,
        'notification_events'         => \App\Models\NotificationEvent::class,
        'external_case_links'         => \App\Models\ExternalCaseLink::class,
        // UEBA Phase 1
        'entity_behavior_baselines'   => EntityBehaviorBaseline::class,
        'baseline_anomaly_scores'     => BaselineAnomalyScore::class,
        'peer_group_profiles'         => PeerGroupProfile::class,
        // Endpoint Fleet Hardening Phase 1
        'endpoint_agents'                   => EndpointAgent::class,
        'endpoint_agent_heartbeats'         => EndpointAgentHeartbeat::class,
        'endpoint_agent_policy_assignments' => EndpointAgentPolicyAssignment::class,
        'endpoint_agent_enrollment_events'  => EndpointAgentEnrollmentEvent::class,
        // Low-level endpoint telemetry — Phase 1
        'endpoint_process_executions'       => EndpointProcessEntry::class,
        'endpoint_network_connections'      => EndpointNetworkCorrelation::class,
        'endpoint_script_executions'        => EndpointScriptExecution::class,
        'endpoint_persistence_indicators'   => EndpointPersistenceItem::class,
        'endpoint_privilege_escalations'    => EndpointPrivilegeEscalation::class,
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions'           => \App\Models\DetectionRuleVersion::class,
        'detection_replay_results'          => \App\Models\DetectionReplayResult::class,
        'detection_false_positive_reports'  => \App\Models\DetectionFalsePositiveReport::class,
        'detection_attack_mappings'         => \App\Models\DetectionAttackMapping::class,
        'detection_suppressions'            => \App\Models\DetectionSuppression::class,
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots'  => \App\Models\TelemetryCapacitySnapshot::class,
        'replay_economics_runs'         => \App\Models\ReplayEconomicsRun::class,
        'query_performance_snapshots'   => \App\Models\QueryPerformanceSnapshot::class,
        'storage_capacity_snapshots'    => \App\Models\StorageCapacitySnapshot::class,
        'capacity_projection_runs'      => \App\Models\CapacityProjectionRun::class,
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs'             => \App\Models\EvidenceIntegrityRun::class,
        'audit_export_requests'               => \App\Models\AuditExportRequest::class,
        'tenant_isolation_validation_runs'    => \App\Models\TenantIsolationValidationRun::class,
        'pii_access_audit'                    => \App\Models\PiiAccessAudit::class,
        'governance_access_reviews'           => \App\Models\GovernanceAccessReview::class,
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats'        => \App\Models\SystemWorkerHeartbeat::class,
        'stream_consumer_lag_snapshots'   => \App\Models\StreamConsumerLagSnapshot::class,
        'duplicate_event_reports'         => \App\Models\DuplicateEventReport::class,
        'degraded_mode_events'            => \App\Models\DegradedModeEvent::class,
        'recovery_validation_runs'        => \App\Models\RecoveryValidationRun::class,
        // Production Readiness / Release Governance Phase 1
        'release_manifests'           => \App\Models\ReleaseManifest::class,
        'deployment_readiness_runs'   => \App\Models\DeploymentReadinessRun::class,
        'environment_drift_reports'   => \App\Models\EnvironmentDriftReport::class,
        'rollback_validation_runs'    => \App\Models\RollbackValidationRun::class,
        'go_nogo_decisions'           => \App\Models\GoNogoDecision::class,
        // Sensor Hardening Phase 2
        'collector_health_events'         => \App\Models\CollectorHealthEvent::class,
        'telemetry_gap_reports'           => \App\Models\TelemetryGapReport::class,
        'telemetry_integrity_runs'        => \App\Models\TelemetryIntegrityRun::class,
        'package_signature_validations'   => \App\Models\PackageSignatureValidation::class,
        'offline_recovery_runs'           => \App\Models\OfflineRecoveryRun::class,
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs' => \App\Models\AdversarialValidationRun::class,
        'attack_chain_timelines'      => \App\Models\AttackChainTimeline::class,
        'chained_detection_graphs'    => \App\Models\ChainedDetectionGraph::class,
        'evasion_resilience_reports'  => \App\Models\EvasionResilienceReport::class,
        'cross_host_correlation_runs' => \App\Models\CrossHostCorrelationRun::class,
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits'           => \App\Models\TenantIsolationAudit::class,
        'tenant_replay_validation_runs'     => \App\Models\TenantReplayValidationRun::class,
        'tenant_graph_isolation_reports'    => \App\Models\TenantGraphIsolationReport::class,
        'tenant_boundary_violation_reports' => \App\Models\TenantBoundaryViolationReport::class,
        'tenant_evidence_integrity_reports' => \App\Models\TenantEvidenceIntegrityReport::class,
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs'           => \App\Models\SoakValidationRun::class,
        'chaos_simulation_runs'          => \App\Models\ChaosSimulationRun::class,
        'recovery_validation_artifacts'  => \App\Models\RecoveryValidationArtifact::class,
        'operational_drift_reports'      => \App\Models\OperationalDriftReport::class,
        'replay_recovery_runs'           => \App\Models\ReplayRecoveryRun::class,
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs'       => \App\Models\PilotOnboardingRun::class,
        'pilot_health_validations'    => \App\Models\PilotHealthValidation::class,
        'pilot_success_metrics'       => \App\Models\PilotSuccessMetric::class,
        'pilot_rollback_validations'  => \App\Models\PilotRollbackValidation::class,
        'operator_readiness_reviews'  => \App\Models\OperatorReadinessReview::class,
        // Real Pilot Execution Phase 1
        'live_pilot_runs'                      => \App\Models\LivePilotRun::class,
        'pilot_health_checkpoints'             => \App\Models\PilotHealthCheckpoint::class,
        'pilot_operational_reviews'            => \App\Models\PilotOperationalReview::class,
        'live_telemetry_validations'           => \App\Models\LiveTelemetryValidation::class,
        'production_observation_checkpoints'   => \App\Models\ProductionObservationCheckpoint::class,
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots'   => \App\Models\OperationalIntelligenceSnapshot::class,
        'analyst_investigation_summaries'      => \App\Models\AnalystInvestigationSummary::class,
        'detection_confidence_history'         => \App\Models\DetectionConfidenceHistory::class,
        'false_positive_drift_reports'         => \App\Models\FalsePositiveDriftReport::class,
        'attack_progression_scores'            => \App\Models\AttackProgressionScore::class,
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots'      => \App\Models\AnalystWorkloadSnapshot::class,
        'alert_prioritization_scores'     => \App\Models\AlertPrioritizationScore::class,
        'false_positive_tuning_reports'   => \App\Models\FalsePositiveTuningReport::class,
        'escalation_quality_reviews'      => \App\Models\EscalationQualityReview::class,
        'operational_fatigue_indicators'  => \App\Models\OperationalFatigueIndicator::class,
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs' => \App\Models\TelemetryScaleValidationRun::class,
        'replay_scale_recovery_runs'      => \App\Models\ReplayScaleRecoveryRun::class,
        'analyst_load_stability_reports'  => \App\Models\AnalystLoadStabilityReport::class,
        'infrastructure_pressure_runs'    => \App\Models\InfrastructurePressureRun::class,
        'telemetry_growth_drift_reports'  => \App\Models\TelemetryGrowthDriftReport::class,
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports'              => \App\Models\TelemetryTrendReport::class,
        'analyst_behavior_trends'              => \App\Models\AnalystBehaviorTrend::class,
        'false_positive_evolution_reports'     => \App\Models\FalsePositiveEvolutionReport::class,
        'operational_drift_history'            => \App\Models\OperationalDriftHistory::class,
        'governance_reporting_runs'            => \App\Models\GovernanceReportingRun::class,
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage'           => \App\Models\EndpointFileHashLineage::class,
        'endpoint_module_loads'                => \App\Models\EndpointModuleLoad::class,
        'endpoint_registry_timelines'          => \App\Models\EndpointRegistryTimeline::class,
        'endpoint_socket_lifecycle'            => \App\Models\EndpointSocketLifecycle::class,
        'endpoint_anti_evasion_indicators'     => \App\Models\EndpointAntiEvasionIndicator::class,
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports'         => \App\Models\DeploymentIntegrityReport::class,
        'rollout_validation_runs'              => \App\Models\RolloutValidationRun::class,
        'deployment_upgrade_history'           => \App\Models\DeploymentUpgradeHistory::class,
        'deployment_drift_reports'             => \App\Models\DeploymentDriftReport::class,
        'environment_validation_reports'       => \App\Models\EnvironmentValidationReport::class,
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs'            => \App\Models\OperationalRecoveryRun::class,
        'service_lifecycle_audit'              => \App\Models\ServiceLifecycleAudit::class,
        'failover_validation_runs'             => \App\Models\FailoverValidationRun::class,
        'operational_continuity_reports'       => \App\Models\OperationalContinuityReport::class,
        'recovery_simulation_runs'             => \App\Models\RecoverySimulationRun::class,
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks'          => \App\Models\SoarPlaybook::class,
        'soar_execution_plans'    => \App\Models\SoarExecutionPlan::class,
        'soar_execution_results'  => \App\Models\SoarExecutionResult::class,
        'soar_approval_requests'  => \App\Models\SoarApprovalRequest::class,
        'soar_simulation_results' => \App\Models\SoarSimulationResult::class,
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes'     => \App\Models\InvestigationGraphNode::class,
        'investigation_graph_edges'     => \App\Models\InvestigationGraphEdge::class,
        'investigation_sessions'        => \App\Models\InvestigationSession::class,
        'retrospective_hunt_queries'    => \App\Models\RetrospectiveHuntQuery::class,
        'investigation_timeline_events' => \App\Models\InvestigationTimelineEvent::class,
    ];

    private const DOMAIN_TIME_COLUMN = [
        'processes'           => 'created_at',
        'persistence_items'   => 'last_seen_at',
        'execution_chains'    => 'detected_at',
        'beacon_patterns'     => 'detected_at',
        'behavioral_findings' => 'detected_at',
        'hosts'               => 'updated_at',
        'network_correlations'=> 'created_at',
        'alerts'                    => 'detected_at',
        'cross_domain_correlations' => 'created_at',
        'endpoint_stream_events'      => 'occurred_at',
        'dns_events'                  => 'occurred_at',
        'proxy_events'                => 'occurred_at',
        'firewall_events'             => 'occurred_at',
        'network_behavioral_findings' => 'created_at',
        'identity_provider_events'    => 'occurred_at',
        'saas_audit_events'           => 'occurred_at',
        'notification_events'         => 'created_at',
        'external_case_links'         => 'created_at',
        // UEBA Phase 1
        'entity_behavior_baselines'   => 'computed_at',
        'baseline_anomaly_scores'     => 'scored_at',
        'peer_group_profiles'         => 'computed_at',
        // Endpoint Fleet Hardening Phase 1
        'endpoint_agents'                   => 'last_seen_at',
        'endpoint_agent_heartbeats'         => 'heartbeat_at',
        'endpoint_agent_policy_assignments' => 'assigned_at',
        'endpoint_agent_enrollment_events'  => 'occurred_at',
        // Low-level endpoint telemetry — Phase 1
        'endpoint_process_executions'       => 'created_at',
        'endpoint_network_connections'      => 'created_at',
        'endpoint_script_executions'        => 'occurred_at',
        'endpoint_persistence_indicators'   => 'last_seen_at',
        'endpoint_privilege_escalations'    => 'occurred_at',
        // Detection Engineering Lifecycle Phase 1
        'detection_rule_versions'           => 'created_at',
        'detection_replay_results'          => 'created_at',
        'detection_false_positive_reports'  => 'created_at',
        'detection_attack_mappings'         => 'created_at',
        'detection_suppressions'            => 'created_at',
        // Performance / Capacity / Cost Governance Phase 1
        'telemetry_capacity_snapshots'  => 'created_at',
        'replay_economics_runs'         => 'created_at',
        'query_performance_snapshots'   => 'created_at',
        'storage_capacity_snapshots'    => 'created_at',
        'capacity_projection_runs'      => 'created_at',
        // Compliance / Governance / Evidence Integrity Phase 1
        'evidence_integrity_runs'             => 'created_at',
        'audit_export_requests'               => 'created_at',
        'tenant_isolation_validation_runs'    => 'created_at',
        'pii_access_audit'                    => 'created_at',
        'governance_access_reviews'           => 'created_at',
        // HA / Distributed Reliability Phase 1
        'system_worker_heartbeats'        => 'updated_at',
        'stream_consumer_lag_snapshots'   => 'created_at',
        'duplicate_event_reports'         => 'created_at',
        'degraded_mode_events'            => 'created_at',
        'recovery_validation_runs'        => 'created_at',
        // Production Readiness / Release Governance Phase 1
        'release_manifests'           => 'created_at',
        'deployment_readiness_runs'   => 'created_at',
        'environment_drift_reports'   => 'created_at',
        'rollback_validation_runs'    => 'created_at',
        'go_nogo_decisions'           => 'created_at',
        // Sensor Hardening Phase 2
        'collector_health_events'       => 'created_at',
        'telemetry_gap_reports'         => 'created_at',
        'telemetry_integrity_runs'      => 'created_at',
        'package_signature_validations' => 'created_at',
        'offline_recovery_runs'         => 'created_at',
        // Advanced Detection Coverage & Adversarial Validation Phase 1
        'adversarial_validation_runs' => 'created_at',
        'attack_chain_timelines'      => 'occurred_at',
        'chained_detection_graphs'    => 'created_at',
        'evasion_resilience_reports'  => 'created_at',
        'cross_host_correlation_runs' => 'created_at',
        // Multi-Tenant Production Isolation Phase 1
        'tenant_isolation_audits'           => 'created_at',
        'tenant_replay_validation_runs'     => 'created_at',
        'tenant_graph_isolation_reports'    => 'created_at',
        'tenant_boundary_violation_reports' => 'created_at',
        'tenant_evidence_integrity_reports' => 'created_at',
        // Long-Duration Production Soak & Chaos Validation Phase 1
        'soak_validation_runs'          => 'created_at',
        'chaos_simulation_runs'         => 'created_at',
        'recovery_validation_artifacts' => 'created_at',
        'operational_drift_reports'     => 'created_at',
        'replay_recovery_runs'          => 'created_at',
        // Production Pilot Readiness Phase 1
        'pilot_onboarding_runs'       => 'created_at',
        'pilot_health_validations'    => 'created_at',
        'pilot_success_metrics'       => 'created_at',
        'pilot_rollback_validations'  => 'created_at',
        'operator_readiness_reviews'  => 'created_at',
        // Real Pilot Execution Phase 1
        'live_pilot_runs'                    => 'created_at',
        'pilot_health_checkpoints'           => 'created_at',
        'pilot_operational_reviews'          => 'created_at',
        'live_telemetry_validations'         => 'created_at',
        'production_observation_checkpoints' => 'created_at',
        // Operational Intelligence Phase 2
        'operational_intelligence_snapshots' => 'created_at',
        'analyst_investigation_summaries'    => 'created_at',
        'detection_confidence_history'       => 'created_at',
        'false_positive_drift_reports'       => 'created_at',
        'attack_progression_scores'          => 'created_at',
        // Analyst Optimization Phase 1
        'analyst_workload_snapshots'     => 'created_at',
        'alert_prioritization_scores'    => 'created_at',
        'false_positive_tuning_reports'  => 'created_at',
        'escalation_quality_reviews'     => 'created_at',
        'operational_fatigue_indicators' => 'created_at',
        // Telemetry Scale Pilot Phase 1
        'telemetry_scale_validation_runs' => 'created_at',
        'replay_scale_recovery_runs'      => 'created_at',
        'analyst_load_stability_reports'  => 'created_at',
        'infrastructure_pressure_runs'    => 'created_at',
        'telemetry_growth_drift_reports'  => 'created_at',
        // Long-Running Operational Validation Phase 1
        'telemetry_trend_reports'             => 'created_at',
        'analyst_behavior_trends'             => 'created_at',
        'false_positive_evolution_reports'    => 'created_at',
        'operational_drift_history'           => 'created_at',
        'governance_reporting_runs'           => 'created_at',
        // Endpoint Sensor Advanced Telemetry Phase 3
        'endpoint_file_hash_lineage'          => 'created_at',
        'endpoint_module_loads'               => 'created_at',
        'endpoint_registry_timelines'         => 'created_at',
        'endpoint_socket_lifecycle'           => 'created_at',
        'endpoint_anti_evasion_indicators'    => 'created_at',
        // Enterprise Deployment Hardening Phase 1
        'deployment_integrity_reports'        => 'created_at',
        'rollout_validation_runs'             => 'created_at',
        'deployment_upgrade_history'          => 'created_at',
        'deployment_drift_reports'            => 'created_at',
        'environment_validation_reports'      => 'created_at',
        // Enterprise Operations Automation & Recovery Governance Phase 1
        'operational_recovery_runs'           => 'created_at',
        'service_lifecycle_audit'             => 'created_at',
        'failover_validation_runs'            => 'created_at',
        'operational_continuity_reports'      => 'created_at',
        'recovery_simulation_runs'            => 'created_at',
        // SOAR Governance & Response Orchestration Phase 1
        'soar_playbooks'          => 'created_at',
        'soar_execution_plans'    => 'created_at',
        'soar_execution_results'  => 'created_at',
        'soar_approval_requests'  => 'created_at',
        'soar_simulation_results' => 'created_at',
        // Advanced Threat Hunting & Investigation Phase 1
        'investigation_graph_nodes'     => 'created_at',
        'investigation_graph_edges'     => 'created_at',
        'investigation_sessions'        => 'created_at',
        'retrospective_hunt_queries'    => 'created_at',
        'investigation_timeline_events' => 'occurred_at',
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
        $domain    = $data['query_domain'] ?? 'processes';
        $filters   = $data['query_filters'] ?? [];
        $timeStart = $data['time_range_start'] ?? null;
        $timeEnd   = $data['time_range_end'] ?? null;
        $maxResults= min((int) ($data['max_results'] ?? self::DEFAULT_MAX_RESULTS), self::MAX_RESULTS);
        $title     = $data['title'] ?? 'Untitled Hunt';
        $traceId   = $data['trace_id'] ?? (string) Str::uuid();
        $scope     = $data['replay_scope'] ?? ThreatHunt::SCOPE_LIVE;

        // Validate and clamp time range
        [$timeStart, $timeEnd] = $this->validateTimeRange($timeStart, $timeEnd);

        // Validate domain + filters (throws on invalid input)
        $this->validateQueryFilters($domain, $filters);

        $hunt = ThreatHunt::create([
            'hunt_id'     => ThreatHunt::generateHuntId(),
            'title'       => substr($title, 0, 255),
            'description' => $data['description'] ?? null,
            'created_by'  => $user?->id,
            'executed_at' => now(),
            'replay_scope'=> $scope,
            'status'      => ThreatHunt::STATUS_COMPLETED,
            'result_count'=> 0,
            'trace_id'    => $traceId,
        ]);

        ThreatHuntQuery::create([
            'hunt_id'          => $hunt->id,
            'query_domain'     => $domain,
            'query_filters'    => $filters,
            'time_range_start' => $timeStart,
            'time_range_end'   => $timeEnd,
            'max_results'      => $maxResults,
        ]);

        // Execute query
        $results = $this->executeQuery($domain, $filters, $timeStart, $timeEnd, $maxResults);

        // Store results as append-only snapshots
        $resultType = $this->domainToResultType($domain);
        $now = now();
        $insertRows = [];
        foreach ($results as $record) {
            $recordArr = is_array($record) ? $record : $record->toArray();
            $insertRows[] = [
                'hunt_id'          => $hunt->id,
                'result_type'      => $resultType,
                'result_source_id' => $recordArr['id'] ?? null,
                'result_data'      => json_encode($this->sanitizeResultData($recordArr)),
                'trace_id'         => $traceId,
                'created_at'       => $now,
            ];
        }

        if ($insertRows) {
            DB::table('threat_hunt_results')->insert($insertRows);
        }

        $status = count($insertRows) > 0 ? ThreatHunt::STATUS_COMPLETED : ThreatHunt::STATUS_EMPTY;
        $hunt->update(['result_count' => count($insertRows), 'status' => $status]);

        return $hunt->fresh();
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
        int $maxResults = self::DEFAULT_MAX_RESULTS
    ): Collection {
        $this->validateQueryFilters($domain, $filters);
        $maxResults = min($maxResults, self::MAX_RESULTS);

        if ($domain === 'alerts') {
            return $this->queryAlerts($filters, $timeStart, $timeEnd, $maxResults);
        }

        $modelClass = self::DOMAIN_MODEL_MAP[$domain];
        $timeColumn = self::DOMAIN_TIME_COLUMN[$domain] ?? 'created_at';

        $query = $modelClass::query();

        // Apply allowlisted filters
        foreach ($filters as $filter) {
            $field    = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '=';
            $value    = $filter['value'] ?? '';

            if (!isset(self::DOMAIN_FIELDS[$domain][$field])) {
                continue;
            }
            if (!in_array($operator, self::DOMAIN_FIELDS[$domain][$field], true)) {
                continue;
            }

            if ($operator === 'contains') {
                $query->where($field, 'like', '%' . addcslashes((string) $value, '%_\\') . '%');
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
    public function pivotHost(string $agentId): array
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return ['error' => 'agent_not_found'];
        }

        $latestSnapshot = \App\Models\EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')->first();

        return [
            'agent'            => $agent->only(['agent_id', 'hostname', 'platform', 'health_state']),
            'process_count'    => $latestSnapshot?->process_count ?? 0,
            'shell_count'      => $latestSnapshot?->shell_count ?? 0,
            'persistence_items'=> EndpointPersistenceItem::where('agent_id', $agent->id)->count(),
            'findings'         => EndpointBehavioralFinding::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(10)
                ->get(['finding_id', 'finding_type', 'severity', 'confidence', 'detected_at'])->toArray(),
            'beacon_patterns'  => EndpointBeaconPattern::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(5)
                ->get(['pattern_id', 'process_name', 'remote_ip', 'connection_count'])->toArray(),
        ];
    }

    /**
     * Pivot on a process name: return all occurrences, ancestry, outbound activity.
     */
    public function pivotProcess(string $processName, ?int $agentId = null): array
    {
        $query = EndpointProcessEntry::where('process_name', $processName);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        $entries = $query->orderByDesc('created_at')->limit(20)->get();
        $outbound = EndpointNetworkCorrelation::where('process_name', $processName)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->orderByDesc('created_at')->limit(20)->get();

        return [
            'process_name'  => $processName,
            'occurrences'   => $entries->map(fn ($e) => [
                'pid'           => $e->pid,
                'parent_name'   => $e->parent_process_name,
                'user'          => $e->user,
                'is_shell'      => $e->is_shell,
                'is_suspicious' => $e->is_suspicious,
                'duration_s'    => $e->duration_seconds,
            ])->all(),
            'outbound_connections' => $outbound->map(fn ($c) => [
                'remote_ip'   => $c->remote_ip,
                'remote_port' => $c->remote_port,
                'proto'       => $c->proto,
                'confidence'  => $c->correlation_confidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a persistence item: return related processes and findings.
     */
    public function pivotPersistence(string $itemKey, ?int $agentId = null): array
    {
        $query = EndpointPersistenceItem::where('item_key', $itemKey);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }
        $item = $query->first();
        if (!$item) {
            return ['error' => 'persistence_item_not_found'];
        }

        $relatedFindings = EndpointBehavioralFinding::where('agent_id', $item->agent_id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION)
            ->orderByDesc('detected_at')->limit(10)->get();

        return [
            'item_key'    => $item->item_key,
            'item_type'   => $item->item_type,
            'item_name'   => $item->item_name,
            'is_new'      => $item->is_new,
            'first_seen'  => $item->first_seen_at?->toIso8601String(),
            'last_seen'   => $item->last_seen_at?->toIso8601String(),
            'related_findings' => $relatedFindings->map(fn ($f) => [
                'finding_id' => $f->finding_id,
                'confidence' => $f->confidence,
                'evidence'   => $f->evidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a trace_id: return all telemetry correlated by trace.
     */
    public function pivotTrace(string $traceId): array
    {
        $sanitizedTrace = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $traceId), 0, 120);
        if (!$sanitizedTrace) {
            return ['error' => 'invalid_trace_id'];
        }

        return [
            'trace_id' => $sanitizedTrace,
            'snapshots'=> \App\Models\EndpointProcessSnapshot::where('trace_id', $sanitizedTrace)
                ->get(['snapshot_id', 'agent_id', 'collected_at', 'process_count'])->toArray(),
            'findings' => EndpointBehavioralFinding::where('trace_id', $sanitizedTrace)
                ->get(['finding_id', 'finding_type', 'severity'])->toArray(),
            'chains'   => EndpointExecutionChain::where('trace_id', $sanitizedTrace)
                ->get(['chain_id', 'chain_score', 'chain_length'])->toArray(),
        ];
    }

    /**
     * Pivot on an entity: return its relationships via bounded graph traversal.
     */
    public function pivotEntity(int $entityId, int $depth = 2): array
    {
        $depth = min($depth, self::MAX_GRAPH_DEPTH);
        $entity = Entity::find($entityId);
        if (!$entity) {
            return ['error' => 'entity_not_found'];
        }

        return [
            'entity'  => $entity->only(['id', 'entity_type', 'entity_key', 'display_name', 'risk_score']),
            'graph'   => $this->graphTraversal($entityId, $depth),
        ];
    }

    // -----------------------------------------------------------------------
    // Graph traversal — depth-limited BFS, deterministic
    // -----------------------------------------------------------------------

    /**
     * Depth-limited BFS traversal of the entity relationship graph.
     * Deterministic (ordered by relationship ID), replay-safe (read-only).
     */
    public function graphTraversal(int $rootId, int $depth = 3): array
    {
        $depth   = min($depth, self::MAX_GRAPH_DEPTH);
        $visited = [$rootId => true];
        $nodes   = [];
        $edges   = [];
        $queue   = [[$rootId, 0]];

        while (!empty($queue)) {
            [$nodeId, $level] = array_shift($queue);

            if ($level >= $depth) {
                continue;
            }

            $entity = Entity::find($nodeId);
            if ($entity) {
                $nodes[$nodeId] = $entity->only(['id', 'entity_type', 'entity_key', 'display_name']);
            }

            // Get relationships (capped at 20 per node to prevent explosion)
            $rels = EntityRelationship::where('source_entity_id', $nodeId)
                ->orWhere('target_entity_id', $nodeId)
                ->orderBy('id')
                ->limit(20)
                ->get();

            foreach ($rels as $rel) {
                $edgeKey = min($rel->source_entity_id, $rel->target_entity_id)
                    . ':' . max($rel->source_entity_id, $rel->target_entity_id)
                    . ':' . $rel->relationship_type;

                if (!isset($edges[$edgeKey])) {
                    $edges[$edgeKey] = [
                        'source'   => $rel->source_entity_id,
                        'target'   => $rel->target_entity_id,
                        'type'     => $rel->relationship_type,
                        'count'    => $rel->observation_count,
                    ];
                }

                $otherId = $rel->source_entity_id === $nodeId
                    ? $rel->target_entity_id
                    : $rel->source_entity_id;

                if (!isset($visited[$otherId])) {
                    $visited[$otherId] = true;
                    $queue[] = [$otherId, $level + 1];
                }
            }
        }

        return [
            'root_id'  => $rootId,
            'nodes'    => array_values($nodes),
            'edges'    => array_values($edges),
            'depth'    => $depth,
            'node_count'=> count($nodes),
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
        if (!$originalQuery) {
            throw new \InvalidArgumentException("Hunt {$originalHunt->hunt_id} has no query to replay.");
        }

        return $this->executeHunt([
            'title'            => "Replay of {$originalHunt->hunt_id}: {$originalHunt->title}",
            'description'      => "Retrospective replay. Original hunt: {$originalHunt->hunt_id}",
            'query_domain'     => $originalQuery->query_domain,
            'query_filters'    => $originalQuery->query_filters ?? [],
            'time_range_start' => $originalQuery->time_range_start?->toIso8601String(),
            'time_range_end'   => $originalQuery->time_range_end?->toIso8601String(),
            'max_results'      => $originalQuery->max_results,
            'replay_scope'     => ThreatHunt::SCOPE_REPLAY,
            'trace_id'         => (string) Str::uuid(),
        ], $user);
    }

    // -----------------------------------------------------------------------
    // Query validation (safety enforcement)
    // -----------------------------------------------------------------------

    /**
     * Validate that all filters use allowlisted fields and operators.
     * Throws \InvalidArgumentException on any violation.
     * NEVER allows raw SQL expressions, field injection, or unsupported domains.
     */
    public function validateQueryFilters(string $domain, array $filters): void
    {
        if (!in_array($domain, self::SUPPORTED_DOMAINS, true)) {
            throw new \InvalidArgumentException("Unsupported query domain: '{$domain}'.");
        }

        $allowed = self::DOMAIN_FIELDS[$domain] ?? [];

        foreach ($filters as $filter) {
            $field    = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '';

            if (!isset($allowed[$field])) {
                throw new \InvalidArgumentException(
                    "Field '{$field}' is not allowlisted for domain '{$domain}'."
                );
            }
            if (!in_array($operator, $allowed[$field], true)) {
                throw new \InvalidArgumentException(
                    "Operator '{$operator}' is not allowed for field '{$field}' in domain '{$domain}'."
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Hunt history queries
    // -----------------------------------------------------------------------

    /** Return all supported hunt domain names. */
    public function supportedDomains(): array
    {
        return self::SUPPORTED_DOMAINS;
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

    public function getHuntHistory(int $limit = 50): Collection
    {
        return ThreatHunt::with('creator')
            ->orderByDesc('executed_at')
            ->limit($limit)
            ->get();
    }

    public function getHuntWithResults(string $huntId): ?ThreatHunt
    {
        return ThreatHunt::where('hunt_id', $huntId)
            ->with(['queries', 'results'])
            ->first();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateTimeRange(?string $start, ?string $end): array
    {
        $startCarbon = $start ? \Carbon\Carbon::parse($start) : null;
        $endCarbon   = $end   ? \Carbon\Carbon::parse($end)   : null;

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
            'processes'           => ThreatHuntResult::TYPE_PROCESS_ENTRY,
            'persistence_items'   => ThreatHuntResult::TYPE_PERSISTENCE_ITEM,
            'behavioral_findings' => ThreatHuntResult::TYPE_BEHAVIORAL_FINDING,
            'execution_chains'    => ThreatHuntResult::TYPE_EXECUTION_CHAIN,
            'beacon_patterns'     => ThreatHuntResult::TYPE_BEACON_PATTERN,
            'network_correlations'=> ThreatHuntResult::TYPE_NETWORK_CORRELATION,
            'hosts'               => ThreatHuntResult::TYPE_HOST,
            'cross_domain_correlations' => 'cross_domain_correlation',
            default                     => 'generic',
        };
    }

    private function sanitizeResultData(array $data): array
    {
        // Remove very large fields to keep snapshot storage bounded
        unset($data['chain_steps'], $data['evidence']);
        return array_filter($data, fn ($v) => !is_array($v) || count($v) < 50);
    }

    private function queryAlerts(array $filters, ?string $timeStart, ?string $timeEnd, int $limit): Collection
    {
        $query = \Illuminate\Support\Facades\DB::table('security_alerts');
        $allowed = self::DOMAIN_FIELDS['alerts'] ?? [];

        foreach ($filters as $filter) {
            $field    = $filter['field']    ?? '';
            $operator = $filter['operator'] ?? '=';
            $value    = $filter['value']    ?? '';

            if (!isset($allowed[$field])) continue;
            if (!in_array($operator, $allowed[$field], true)) continue;

            if ($operator === 'contains') {
                $query->where($field, 'like', '%' . addcslashes((string)$value, '%_\\') . '%');
            } else {
                $query->where($field, $operator, $value);
            }
        }

        if ($timeStart) $query->where('detected_at', '>=', $timeStart);
        if ($timeEnd)   $query->where('detected_at', '<=', $timeEnd);

        return collect($query->orderByDesc('detected_at')->limit($limit)->get());
    }

    // -----------------------------------------------------------------------
    // Cross-domain pivot methods — Phase 1 (2026-05-18)
    // All read-only. Advisory-only. No mutations.
    // -----------------------------------------------------------------------

    public function pivotIdentityToHost(string $actorKey): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9@.\-_]/', '', $actorKey), 0, 255);
        if (!$sanitized) {
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
            'actor_key'       => $sanitized,
            'correlations'    => $correlations->all(),
            'identity_alerts' => $alerts,
            'advisory_note'   => 'Advisory-only. Cross-domain pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotMultiHostDestination(string $ip): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9:.\-]/', '', $ip), 0, 45);
        if (!$sanitized) {
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
            'ip'           => $sanitized,
            'beacons'      => $beacons,
            'correlations' => $correlations,
            'advisory_note'=> 'Advisory-only. Multi-host destination pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotAttackStage(string $stage): array
    {
        if (!in_array($stage, \App\Models\AttackStageTimeline::STAGES, true)) {
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
            'stage'        => $stage,
            'timelines'    => $timelines,
            'correlations' => $correlations,
            'advisory_note'=> 'Advisory-only. Attack stage pivot is retrospective and non-destructive.',
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
        $since  = now()->subMinutes($windowMinutes);
        $events = \App\Models\EndpointStreamEvent::where('process_name', $processName)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('sequence_id')
            ->limit(200)
            ->get();

        $agents = $events->pluck('agent_id')->unique()->values()->toArray();
        $types  = $events->groupBy('event_type')->map->count();

        return [
            'process_name'   => $processName,
            'window_minutes' => $windowMinutes,
            'event_count'    => $events->count(),
            'agents_affected'=> $agents,
            'event_type_breakdown' => $types,
            'advisory_only'  => true,
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
            'trace_id'       => $traceId,
            'event_count'    => $events->count(),
            'sequence_range' => $sequenceRange,
            'event_types'    => $events->pluck('event_type')->unique()->values()->toArray(),
            'agents'         => $events->pluck('agent_id')->unique()->values()->toArray(),
            'events'         => $events->take(50)->toArray(),
            'advisory_only'  => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Rapid beacon investigation — identify repeated execution patterns in streaming events.
     */
    public function pivotStreamBeaconInvestigation(string $agentId, int $windowMinutes = 30): array
    {
        $streamSvc = app(\App\Services\EndpointStreamingService::class);
        $findings  = $streamSvc->detectStreamBeaconPattern($agentId, $windowMinutes * 60);
        $gaps      = $streamSvc->detectSequenceGaps($agentId);

        return [
            'agent_id'       => $agentId,
            'window_minutes' => $windowMinutes,
            'beacon_findings'=> $findings,
            'sequence_gaps'  => $gaps,
            'advisory_only'  => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Stream execution chain inspection — short-window execution chain via streaming events.
     */
    public function pivotStreamExecutionChain(string $agentId, int $windowSeconds = 300): array
    {
        $since  = now()->subSeconds($windowSeconds);
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
            $pid  = $ev->process_pid;
            if ($ppid && $pid) {
                $chainMap[$ppid][] = [
                    'pid'       => $pid,
                    'name'      => $ev->process_name,
                    'type'      => $ev->event_type,
                    'occurred'  => $ev->occurred_at?->toIso8601String(),
                    'sequence'  => $ev->sequence_id,
                ];
            }
        }

        return [
            'agent_id'          => $agentId,
            'window_seconds'    => $windowSeconds,
            'total_events'      => $events->count(),
            'execution_chain'   => $chainMap,
            'advisory_only'     => true,
            'no_autonomous_response' => true,
        ];
    }
}
