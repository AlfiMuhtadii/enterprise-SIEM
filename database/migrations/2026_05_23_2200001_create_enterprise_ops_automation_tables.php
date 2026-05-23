<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: operational recovery runs
        Schema::create('operational_recovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('recovery_run_id')->unique();
            $table->string('service_name');
            $table->string('recovery_type'); // restart, reconnect, replay, failover, drain
            $table->string('recovery_state'); // initiated, validating, checkpoint_passed, completed, failed
            $table->integer('sequence_order')->default(1);
            $table->float('duration_s')->default(0.0);
            $table->boolean('replay_continuous')->default(false);
            $table->boolean('bounded_scope')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('recovery_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: service lifecycle audit
        Schema::create('service_lifecycle_audit', function (Blueprint $table) {
            $table->id();
            $table->string('lifecycle_id')->unique();
            $table->string('service_name');
            $table->string('lifecycle_event'); // startup, shutdown, restart, degraded, recovered, drift_detected
            $table->string('previous_state')->nullable();
            $table->string('current_state');
            $table->boolean('dependency_satisfied')->default(true);
            $table->boolean('drift_detected')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('lifecycle_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: recovery checkpoint history (ops recovery checkpoints)
        Schema::create('recovery_checkpoint_history', function (Blueprint $table) {
            $table->id();
            $table->string('ops_checkpoint_id')->unique();
            $table->string('recovery_run_id');
            $table->string('checkpoint_type'); // pre_recovery, post_restart, post_reconnect, post_replay, post_failover, continuity_verified
            $table->boolean('passed')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('checkpoint_details')->nullable();
            $table->timestamps();
        });

        // Append-only: failover validation runs
        Schema::create('failover_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('failover_run_id')->unique();
            $table->string('service_name');
            $table->string('failover_type'); // active_passive, drain_switch, replica_promotion, dependency_reroute
            $table->boolean('readiness_verified')->default(false);
            $table->boolean('dependency_mapped')->default(false);
            $table->boolean('continuity_verified')->default(false);
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('failover_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: bounded automation reports
        Schema::create('bounded_automation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('automation_id')->unique();
            $table->string('automation_type'); // health_check, dependency_check, recovery_readiness, replay_continuity, queue_continuity, deployment_continuity
            $table->string('service_scope');
            $table->boolean('check_passed')->default(false);
            $table->boolean('remediation_proposed')->default(false);
            $table->boolean('autonomous_action_taken')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('automation_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: recovery dependency graphs
        Schema::create('recovery_dependency_graphs', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->unique();
            $table->string('root_service');
            $table->integer('dependency_depth')->default(1);
            $table->integer('total_dependencies')->default(0);
            $table->boolean('circular_dependency_detected')->default(false);
            $table->boolean('all_dependencies_healthy')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('dependency_map')->nullable();
            $table->timestamps();
        });

        // Append-only: operational continuity reports
        Schema::create('operational_continuity_reports', function (Blueprint $table) {
            $table->id();
            $table->string('continuity_id')->unique();
            $table->string('environment');
            $table->float('continuity_score')->default(0.0); // 0.0-1.0
            $table->boolean('replay_continuous')->default(false);
            $table->boolean('queue_continuous')->default(false);
            $table->boolean('telemetry_continuous')->default(false);
            $table->boolean('storage_continuous')->default(false);
            $table->boolean('overall_continuous')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('continuity_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: recovery simulation runs
        Schema::create('recovery_simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id')->unique();
            $table->string('simulation_type'); // dependency_failure, replay_recovery, queue_reconnect, service_restart, storage_reconnect
            $table->string('target_service');
            $table->float('duration_s')->default(0.0);
            $table->boolean('simulation_passed')->default(false);
            $table->boolean('destructive_action_taken')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('simulation_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: enterprise operations audit
        Schema::create('enterprise_operations_audit', function (Blueprint $table) {
            $table->id();
            $table->string('ops_audit_id')->unique();
            $table->string('operation_type'); // recovery_initiated, lifecycle_event, failover_drill, automation_check, simulation_run, continuity_check
            $table->string('service_scope');
            $table->string('actor');
            $table->string('outcome'); // success, failure, advisory, skipped
            $table->boolean('is_advisory')->default(true);
            $table->json('ops_audit_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_operations_audit');
        Schema::dropIfExists('recovery_simulation_runs');
        Schema::dropIfExists('operational_continuity_reports');
        Schema::dropIfExists('recovery_dependency_graphs');
        Schema::dropIfExists('bounded_automation_reports');
        Schema::dropIfExists('failover_validation_runs');
        Schema::dropIfExists('recovery_checkpoint_history');
        Schema::dropIfExists('service_lifecycle_audit');
        Schema::dropIfExists('operational_recovery_runs');
    }
};
