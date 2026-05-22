<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soak_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('soak_type'); // 6h|12h|replay|worker_restart|telemetry|queue|degraded
            $table->integer('duration_minutes');
            $table->string('status'); // running|completed|aborted
            $table->boolean('passed')->default(false);
            $table->float('memory_growth_mb')->default(0);
            $table->float('queue_lag_growth')->default(0);
            $table->integer('replay_backlog')->default(0);
            $table->float('duplicate_event_rate')->default(0);
            $table->integer('worker_restart_count')->default(0);
            $table->float('telemetry_gap_rate')->default(0);
            $table->float('retry_amplification_factor')->default(0);
            $table->boolean('is_advisory')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('soak_validation_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_id')->unique();
            $table->string('run_id');
            $table->string('metric_name');
            $table->float('metric_value');
            $table->string('unit')->nullable(); // ms|mb|count|rate|pct
            $table->integer('sample_offset_minutes')->default(0);
            $table->boolean('drift_detected')->default(false);
            $table->float('baseline_value')->nullable();
            $table->float('drift_delta')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('chaos_simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id')->unique();
            $table->string('scenario'); // worker_restart|queue_disconnect|storage_unavailable|replay_throttle|delayed_telemetry|dependency_timeout|degraded_index|endpoint_disconnect
            $table->integer('duration_seconds');
            $table->boolean('recovery_verified')->default(false);
            $table->integer('failures_injected')->default(0);
            $table->integer('recoveries_observed')->default(0);
            $table->string('verdict'); // pass|fail|partial
            $table->boolean('replay_safe')->default(true);
            $table->boolean('isolation_preserved')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('failure_sequence')->nullable();
            $table->timestamps();
        });

        Schema::create('chaos_failure_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('simulation_id');
            $table->string('failure_type');
            $table->string('component'); // worker|queue|storage|search|endpoint
            $table->integer('offset_seconds')->default(0);
            $table->string('outcome'); // injected|detected|recovered|unrecovered
            $table->integer('recovery_seconds')->nullable();
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('recovery_validation_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('artifact_id')->unique();
            $table->string('simulation_id')->nullable();
            $table->string('run_id')->nullable();
            $table->string('recovery_type'); // replay|telemetry|queue|worker|storage|graph|tenant
            $table->boolean('recovery_ok');
            $table->integer('recovery_seconds')->default(0);
            $table->boolean('duplicates_prevented')->default(true);
            $table->boolean('tenant_isolation_preserved')->default(true);
            $table->boolean('graph_integrity_preserved')->default(true);
            $table->string('verdict'); // pass|fail|partial
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('operational_drift_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('run_id')->nullable();
            $table->string('drift_type'); // memory|queue|replay_amplification|worker_restart|telemetry_throughput|storage_latency|query_latency|graph_traversal
            $table->float('baseline_value');
            $table->float('observed_value');
            $table->float('drift_delta');
            $table->float('drift_pct');
            $table->integer('window_minutes');
            $table->boolean('drift_exceeds_threshold');
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('replay_recovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('trigger'); // worker_restart|queue_disconnect|storage_recovery|manual
            $table->integer('events_pending')->default(0);
            $table->integer('events_replayed')->default(0);
            $table->boolean('ordering_preserved')->default(true);
            $table->boolean('duplicates_prevented')->default(true);
            $table->boolean('tenant_isolation_preserved')->default(true);
            $table->boolean('continuity_verified')->default(false);
            $table->integer('replay_seconds')->default(0);
            $table->string('verdict'); // pass|fail|partial
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('telemetry_continuity_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('soak_run_id')->nullable();
            $table->integer('observation_window_minutes');
            $table->integer('expected_events')->default(0);
            $table->integer('observed_events')->default(0);
            $table->float('continuity_pct');
            $table->integer('gap_count')->default(0);
            $table->integer('total_gap_seconds')->default(0);
            $table->boolean('continuity_ok');
            $table->string('verdict'); // pass|fail|degraded
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        // MUTABLE — scenario catalog with allow/deny governance
        Schema::create('bounded_failure_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('scenario_key')->unique();
            $table->string('name');
            $table->string('component');
            $table->integer('max_duration_seconds');
            $table->boolean('enabled')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('destructive')->default(false);
            $table->json('allowed_in_environments')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounded_failure_scenarios');
        Schema::dropIfExists('telemetry_continuity_reports');
        Schema::dropIfExists('replay_recovery_runs');
        Schema::dropIfExists('operational_drift_reports');
        Schema::dropIfExists('recovery_validation_artifacts');
        Schema::dropIfExists('chaos_failure_events');
        Schema::dropIfExists('chaos_simulation_runs');
        Schema::dropIfExists('soak_validation_metrics');
        Schema::dropIfExists('soak_validation_runs');
    }
};
