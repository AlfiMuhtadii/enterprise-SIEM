<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: cluster topology reports
        Schema::create('cluster_topology_reports', function (Blueprint $table) {
            $table->id();
            $table->string('topology_report_id')->unique();
            $table->string('cluster_id');
            $table->string('cluster_role'); // primary, secondary, arbiter, observer
            $table->string('topology_state'); // healthy, degraded, partitioned, rejoining
            $table->integer('node_count')->default(1);
            $table->integer('replica_count')->default(0);
            $table->float('replication_lag_ms')->default(0.0);
            $table->boolean('quorum_achieved')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('topology_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: HA validation runs
        Schema::create('ha_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('ha_run_id')->unique();
            $table->string('cluster_id');
            $table->string('ha_check_type'); // quorum, replica_continuity, replay_continuity, failover_readiness, replication_lag
            $table->string('ha_state'); // passing, degraded, failed, recovering
            $table->float('ha_score')->default(0.0);
            $table->float('replication_lag_ms')->default(0.0);
            $table->boolean('quorum_valid')->default(false);
            $table->boolean('replica_continuous')->default(false);
            $table->boolean('replay_continuous')->default(false);
            $table->boolean('failover_ready')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('ha_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: telemetry distribution reports
        Schema::create('telemetry_distribution_reports', function (Blueprint $table) {
            $table->id();
            $table->string('distribution_report_id')->unique();
            $table->string('cluster_id');
            $table->string('distribution_state'); // balanced, imbalanced, overloaded, recovering
            $table->float('ingestion_rate_eps')->default(0.0);
            $table->float('queue_pressure_pct')->default(0.0);
            $table->float('cross_cluster_lag_ms')->default(0.0);
            $table->float('replay_amplification_ratio')->default(1.0);
            $table->boolean('load_balanced')->default(false);
            $table->boolean('bounded_redistribution')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('distribution_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: failover coordination history
        Schema::create('failover_coordination_history', function (Blueprint $table) {
            $table->id();
            $table->string('failover_coordination_id')->unique();
            $table->string('cluster_id');
            $table->string('failover_type'); // drill, simulation, dependency_sequenced, replay_verified
            $table->string('failover_state'); // planned, sequencing, validating, completed, rolled_back, aborted
            $table->float('duration_s')->default(0.0);
            $table->boolean('dependency_ordered')->default(false);
            $table->boolean('replay_verified')->default(false);
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('uncontrolled_failover')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('failover_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: distributed replay continuity
        Schema::create('distributed_replay_continuity', function (Blueprint $table) {
            $table->id();
            $table->string('replay_continuity_id')->unique();
            $table->string('cluster_id');
            $table->string('continuity_state'); // continuous, gap_detected, recovering, restored
            $table->float('replay_coverage_pct')->default(0.0);
            $table->integer('gap_count')->default(0);
            $table->float('amplification_ratio')->default(1.0);
            $table->boolean('cross_cluster_continuous')->default(false);
            $table->boolean('durability_verified')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('continuity_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: infrastructure cost reports
        Schema::create('infrastructure_cost_reports', function (Blueprint $table) {
            $table->id();
            $table->string('cost_report_id')->unique();
            $table->string('cluster_id');
            $table->string('cost_window'); // daily, weekly, monthly
            $table->float('storage_cost_estimate')->default(0.0);
            $table->float('ingestion_cost_estimate')->default(0.0);
            $table->float('replay_amplification_cost')->default(0.0);
            $table->float('ha_overhead_cost')->default(0.0);
            $table->float('total_cost_estimate')->default(0.0);
            $table->float('utilization_efficiency_pct')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->json('cost_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: cluster lifecycle audit
        Schema::create('cluster_lifecycle_audit', function (Blueprint $table) {
            $table->id();
            $table->string('cluster_audit_id')->unique();
            $table->string('cluster_id');
            $table->string('lifecycle_event'); // registered, validated, topology_updated, degraded, restored, decommissioned
            $table->string('previous_state')->nullable();
            $table->string('current_state');
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: scale profile validation runs
        Schema::create('scale_profile_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scale_run_id')->unique();
            $table->string('scale_profile'); // small_50, medium_250, large_500
            $table->integer('endpoint_count')->default(0);
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('replay_durability_pct')->default(0.0);
            $table->float('analyst_workload_score')->default(0.0);
            $table->float('queue_recovery_score')->default(0.0);
            $table->float('operational_score')->default(0.0);
            $table->boolean('scale_passed')->default(false);
            $table->boolean('bounded_scope')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('scale_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: enterprise scale operations audit
        Schema::create('enterprise_scale_operations_audit', function (Blueprint $table) {
            $table->id();
            $table->string('scale_audit_id')->unique();
            $table->string('audit_event_type'); // cluster_registered, ha_validated, telemetry_balanced, failover_drilled, cost_assessed, scale_validated, continuity_verified
            $table->string('actor_type'); // operator, system, analyst
            $table->string('cluster_id')->nullable();
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->json('audit_evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_scale_operations_audit');
        Schema::dropIfExists('scale_profile_validation_runs');
        Schema::dropIfExists('cluster_lifecycle_audit');
        Schema::dropIfExists('infrastructure_cost_reports');
        Schema::dropIfExists('distributed_replay_continuity');
        Schema::dropIfExists('failover_coordination_history');
        Schema::dropIfExists('telemetry_distribution_reports');
        Schema::dropIfExists('ha_validation_runs');
        Schema::dropIfExists('cluster_topology_reports');
    }
};
