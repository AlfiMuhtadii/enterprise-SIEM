<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: operational intelligence snapshots
        Schema::create('operational_intelligence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('tenant_id');
            $table->string('snapshot_type'); // daily, weekly, incident_driven, replay
            $table->integer('active_rules');
            $table->integer('shadow_rules');
            $table->float('avg_confidence')->default(0.0);
            $table->integer('alert_count')->default(0);
            $table->integer('false_positive_count')->default(0);
            $table->float('false_positive_rate')->default(0.0);
            $table->integer('chained_detections')->default(0);
            $table->float('coverage_score')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        // Append-only: analyst investigation summaries
        Schema::create('analyst_investigation_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('summary_id')->unique();
            $table->string('tenant_id');
            $table->string('analyst_id');
            $table->string('investigation_id');
            $table->string('attack_tactic')->nullable();
            $table->string('attack_technique')->nullable();
            $table->integer('evidence_count')->default(0);
            $table->integer('chained_count')->default(0);
            $table->float('confidence_score')->default(0.0);
            $table->string('verdict'); // confirmed, dismissed, needs_review, escalated
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence_links')->nullable();
            $table->timestamps();
        });

        // Append-only: detection confidence history
        Schema::create('detection_confidence_history', function (Blueprint $table) {
            $table->id();
            $table->string('history_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->float('confidence_value');
            $table->string('confidence_source'); // rule_base, replay_validated, analyst_adjusted, drift_adjusted
            $table->boolean('replay_consistent')->default(true);
            $table->float('drift_delta')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only: false-positive drift reports
        Schema::create('false_positive_drift_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->float('fp_rate_current')->default(0.0);
            $table->float('fp_rate_baseline')->default(0.0);
            $table->float('drift_magnitude')->default(0.0);
            $table->string('drift_direction'); // increasing, decreasing, stable
            $table->string('probable_cause')->nullable();
            $table->boolean('suppression_recommended')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: attack progression scores
        Schema::create('attack_progression_scores', function (Blueprint $table) {
            $table->id();
            $table->string('score_id')->unique();
            $table->string('tenant_id');
            $table->string('attack_chain_id');
            $table->string('tactic_sequence');
            $table->integer('tactic_count')->default(0);
            $table->float('progression_score')->default(0.0);
            $table->float('confidence_score')->default(0.0);
            $table->boolean('chained_confirmed')->default(false);
            $table->boolean('replay_validated')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('chain_evidence')->nullable();
            $table->timestamps();
        });

        // Mutable: chained investigation views
        Schema::create('chained_investigation_views', function (Blueprint $table) {
            $table->id();
            $table->string('view_id')->unique();
            $table->string('tenant_id');
            $table->string('investigation_id');
            $table->string('status'); // active, archived, replaying
            $table->integer('depth')->default(1);
            $table->integer('node_count')->default(0);
            $table->integer('edge_count')->default(0);
            $table->boolean('bounded_traversal')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('view_state')->nullable();
            $table->timestamps();
        });

        // Append-only: replay confidence validations
        Schema::create('replay_confidence_validations', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->float('original_confidence')->default(0.0);
            $table->float('replay_confidence')->default(0.0);
            $table->float('confidence_delta')->default(0.0);
            $table->boolean('replay_consistent')->default(true);
            $table->string('verdict'); // consistent, drifted, inconclusive
            $table->boolean('is_advisory')->default(true);
            $table->json('replay_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: suppression effectiveness reports
        Schema::create('suppression_effectiveness_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('rule_id');
            $table->string('tenant_id');
            $table->string('suppression_key');
            $table->integer('suppressed_count')->default(0);
            $table->integer('fp_prevented')->default(0);
            $table->integer('tp_suppressed')->default(0);
            $table->float('effectiveness_score')->default(0.0);
            $table->boolean('suppression_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: analyst acknowledgment patterns
        Schema::create('analyst_acknowledgment_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern_id')->unique();
            $table->string('analyst_id');
            $table->string('tenant_id');
            $table->string('rule_id');
            $table->string('acknowledgment_type'); // dismissed_fp, confirmed_tp, escalated, deferred
            $table->float('response_latency_seconds')->default(0.0);
            $table->boolean('replay_consistent')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyst_acknowledgment_patterns');
        Schema::dropIfExists('suppression_effectiveness_reports');
        Schema::dropIfExists('replay_confidence_validations');
        Schema::dropIfExists('chained_investigation_views');
        Schema::dropIfExists('attack_progression_scores');
        Schema::dropIfExists('false_positive_drift_reports');
        Schema::dropIfExists('detection_confidence_history');
        Schema::dropIfExists('analyst_investigation_summaries');
        Schema::dropIfExists('operational_intelligence_snapshots');
    }
};
