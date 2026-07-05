<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIM-LAYER-REALITY-GATE (Track A): label every HA/scale/chaos/soak/pilot
 * validation-run record as simulated/computed rather than measured against
 * real distributed infrastructure. A simulated PASS must never be
 * indistinguishable, in the UI/exports, from a real validated result.
 *
 * is_simulated defaults true and evidence_basis defaults 'computed' because
 * none of these validators currently exercise real distributed infrastructure
 * (Redpanda is single-node — GAP-004). Once a validator is backed by a real
 * multi-node harness (Track B), that specific write path can set
 * evidence_basis='measured' explicitly.
 */
return new class extends Migration
{
    private const TABLES = [
        'cluster_topology_reports',
        'ha_validation_runs',
        'telemetry_distribution_reports',
        'failover_coordination_history',
        'distributed_replay_continuity',
        'infrastructure_cost_reports',
        'cluster_lifecycle_audit',
        'scale_profile_validation_runs',
        'enterprise_scale_operations_audit',
        'telemetry_scale_validation_runs',
        'telemetry_scale_metrics',
        'replay_scale_recovery_runs',
        'analyst_load_stability_reports',
        'infrastructure_pressure_runs',
        'telemetry_growth_drift_reports',
        'scale_observation_windows',
        'queue_recovery_validation_reports',
        'scale_pilot_audit',
        'soak_validation_runs',
        'soak_validation_metrics',
        'chaos_simulation_runs',
        'chaos_failure_events',
        'recovery_validation_artifacts',
        'operational_drift_reports',
        'replay_recovery_runs',
        'telemetry_continuity_reports',
        'live_pilot_runs',
        'pilot_endpoint_enrollments',
        'pilot_health_checkpoints',
        'live_telemetry_validations',
        'production_observation_checkpoints',
        'pilot_operational_reviews',
        'pilot_drift_reviews',
        'pilot_rollback_audit',
        'pilot_execution_audit',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_simulated')->default(true);
                $blueprint->string('evidence_basis', 20)->default('computed');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['is_simulated', 'evidence_basis']);
            });
        }
    }
};
