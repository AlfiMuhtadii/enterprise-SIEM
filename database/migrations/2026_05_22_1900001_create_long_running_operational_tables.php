<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: operational validation windows
        Schema::create('operational_validation_windows', function (Blueprint $table) {
            $table->id();
            $table->string('window_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('replay_recovery_success_pct')->default(0.0);
            $table->float('avg_queue_lag')->default(0.0);
            $table->float('storage_growth_gb')->default(0.0);
            $table->integer('worker_restart_count')->default(0);
            $table->boolean('criteria_met')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('window_summary')->nullable();
            $table->timestamps();
        });

        // Append-only: telemetry trend reports
        Schema::create('telemetry_trend_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('continuity_trend_slope')->default(0.0);
            $table->float('queue_lag_trend_slope')->default(0.0);
            $table->float('duplicate_rate_trend')->default(0.0);
            $table->float('replay_backlog_trend_slope')->default(0.0);
            $table->float('telemetry_gap_accumulation')->default(0.0);
            $table->float('storage_growth_rate_gb_per_day')->default(0.0);
            $table->string('trend_verdict'); // stable, degrading, improving, critical
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('trend_data')->nullable();
            $table->timestamps();
        });

        // Append-only: analyst behavior trends
        Schema::create('analyst_behavior_trends', function (Blueprint $table) {
            $table->id();
            $table->string('trend_id')->unique();
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('avg_latency_seconds')->default(0.0);
            $table->float('latency_trend_slope')->default(0.0);
            $table->float('fatigue_score')->default(0.0);
            $table->float('escalation_quality_avg')->default(0.0);
            $table->float('suppression_usage_rate')->default(0.0);
            $table->integer('recurring_dismissal_count')->default(0);
            $table->float('avg_investigation_duration_minutes')->default(0.0);
            $table->boolean('behavior_stable')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('behavior_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: false-positive evolution reports
        Schema::create('false_positive_evolution_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('fp_rate_start')->default(0.0);
            $table->float('fp_rate_end')->default(0.0);
            $table->float('fp_trend_slope')->default(0.0);
            $table->float('suppression_effectiveness_avg')->default(0.0);
            $table->float('replay_disagreement_rate')->default(0.0);
            $table->float('confidence_drift_avg')->default(0.0);
            $table->integer('recurring_benign_count')->default(0);
            $table->string('fp_verdict'); // improving, stable, worsening, critical
            $table->boolean('is_advisory')->default(true);
            $table->json('evolution_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: operational drift history
        Schema::create('operational_drift_history', function (Blueprint $table) {
            $table->id();
            $table->string('drift_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('replay_amplification_drift')->default(0.0);
            $table->float('queue_growth_drift')->default(0.0);
            $table->float('telemetry_growth_drift')->default(0.0);
            $table->float('analyst_overload_drift')->default(0.0);
            $table->float('storage_pressure_drift')->default(0.0);
            $table->float('infrastructure_degradation_drift')->default(0.0);
            $table->float('graph_traversal_latency_drift')->default(0.0);
            $table->float('replay_latency_drift')->default(0.0);
            $table->float('composite_drift_score')->default(0.0);
            $table->string('drift_verdict'); // stable, monitoring, escalated, critical
            $table->boolean('is_advisory')->default(true);
            $table->json('drift_breakdown')->nullable();
            $table->timestamps();
        });

        // Append-only: governance reporting runs
        Schema::create('governance_reporting_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->string('report_type'); // weekly, monthly, replay_durability, telemetry_continuity, analyst_efficiency, infrastructure_stability
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('overall_health_score')->default(0.0);
            $table->boolean('telemetry_passing')->default(true);
            $table->boolean('replay_passing')->default(true);
            $table->boolean('analyst_stable')->default(true);
            $table->boolean('infrastructure_stable')->default(true);
            $table->boolean('tenant_isolation_passing')->default(true);
            $table->string('governance_verdict'); // pass, advisory, degraded, fail
            $table->boolean('is_advisory')->default(true);
            $table->json('report_summary')->nullable();
            $table->timestamps();
        });

        // Append-only: replay durability history
        Schema::create('replay_durability_history', function (Blueprint $table) {
            $table->id();
            $table->string('history_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('replay_success_rate_pct')->default(0.0);
            $table->float('avg_recovery_latency_seconds')->default(0.0);
            $table->float('replay_amplification_avg')->default(0.0);
            $table->integer('total_recovery_events')->default(0);
            $table->integer('failed_recovery_events')->default(0);
            $table->float('backlog_trend_slope')->default(0.0);
            $table->boolean('durability_acceptable')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('durability_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: infrastructure stability reports
        Schema::create('infrastructure_stability_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->string('window_type'); // 7d, 14d, 30d
            $table->float('avg_cpu_pct')->default(0.0);
            $table->float('avg_memory_growth_mb')->default(0.0);
            $table->float('avg_storage_pressure_pct')->default(0.0);
            $table->float('avg_query_latency_ms')->default(0.0);
            $table->float('cpu_trend_slope')->default(0.0);
            $table->float('memory_trend_slope')->default(0.0);
            $table->float('storage_trend_slope')->default(0.0);
            $table->string('stability_verdict'); // stable, monitoring, degrading, critical
            $table->boolean('is_advisory')->default(true);
            $table->json('stability_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: production governance audit
        Schema::create('production_governance_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('tenant_id');
            $table->string('event_type'); // window_created, trend_analyzed, report_generated, drift_detected, governance_review
            $table->string('actor');
            $table->string('outcome'); // success, advisory, degraded, failed
            $table->text('description')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_governance_audit');
        Schema::dropIfExists('infrastructure_stability_reports');
        Schema::dropIfExists('replay_durability_history');
        Schema::dropIfExists('governance_reporting_runs');
        Schema::dropIfExists('operational_drift_history');
        Schema::dropIfExists('false_positive_evolution_reports');
        Schema::dropIfExists('analyst_behavior_trends');
        Schema::dropIfExists('telemetry_trend_reports');
        Schema::dropIfExists('operational_validation_windows');
    }
};
