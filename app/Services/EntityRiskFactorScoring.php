<?php

namespace App\Services;

/**
 * CODE-STRUCT-DECOMPOSE: pure risk-factor scoring math extracted from
 * EntityRiskScoringService — factor weights, level thresholds, score
 * aggregation, and factor-record construction. Zero Eloquent/DB dependency;
 * everything here operates on already-collected data (a $factors array or
 * a raw float score), matching the same pattern already applied to
 * UEBABaselineService -> UEBAStatistics and ThreatHuntingService ->
 * ThreatHuntQueryAllowlist.
 */
class EntityRiskFactorScoring
{
    public const WEIGHTS = [
        'critical_alerts'            => 3.0,
        'high_alerts'                => 2.0,
        'medium_alerts'              => 0.8,
        'mfa_failure_burst'          => 2.5,
        'incident_involvement'       => 3.0,
        'trace_frequency'            => 0.4,
        'relationship_count'         => 0.3,
        'persistence_indicator'      => 2.5,
        'c2_indicator'               => 3.5,
        'shadow_alert_advisory'      => 0.5,
        // Cross-domain amplification factors — advisory_only = true
        'cross_domain_correlation'   => 2.0,
        'multi_stage_escalation'     => 2.5,
        'repeated_destination_reuse' => 1.5,
        'identity_endpoint_combined' => 3.0,
        // Active response scoring — Phase 2
        'response_blast_radius'      => 1.5,  // amplification: entity is a target with high blast radius
        'response_rollback_impact'   => 1.0,  // rollback was needed — indicates significant threat
        'response_execution_mitigation' => -2.0, // mitigation applied — lowers risk (negative contribution)
        // UEBA behavioral baseline factors — Phase 1 (advisory_only = true)
        'baseline_anomaly_factor'       => 2.0,  // entity has recent high-confidence baseline anomalies
        'peer_deviation_factor'         => 1.5,  // entity deviates significantly from peer group
        'abnormal_data_volume_factor'   => 2.0,  // abnormal bytes out / data exfil signal
        'unusual_activity_time_factor'  => 1.5,  // unusual active hours pattern
        // Endpoint operational hardening factors — Phase 1 (advisory_only = true)
        'telemetry_gap_factor'          => 2.0,  // host telemetry has a significant gap (potential blind spot)
        'tamper_visibility_factor'      => 2.5,  // tamper indicator detected on endpoint
        'stale_agent_factor'            => 1.5,  // agent is stale or offline
        'policy_drift_factor'           => 1.0,  // agent config does not match assigned fleet policy
        // Low-level endpoint telemetry factors — Phase 1 (advisory_only = true)
        'rare_process_factor'           => 2.0,  // rare/unusual process execution on endpoint
        'suspicious_script_factor'      => 2.5,  // encoded or suspicious script execution detected
        'persistence_indicator_factor'  => 2.5,  // low-level persistence indicator (registry, cron, service)
        'abnormal_connection_factor'    => 2.0,  // outbound connection to rare/unusual destination
        'privilege_escalation_factor'   => 3.0,  // privilege escalation indicator detected on endpoint
        // SOAR Governance & Response Orchestration Phase 1 (advisory_only = true)
        'orchestration_relevance_factor'  => 1.5,  // entity appears in an active SOAR execution plan
        'repeated_simulation_factor'      => 1.0,  // entity is repeatedly simulated as a target
        'soar_blast_radius_factor'        => 2.0,  // entity in a plan with high simulated blast radius
        'rollback_readiness_factor'       => -0.5, // entity has rollback plan validated (lowers risk slightly)
        // Advanced Threat Hunting & Investigation Phase 1 (advisory_only = true)
        'attack_path_factor'              => 2.5,  // entity appears on a reconstructed attack timeline
        'multi_host_correlation_factor'   => 2.0,  // entity observed on multiple hosts in investigation graph
        'repeated_pivot_factor'           => 1.5,  // entity is a pivot origin multiple times across sessions
        'investigation_relevance_factor'  => 1.0,  // entity linked as evidence in an active investigation
        // Production Readiness / Release Governance Phase 1 (advisory_only = true)
        'release_drift_factor'            => 1.5,  // entity host has unresolved blocking environment drift
        'readiness_blocker_factor'        => 2.0,  // entity associated with a failed deployment readiness run
        'rollback_risk_factor'            => 2.5,  // entity on a host with low rollback readiness score
        'release_audit_anomaly_factor'    => 1.0,  // entity appears in anomalous release audit event
        // Advanced Detection Coverage & Adversarial Validation Phase 1 (advisory_only = true)
        'chained_attack_factor'           => 2.5,  // entity appears in a multi-hop chained attack graph
        'tactic_progression_factor'       => 2.0,  // entity exhibits multi-stage ATT&CK tactic progression
        'credential_abuse_factor'         => 3.0,  // entity has credential access indicators in chain timelines
        'lateral_movement_factor'         => 2.5,  // entity is a node in a lateral movement correlation run
        'evasion_resilience_factor'       => 1.5,  // entity associated with failed evasion resilience tests
        // Sensor Hardening Phase 2 (advisory_only = true)
        'collector_restart_factor'        => 2.0,  // endpoint collector is restarting frequently (instability signal)
        'package_integrity_factor'        => 3.0,  // unsigned or hash-invalid package detected on endpoint
        'offline_duration_factor'         => 2.0,  // endpoint was offline for extended period (blind spot risk)
        'sensor_resource_pressure_factor' => 1.5,  // endpoint sensor CPU/memory in critical pressure state
        // Multi-Tenant Production Isolation Phase 1 (advisory_only = true)
        'tenant_isolation_failure_factor'     => 3.0,  // entity associated with a tenant isolation audit failure
        'tenant_boundary_violation_factor'    => 3.5,  // entity linked to a detected tenant boundary violation
        'tenant_context_propagation_factor'   => 2.0,  // entity trace shows tenant context attribution failure
        'tenant_evidence_integrity_factor'    => 2.5,  // entity evidence refs contain cross-tenant or invalid refs
        // Long-Duration Soak & Chaos Validation Phase 1 (advisory_only = true)
        'soak_failure_factor'                 => 2.0,  // entity involved in a failed long-duration soak validation
        'chaos_recovery_failure_factor'       => 2.5,  // entity associated with a failed chaos recovery validation
        'operational_drift_factor'            => 1.5,  // entity linked to operational drift exceeding threshold
        'replay_continuity_failure_factor'    => 2.0,  // entity replay recovery shows ordering/continuity failure
        // Production Pilot Readiness Phase 1 (advisory_only = true)
        'pilot_health_failure_factor'         => 2.5,  // entity associated with a failed pilot health validation
        'pilot_onboarding_pressure_factor'    => 1.5,  // entity tenant in critical telemetry onboarding pressure
        'pilot_rollback_trigger_factor'       => 3.0,  // entity associated with a pilot rollback validation failure
        'operator_readiness_failure_factor'   => 2.0,  // entity linked to an operator who failed readiness review
    ];

    // Score thresholds for level assignment
    public const LEVEL_THRESHOLDS = [
        'critical' => 7.5,
        'high'     => 5.0,
        'medium'   => 2.5,
        'low'      => 0.0,
    ];

    public const MAX_SCORE = 10.0;

    public static function scoreToLevel(float $score): string
    {
        return match (true) {
            $score >= static::LEVEL_THRESHOLDS['critical'] => 'critical',
            $score >= static::LEVEL_THRESHOLDS['high']     => 'high',
            $score >= static::LEVEL_THRESHOLDS['medium']   => 'medium',
            default                                         => 'low',
        };
    }

    public static function aggregateScore(array $factors): float
    {
        $total = 0.0;
        foreach ($factors as $f) {
            $total += (float) ($f['contribution'] ?? 0.0);
        }
        return (float) min(round($total, 2), static::MAX_SCORE);
    }

    public static function makeFactor(
        string $name,
        int    $value,
        array  $alertIds    = [],
        array  $traceIds    = [],
        array  $incidentIds = [],
        bool   $advisoryOnly = false
    ): array {
        $weight       = (float) (self::WEIGHTS[$name] ?? 1.0);
        $contribution = (float) round(min((float) $value * $weight, static::MAX_SCORE), 3);

        $factor = [
            'factor'       => $name,
            'value'        => $value,
            'weight'       => $weight,
            'contribution' => $contribution,
        ];

        if ($alertIds)    $factor['alert_ids']    = $alertIds;
        if ($traceIds)    $factor['trace_ids']    = $traceIds;
        if ($incidentIds) $factor['incident_ids'] = $incidentIds;
        if ($advisoryOnly) $factor['advisory_only'] = true;

        return $factor;
    }
}
