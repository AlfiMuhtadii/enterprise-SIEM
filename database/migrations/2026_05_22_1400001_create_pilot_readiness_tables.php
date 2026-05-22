<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_onboarding_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->string('status'); // pending|approved|onboarding|active|paused|aborted
            $table->string('approval_ref')->nullable();
            $table->integer('max_events_per_second')->default(1000);
            $table->integer('max_endpoints')->default(100);
            $table->integer('pilot_duration_hours');
            $table->boolean('readiness_checklist_complete')->default(false);
            $table->boolean('rollback_drill_complete')->default(false);
            $table->boolean('operator_acknowledged')->default(false);
            $table->string('operator_id')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence_refs')->nullable();
            $table->timestamps();
        });

        Schema::create('pilot_health_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('check_type'); // telemetry|replay|queue|worker|endpoint|isolation|dashboard|hunt|storage|drift
            $table->boolean('check_passed');
            $table->string('verdict'); // pass|fail|degraded
            $table->text('failure_reason')->nullable();
            $table->float('metric_value')->nullable();
            $table->float('threshold_value')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('pilot_success_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('metric_name'); // telemetry_continuity_pct|replay_success_pct|queue_recovery_latency_ms|isolation_pass_rate|endpoint_stability_pct|fp_ratio|drift_stability_pct|operator_ack_latency_s
            $table->float('metric_value');
            $table->float('target_value');
            $table->boolean('target_met');
            $table->integer('window_hours');
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('pilot_rollback_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('trigger'); // manual|health_failure|metric_breach|operator_request|timeout
            $table->boolean('checkpoint_valid');
            $table->boolean('approval_obtained');
            $table->boolean('rollback_safe');
            $table->boolean('audit_complete');
            $table->string('approved_by')->nullable();
            $table->string('verdict'); // pass|fail|pending_approval
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('telemetry_onboarding_pressure', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->float('events_per_second');
            $table->float('queue_growth_rate');
            $table->float('storage_growth_mb_per_hour');
            $table->integer('endpoint_count');
            $table->float('replay_amplification_factor');
            $table->boolean('pressure_ok');
            $table->string('pressure_level'); // normal|elevated|high|critical
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('operator_readiness_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('review_id')->unique();
            $table->string('run_id');
            $table->string('operator_id');
            $table->string('review_type'); // runbook|escalation|shift_handoff|incident_workflow|general
            $table->boolean('runbook_reviewed');
            $table->boolean('escalation_validated');
            $table->boolean('shift_handoff_ready');
            $table->boolean('incident_workflow_tested');
            $table->boolean('operator_ready');
            $table->integer('acknowledgment_latency_seconds')->nullable();
            $table->string('verdict'); // pass|fail|incomplete
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('pilot_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('event_type'); // onboarding_started|onboarding_approved|health_check|metric_snapshot|rollback_triggered|operator_ack|pilot_completed|pilot_aborted
            $table->string('actor_id')->nullable();
            $table->string('description');
            $table->json('payload')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('onboarding_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->string('requested_by');
            $table->string('status'); // pending|approved|rejected
            $table->string('reviewed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        // MUTABLE — observation window tracks live pilot state
        Schema::create('pilot_observation_windows', function (Blueprint $table) {
            $table->id();
            $table->string('window_id')->unique();
            $table->string('run_id');
            $table->string('tenant_id');
            $table->integer('duration_hours');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status'); // pending|active|completed|aborted
            $table->boolean('health_ok')->default(true);
            $table->boolean('metrics_meeting_targets')->default(false);
            $table->string('phase'); // 24h|48h|72h|extended
            $table->json('health_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_observation_windows');
        Schema::dropIfExists('onboarding_approval_requests');
        Schema::dropIfExists('pilot_audit_events');
        Schema::dropIfExists('operator_readiness_reviews');
        Schema::dropIfExists('telemetry_onboarding_pressure');
        Schema::dropIfExists('pilot_rollback_validations');
        Schema::dropIfExists('pilot_success_metrics');
        Schema::dropIfExists('pilot_health_validations');
        Schema::dropIfExists('pilot_onboarding_runs');
    }
};
