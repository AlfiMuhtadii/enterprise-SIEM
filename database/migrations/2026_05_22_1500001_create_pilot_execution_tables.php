<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: live pilot runs
        Schema::create('live_pilot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->string('pilot_name');
            $table->integer('target_endpoint_count');
            $table->integer('enrolled_endpoint_count')->default(0);
            $table->string('status'); // pending, active, completed, aborted, rolled_back
            $table->boolean('activation_approved')->default(false);
            $table->string('approved_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('observation_window_hours')->default(24);
            $table->boolean('rollback_ready')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        // Append-only: endpoint enrollments
        Schema::create('pilot_endpoint_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('enrollment_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('endpoint_id');
            $table->string('hostname');
            $table->string('status'); // enrolling, enrolled, failed, withdrawn
            $table->boolean('onboarding_verified')->default(false);
            $table->boolean('telemetry_flowing')->default(false);
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only: health checkpoints
        Schema::create('pilot_health_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('checkpoint_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('checkpoint_type'); // 24h, 48h, 72h, manual, escalation
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('replay_recovery_success_pct')->default(0.0);
            $table->float('queue_recovery_latency_ms')->default(0.0);
            $table->float('endpoint_stability_pct')->default(0.0);
            $table->float('tenant_isolation_pass_rate')->default(1.0);
            $table->float('false_positive_ratio')->default(0.0);
            $table->float('drift_stability_pct')->default(1.0);
            $table->float('rollback_readiness_score')->default(0.0);
            $table->boolean('health_ok')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('metrics')->nullable();
            $table->timestamps();
        });

        // Append-only: operational reviews
        Schema::create('pilot_operational_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('review_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('review_type'); // daily, escalation, false_positive, drift, replay_integrity, tenant_isolation
            $table->string('reviewed_by');
            $table->string('verdict'); // acknowledged, escalated, deferred, closed
            $table->text('notes')->nullable();
            $table->boolean('requires_followup')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: drift reviews
        Schema::create('pilot_drift_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('drift_review_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('drift_type'); // telemetry, queue, memory, replay, schema
            $table->float('drift_magnitude')->default(0.0);
            $table->string('drift_severity'); // low, medium, high, critical
            $table->string('verdict'); // stable, monitoring, escalated, rolled_back
            $table->string('reviewed_by');
            $table->boolean('rollback_triggered')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        // Append-only: rollback audit
        Schema::create('pilot_rollback_audit', function (Blueprint $table) {
            $table->id();
            $table->string('rollback_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('trigger_reason'); // threshold_breach, operator_request, drift_critical, health_fail
            $table->string('triggered_by');
            $table->boolean('rollback_approved')->default(false);
            $table->string('approved_by')->nullable();
            $table->string('status'); // pending_approval, approved, executed, verified, rejected
            $table->boolean('destructive_action')->default(false); // always false
            $table->boolean('replay_reconstructed')->default(false);
            $table->boolean('isolation_preserved')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('audit_trail')->nullable();
            $table->timestamps();
        });

        // Append-only: live telemetry validations
        Schema::create('live_telemetry_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->float('events_per_second')->default(0.0);
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->integer('queue_lag')->default(0);
            $table->float('replay_continuity_pct')->default(0.0);
            $table->float('duplicate_event_rate')->default(0.0);
            $table->float('telemetry_gap_rate')->default(0.0);
            $table->integer('collector_reconnect_count')->default(0);
            $table->float('storage_pressure_pct')->default(0.0);
            $table->boolean('worker_healthy')->default(true);
            $table->boolean('validation_passed')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('raw_metrics')->nullable();
            $table->timestamps();
        });

        // Append-only: production observation checkpoints
        Schema::create('production_observation_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('checkpoint_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('window_type'); // 24h, 48h, 72h
            $table->float('telemetry_continuity_pct')->default(0.0);
            $table->float('replay_recovery_success_pct')->default(0.0);
            $table->float('drift_stability_pct')->default(1.0);
            $table->float('rollback_readiness_score')->default(0.0);
            $table->boolean('criteria_met')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        // Append-only: pilot execution audit
        Schema::create('pilot_execution_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('event_type'); // activation, enrollment, checkpoint, review, drift, rollback, completion
            $table->string('actor');
            $table->string('outcome'); // success, failure, pending, escalated
            $table->text('description')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_execution_audit');
        Schema::dropIfExists('production_observation_checkpoints');
        Schema::dropIfExists('live_telemetry_validations');
        Schema::dropIfExists('pilot_rollback_audit');
        Schema::dropIfExists('pilot_drift_reviews');
        Schema::dropIfExists('pilot_operational_reviews');
        Schema::dropIfExists('pilot_health_checkpoints');
        Schema::dropIfExists('pilot_endpoint_enrollments');
        Schema::dropIfExists('live_pilot_runs');
    }
};
