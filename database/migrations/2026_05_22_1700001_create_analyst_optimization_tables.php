<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: analyst workload snapshots
        Schema::create('analyst_workload_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('shift_id')->nullable();
            $table->integer('open_investigations')->default(0);
            $table->integer('pending_acknowledgments')->default(0);
            $table->integer('escalation_queue_depth')->default(0);
            $table->float('avg_acknowledgment_latency_seconds')->default(0.0);
            $table->integer('investigations_completed_last_8h')->default(0);
            $table->float('workload_score')->default(0.0);
            $table->boolean('overload_indicator')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only: alert prioritization scores
        Schema::create('alert_prioritization_scores', function (Blueprint $table) {
            $table->id();
            $table->string('score_id')->unique();
            $table->string('alert_id');
            $table->string('tenant_id');
            $table->string('rule_id');
            $table->float('base_severity_score')->default(0.0);
            $table->float('replay_confidence_factor')->default(1.0);
            $table->float('recurrence_factor')->default(1.0);
            $table->float('escalation_frequency_factor')->default(1.0);
            $table->float('final_priority_score')->default(0.0);
            $table->string('priority_tier'); // critical, high, medium, low
            $table->boolean('replay_validated')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('scoring_factors')->nullable();
            $table->timestamps();
        });

        // Append-only: false-positive tuning reports
        Schema::create('false_positive_tuning_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->string('analyst_id');
            $table->string('tuning_action'); // suppress, tune_threshold, add_allowlist, review_deferred
            $table->string('suppression_scope')->nullable();
            $table->integer('suppression_duration_days')->nullable();
            $table->float('fp_rate_before')->default(0.0);
            $table->float('fp_rate_after_estimate')->default(0.0);
            $table->boolean('replay_validated')->default(false);
            $table->boolean('expiry_tracked')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: analyst acknowledgment audit
        Schema::create('analyst_acknowledgment_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('alert_id');
            $table->string('rule_id');
            $table->string('acknowledgment_action'); // dismissed, confirmed, escalated, deferred, re_queued
            $table->float('latency_seconds')->default(0.0);
            $table->boolean('repeated_dismissal')->default(false);
            $table->integer('dismissal_count')->default(1);
            $table->boolean('replay_consistent')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('context')->nullable();
            $table->timestamps();
        });

        // Append-only: escalation quality reviews
        Schema::create('escalation_quality_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('review_id')->unique();
            $table->string('escalation_id');
            $table->string('tenant_id');
            $table->string('reviewed_by');
            $table->float('quality_score')->default(0.0);
            $table->string('quality_tier'); // high, medium, low, noise
            $table->boolean('evidence_sufficient')->default(true);
            $table->boolean('severity_appropriate')->default(true);
            $table->boolean('replay_validated')->default(false);
            $table->string('verdict'); // valid, over_escalated, under_escalated, noise
            $table->boolean('is_advisory')->default(true);
            $table->json('review_notes')->nullable();
            $table->timestamps();
        });

        // Mutable: investigation ergonomic views
        Schema::create('investigation_ergonomic_views', function (Blueprint $table) {
            $table->id();
            $table->string('view_id')->unique();
            $table->string('investigation_id');
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('status'); // active, bookmarked, archived, replaying
            $table->integer('evidence_count')->default(0);
            $table->integer('bookmark_count')->default(0);
            $table->boolean('timeline_compressed')->default(false);
            $table->boolean('chain_summarized')->default(false);
            $table->boolean('bounded_traversal')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('view_state')->nullable();
            $table->timestamps();
        });

        // Append-only: alert recurrence reports
        Schema::create('alert_recurrence_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->integer('recurrence_count')->default(1);
            $table->integer('window_hours')->default(24);
            $table->float('recurrence_rate')->default(0.0);
            $table->boolean('suppression_candidate')->default(false);
            $table->boolean('replay_consistent')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('recurrence_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: operational fatigue indicators
        Schema::create('operational_fatigue_indicators', function (Blueprint $table) {
            $table->id();
            $table->string('indicator_id')->unique();
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('shift_id')->nullable();
            $table->float('dismissal_acceleration_rate')->default(0.0);
            $table->float('avg_review_time_seconds')->default(0.0);
            $table->float('baseline_review_time_seconds')->default(0.0);
            $table->integer('consecutive_dismissals')->default(0);
            $table->boolean('fatigue_detected')->default(false);
            $table->string('fatigue_severity')->default('none'); // none, low, medium, high
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: shift handoff validations
        Schema::create('shift_handoff_validations', function (Blueprint $table) {
            $table->id();
            $table->string('handoff_id')->unique();
            $table->string('outgoing_analyst_id');
            $table->string('incoming_analyst_id');
            $table->string('tenant_id');
            $table->string('shift_id');
            $table->integer('open_investigations_handed_off')->default(0);
            $table->integer('pending_escalations_handed_off')->default(0);
            $table->boolean('context_documented')->default(false);
            $table->boolean('replay_validated')->default(false);
            $table->boolean('continuity_preserved')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('handoff_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_handoff_validations');
        Schema::dropIfExists('operational_fatigue_indicators');
        Schema::dropIfExists('alert_recurrence_reports');
        Schema::dropIfExists('investigation_ergonomic_views');
        Schema::dropIfExists('escalation_quality_reviews');
        Schema::dropIfExists('analyst_acknowledgment_audit');
        Schema::dropIfExists('false_positive_tuning_reports');
        Schema::dropIfExists('alert_prioritization_scores');
        Schema::dropIfExists('analyst_workload_snapshots');
    }
};
