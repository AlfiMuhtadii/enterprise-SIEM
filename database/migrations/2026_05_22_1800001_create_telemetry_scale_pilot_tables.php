<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: telemetry scale validation runs
        Schema::create('telemetry_scale_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->integer('endpoint_count');
            $table->string('scale_profile'); // scale_50, scale_75, scale_100
            $table->string('status'); // pending, running, completed, aborted
            $table->float('avg_events_per_second')->default(0.0);
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('duplicate_rate')->default(0.0);
            $table->integer('replay_backlog')->default(0);
            $table->boolean('validation_passed')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        // Append-only: telemetry scale metrics (per-checkpoint)
        Schema::create('telemetry_scale_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('metric_type'); // throughput, queue_lag, replay_backlog, storage, worker_restarts, duplicate_rate
            $table->float('value')->default(0.0);
            $table->float('baseline_value')->default(0.0);
            $table->float('drift_pct')->default(0.0);
            $table->boolean('within_bounds')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only: replay scale recovery runs
        Schema::create('replay_scale_recovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('recovery_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->integer('backlog_at_start')->default(0);
            $table->integer('backlog_at_end')->default(0);
            $table->float('recovery_latency_seconds')->default(0.0);
            $table->float('replay_amplification_factor')->default(1.0);
            $table->boolean('amplification_bounded')->default(true);
            $table->boolean('duplicate_protected')->default(true);
            $table->boolean('recovery_successful')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('recovery_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: analyst load stability reports
        Schema::create('analyst_load_stability_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->float('alert_throughput_per_hour')->default(0.0);
            $table->float('avg_acknowledgment_latency_seconds')->default(0.0);
            $table->integer('escalation_backlog')->default(0);
            $table->boolean('fatigue_detected')->default(false);
            $table->integer('repeated_dismissal_count')->default(0);
            $table->float('avg_investigation_duration_minutes')->default(0.0);
            $table->float('queue_growth_rate')->default(0.0);
            $table->boolean('workload_stable')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('stability_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: infrastructure pressure runs
        Schema::create('infrastructure_pressure_runs', function (Blueprint $table) {
            $table->id();
            $table->string('pressure_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->float('cpu_usage_pct')->default(0.0);
            $table->float('memory_growth_mb')->default(0.0);
            $table->float('storage_pressure_pct')->default(0.0);
            $table->float('partition_pressure_pct')->default(0.0);
            $table->float('query_latency_ms')->default(0.0);
            $table->float('graph_traversal_latency_ms')->default(0.0);
            $table->float('replay_latency_ms')->default(0.0);
            $table->boolean('pressure_within_bounds')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('pressure_snapshot')->nullable();
            $table->timestamps();
        });

        // Append-only: telemetry growth drift reports
        Schema::create('telemetry_growth_drift_reports', function (Blueprint $table) {
            $table->id();
            $table->string('drift_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('drift_dimension'); // replay_amplification, telemetry_growth, queue_lag, analyst_overload, storage_growth, query_latency, graph_traversal
            $table->float('current_value')->default(0.0);
            $table->float('baseline_value')->default(0.0);
            $table->float('drift_magnitude')->default(0.0);
            $table->string('drift_severity'); // low, medium, high, critical
            $table->boolean('drift_bounded')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('drift_evidence')->nullable();
            $table->timestamps();
        });

        // Mutable: scale observation windows
        Schema::create('scale_observation_windows', function (Blueprint $table) {
            $table->id();
            $table->string('window_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->integer('window_hours'); // 24, 48, 72
            $table->string('status'); // active, completed, aborted
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('replay_recovery_success_pct')->default(0.0);
            $table->float('drift_stability_pct')->default(1.0);
            $table->boolean('criteria_met')->default(false);
            $table->boolean('bounded_window')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('window_summary')->nullable();
            $table->timestamps();
        });

        // Append-only: queue recovery validation reports
        Schema::create('queue_recovery_validation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->integer('queue_lag_at_start')->default(0);
            $table->integer('queue_lag_at_end')->default(0);
            $table->float('recovery_latency_seconds')->default(0.0);
            $table->boolean('duplicate_protected')->default(true);
            $table->boolean('replay_amplification_safe')->default(true);
            $table->boolean('continuity_after_reconnect')->default(true);
            $table->boolean('recovery_successful')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('recovery_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: scale pilot audit
        Schema::create('scale_pilot_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('event_type'); // run_started, checkpoint, drift_detected, recovery, completion, aborted
            $table->string('actor');
            $table->string('outcome'); // success, failure, degraded, bounded
            $table->text('description')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scale_pilot_audit');
        Schema::dropIfExists('queue_recovery_validation_reports');
        Schema::dropIfExists('scale_observation_windows');
        Schema::dropIfExists('telemetry_growth_drift_reports');
        Schema::dropIfExists('infrastructure_pressure_runs');
        Schema::dropIfExists('analyst_load_stability_reports');
        Schema::dropIfExists('replay_scale_recovery_runs');
        Schema::dropIfExists('telemetry_scale_metrics');
        Schema::dropIfExists('telemetry_scale_validation_runs');
    }
};
