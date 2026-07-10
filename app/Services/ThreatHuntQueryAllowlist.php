<?php

namespace App\Services;

/**
 * ThreatHuntQueryAllowlist — the security-critical query allowlist for
 * ThreatHuntingService, extracted (CODE-STRUCT-DECOMPOSE) so the
 * ~1150-line field/operator allowlist and its validator are an
 * independently reviewable, independently testable unit rather than
 * buried inside a 2500+ line service file. NEVER allows raw SQL
 * expressions, field injection, or unsupported domains.
 *
 * Query fields and operators are strictly allowlisted per domain — this
 * class is the single source of truth for what a hunt query is allowed to
 * touch. Pure data + pure validation logic: no DB/Eloquent dependency.
 */
class ThreatHuntQueryAllowlist
{
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
        // Commercial Readiness & Productization Phase 1
        'tenant_onboarding_runs',
        'commercial_release_history',
        'support_bundle_exports',
        'deployment_readiness_reports',
        'release_compatibility_reports',
        // Enterprise Scale Architecture & HA Governance Phase 1
        'cluster_topology_reports',
        'ha_validation_runs',
        'telemetry_distribution_reports',
        'failover_coordination_history',
        'infrastructure_cost_reports',
        // Final XDR Readiness Certification Phase 1
        'xdr_readiness_certifications',
        'production_acceptance_gates',
        'operational_limitation_reports',
        'production_risk_register',
        'go_live_validation_runs',
        // Release Candidate Stabilization & Pilot Deployment Preparation Phase 1
        'release_candidate_manifests',
        'feature_freeze_audit',
        'deployment_artifact_reports',
        'pilot_deployment_preparation_runs',
        'deployment_reproducibility_reports',
        // Code-Level XDR Maturity Acceleration Phase 1
        'synthetic_attack_fixtures',
        'detection_quality_scorecards',
        'false_positive_negative_reports',
        'telemetry_quality_scorecards',
        'xdr_maturity_scorecards',
        // Final Demo / Portfolio / Thesis Packaging Phase 1
        'demo_scenario_runs',
        'demo_readiness_snapshots',
        'platform_showcase_exports',
        // Shadow Domain Soak Harness — BACKLOG-PROMOTION-018
        'shadow_soak_runs',
        'shadow_soak_gate_checks',
        'shadow_soak_evidence_snapshots',
        // Enterprise Pilot Readiness Matrix — PILOT-034
        'pilot_readiness_matrix_runs',
        'pilot_readiness_gate_evaluations',
        'pilot_readiness_evidence_links',
        // Tenant Backfill + Strict Mode Readiness — ENTERPRISE-065
        'tenant_backfill_audit_runs',
        'tenant_strict_mode_assessments',
        'tenant_strict_mode_gate_results',
        // Redpanda Recovery Hardening — ENTERPRISE-066
        'redpanda_topic_health_runs',
        'redpanda_consumer_group_health_runs',
        'redpanda_recovery_events',
        // RAG Knowledge Base Seeding — ENTERPRISE-067
        'rag_seed_runs',
        'rag_seed_document_log',
        // Security Hardening Evidence Freeze — ENTERPRISE-074
        'security_hardening_freeze_runs',
        'security_hardening_freeze_checks',
        'security_hardening_freeze_coverage_reports',
        'security_hardening_freeze_control_evidence',
        'security_hardening_freeze_audit_events',
        // Asset Inventory / CMDB — ASSET-INVENTORY
        'asset_inventory',
        'asset_criticality',
        // Data Residency / GDPR Erasure — DATA-RESIDENCY-ERASURE
        'tenant_retention_policies',
        'data_erasure_requests',
    ];

    public const DOMAIN_FIELDS = [
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
        'tenant_onboarding_runs' => [
            'tenant_id'         => ['=', 'contains'],
            'onboarding_phase'  => ['='],
            'onboarding_state'  => ['='],
            'validation_passed' => ['='],
            'rollback_capable'  => ['='],
        ],
        'commercial_release_history' => [
            'release_version'      => ['=', 'contains'],
            'release_stage'        => ['='],
            'release_state'        => ['='],
            'migration_compatible' => ['='],
            'rollback_compatible'  => ['='],
        ],
        'support_bundle_exports' => [
            'bundle_type'              => ['='],
            'export_scope'             => ['='],
            'size_bounded'             => ['='],
            'destructive_action_taken' => ['='],
            'replay_safe'              => ['='],
        ],
        'deployment_readiness_reports' => [
            'tenant_id'           => ['=', 'contains'],
            'environment'         => ['='],
            'deployment_ready'    => ['='],
            'onboarding_complete' => ['='],
            'telemetry_healthy'   => ['='],
        ],
        'release_compatibility_reports' => [
            'release_id'               => ['=', 'contains'],
            'from_version'             => ['=', 'contains'],
            'to_version'               => ['=', 'contains'],
            'schema_compatible'        => ['='],
            'rollback_safe'            => ['='],
        ],
        'cluster_topology_reports' => [
            'cluster_id'         => ['=', 'contains'],
            'cluster_role'       => ['='],
            'topology_state'     => ['='],
            'quorum_achieved'    => ['='],
            'replication_lag_ms' => ['>=', '<='],
        ],
        'ha_validation_runs' => [
            'cluster_id'         => ['=', 'contains'],
            'ha_check_type'      => ['='],
            'ha_state'           => ['='],
            'quorum_valid'       => ['='],
            'failover_ready'     => ['='],
        ],
        'telemetry_distribution_reports' => [
            'cluster_id'                 => ['=', 'contains'],
            'distribution_state'         => ['='],
            'load_balanced'              => ['='],
            'replay_amplification_ratio' => ['>=', '<='],
            'queue_pressure_pct'         => ['>=', '<='],
        ],
        'failover_coordination_history' => [
            'cluster_id'            => ['=', 'contains'],
            'failover_type'         => ['='],
            'failover_state'        => ['='],
            'dependency_ordered'    => ['='],
            'uncontrolled_failover' => ['='],
        ],
        'infrastructure_cost_reports' => [
            'cluster_id'                 => ['=', 'contains'],
            'cost_window'                => ['='],
            'total_cost_estimate'        => ['>=', '<='],
            'utilization_efficiency_pct' => ['>=', '<='],
            'is_advisory'                => ['='],
        ],
        // Release Candidate Stabilization & Pilot Deployment Preparation Phase 1
        'release_candidate_manifests' => [
            'rc_version'              => ['=', 'contains'],
            'rc_state'                => ['='],
            'readiness_score'         => ['>=', '<='],
            'feature_freeze_enforced' => ['='],
            'soak_validated'          => ['='],
        ],
        'feature_freeze_audit' => [
            'freeze_event'  => ['='],
            'freeze_scope'  => ['='],
            'subsystem'     => ['=', 'contains'],
            'rc_version'    => ['=', 'contains'],
            'hotfix_allowed'=> ['='],
        ],
        'deployment_artifact_reports' => [
            'artifact_type'      => ['='],
            'artifact_state'     => ['='],
            'integrity_verified' => ['='],
            'rc_version'         => ['=', 'contains'],
        ],
        'pilot_deployment_preparation_runs' => [
            'preparation_scope'  => ['='],
            'preparation_state'  => ['='],
            'readiness_score'    => ['>=', '<='],
            'onboarding_ready'   => ['='],
            'rollback_ready'     => ['='],
        ],
        'deployment_reproducibility_reports' => [
            'deployment_scope'            => ['='],
            'reproducibility_score'       => ['>=', '<='],
            'artifact_hash_verified'      => ['='],
            'configuration_deterministic' => ['='],
            'rc_version'                  => ['=', 'contains'],
        ],
        // Code-Level XDR Maturity Acceleration Phase 1
        'synthetic_attack_fixtures' => [
            'fixture_scenario' => ['='],
            'fixture_state'    => ['='],
            'is_lab_safe'      => ['='],
            'is_destructive'   => ['='],
            'total_events'     => ['>=', '<='],
        ],
        'detection_quality_scorecards' => [
            'scored_rule_id'          => ['='],
            'precision_score'         => ['>=', '<='],
            'recall_score'            => ['>=', '<='],
            'overall_quality_score'   => ['>=', '<='],
            'is_advisory'             => ['='],
        ],
        'false_positive_negative_reports' => [
            'analyzed_rule_id'   => ['='],
            'fp_prevalence_rate' => ['>=', '<='],
            'fn_prevalence_rate' => ['>=', '<='],
            'advisory_only'      => ['='],
        ],
        'telemetry_quality_scorecards' => [
            'tenant_id'               => ['='],
            'overall_quality_score'   => ['>=', '<='],
            'malformed_event_ratio'   => ['>=', '<='],
            'trace_id_completeness'   => ['>=', '<='],
            'is_advisory'             => ['='],
        ],
        'xdr_maturity_scorecards' => [
            'maturity_tier'           => ['='],
            'overall_maturity_score'  => ['>=', '<='],
            'detection_quality_score' => ['>=', '<='],
            'telemetry_quality_score' => ['>=', '<='],
            'is_advisory'             => ['='],
        ],
        // Final XDR Readiness Certification Phase 1
        'xdr_readiness_certifications' => [
            'certification_type'  => ['='],
            'certification_state' => ['='],
            'readiness_score'     => ['>=', '<='],
            'all_gates_passed'    => ['='],
            'soak_validated'      => ['='],
        ],
        'production_acceptance_gates' => [
            'gate_name'    => ['='],
            'gate_state'   => ['='],
            'gate_passed'  => ['='],
            'gate_score'   => ['>=', '<='],
        ],
        'operational_limitation_reports' => [
            'limitation_category' => ['='],
            'limitation_severity' => ['='],
            'cutover_blocked'     => ['='],
            'affected_domain'     => ['=', 'contains'],
        ],
        'production_risk_register' => [
            'risk_category'    => ['='],
            'risk_severity'    => ['='],
            'risk_state'       => ['='],
            'cutover_blocker'  => ['='],
            'risk_score'       => ['>=', '<='],
        ],
        'go_live_validation_runs' => [
            'validation_scope'     => ['='],
            'validation_state'     => ['='],
            'soak_requirement_met' => ['='],
            'gate_requirement_met' => ['='],
            'rollback_ready'       => ['='],
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
        // Final Demo / Portfolio / Thesis Packaging Phase 1
        'demo_scenario_runs' => [
            'scenario_name'            => ['=', 'contains'],
            'scenario_state'           => ['='],
            'is_lab_safe'              => ['='],
            'is_destructive'           => ['='],
            'has_autonomous_remediation'=> ['='],
            'demo_mode'                => ['='],
        ],
        'demo_readiness_snapshots' => [
            'overall_ready'      => ['='],
            'rule_registry_pass' => ['='],
            'php_tests_passed'   => ['>=', '<='],
        ],
        'platform_showcase_exports' => [
            'export_type'     => ['='],
            'export_format'   => ['='],
            'is_deterministic'=> ['='],
            'is_advisory'     => ['='],
            'is_fabricated'   => ['='],
        ],
        // Shadow Domain Soak Harness — BACKLOG-PROMOTION-018
        'shadow_soak_runs' => [
            'domain'        => ['='],
            'status'        => ['='],
            'evidence_pass' => ['='],
            'window_hours'  => ['=', '>=', '<='],
        ],
        'shadow_soak_gate_checks' => [
            'domain'     => ['='],
            'gate_name'  => ['='],
            'result'     => ['='],
            'run_id'     => ['='],
        ],
        'shadow_soak_evidence_snapshots' => [
            'domain'                => ['='],
            'high_confidence_rate'  => ['>=', '<='],
            'suppression_rate'      => ['>=', '<='],
            'total_findings'        => ['>=', '<='],
            'run_id'                => ['='],
        ],
        'asset_inventory' => [
            'hostname'     => ['=', 'contains'],
            'ip_address'   => ['='],
            'environment'  => ['='],
            'asset_type'   => ['='],
            'tenant_id'    => ['='],
        ],
        'asset_criticality' => [
            'criticality_tier' => ['='],
            'tenant_id'        => ['='],
        ],
        'tenant_retention_policies' => [
            'tenant_id' => ['='],
        ],
        'data_erasure_requests' => [
            'tenant_id'    => ['='],
            'status'       => ['='],
            'requested_by' => ['='],
        ],
    ];

    /**
     * Validate that all filters use allowlisted fields and operators.
     * Throws \InvalidArgumentException on any violation.
     * NEVER allows raw SQL expressions, field injection, or unsupported domains.
     */
    public static function validate(string $domain, array $filters): void
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
}
