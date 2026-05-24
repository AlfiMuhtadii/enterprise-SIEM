<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable — tracks fixture revisions; delete is forbidden
        Schema::create('synthetic_attack_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('fixture_id')->unique();
            $table->string('fixture_scenario');
            $table->string('fixture_format')->default('json');
            $table->json('event_sequence');
            $table->string('trace_id');
            $table->string('chain_id');
            $table->json('mitre_tactics');
            $table->integer('total_events')->default(0);
            $table->boolean('is_lab_safe')->default(true);
            $table->boolean('is_destructive')->default(false);
            $table->boolean('has_live_execution')->default(false);
            $table->boolean('has_destructive_payload')->default(false);
            $table->string('fixture_state')->default('draft');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Append-only
        Schema::create('detection_quality_scorecards', function (Blueprint $table) {
            $table->id();
            $table->string('scorecard_id')->unique();
            $table->string('scored_rule_id')->nullable();
            $table->float('precision_score')->default(0);
            $table->float('recall_score')->default(0);
            $table->float('false_positive_rate')->default(0);
            $table->float('false_negative_rate')->default(0);
            $table->float('detection_coverage')->default(0);
            $table->float('attack_coverage')->default(0);
            $table->float('evidence_completeness')->default(0);
            $table->float('confidence_stability')->default(0);
            $table->float('alert_amplification_ratio')->default(1.0);
            $table->float('overall_quality_score')->default(0);
            $table->boolean('is_advisory')->default(true);
            $table->json('score_components')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('false_positive_negative_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('analyzed_rule_id')->nullable();
            $table->integer('noisy_rule_count')->default(0);
            $table->integer('repeated_benign_count')->default(0);
            $table->integer('suppressed_recurring_count')->default(0);
            $table->integer('missed_chain_count')->default(0);
            $table->integer('weak_evidence_count')->default(0);
            $table->integer('unstable_confidence_count')->default(0);
            $table->float('fp_prevalence_rate')->default(0);
            $table->float('fn_prevalence_rate')->default(0);
            $table->boolean('advisory_only')->default(true);
            $table->boolean('no_auto_suppression')->default(true);
            $table->boolean('no_auto_closure')->default(true);
            $table->json('findings')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('telemetry_quality_scorecards', function (Blueprint $table) {
            $table->id();
            $table->string('scorecard_id')->unique();
            $table->string('tenant_id')->nullable();
            $table->float('trace_id_completeness')->default(0);
            $table->float('event_id_completeness')->default(0);
            $table->float('timestamp_consistency')->default(0);
            $table->float('host_agent_presence')->default(0);
            $table->float('process_lineage_completeness')->default(0);
            $table->float('malformed_event_ratio')->default(0);
            $table->float('orphaned_event_ratio')->default(0);
            $table->float('enrichment_coverage')->default(0);
            $table->float('tenant_id_consistency')->default(0);
            $table->float('overall_quality_score')->default(0);
            $table->integer('events_sampled')->default(0);
            $table->boolean('is_advisory')->default(true);
            $table->json('quality_breakdown')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('analyst_triage_simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id')->unique();
            $table->string('simulation_scenario');
            $table->integer('alerts_simulated')->default(0);
            $table->integer('duplicates_handled')->default(0);
            $table->integer('chains_investigated')->default(0);
            $table->integer('escalations_decided')->default(0);
            $table->integer('dismissals_decided')->default(0);
            $table->integer('fp_markings')->default(0);
            $table->integer('handoffs_simulated')->default(0);
            $table->float('triage_efficiency_score')->default(0);
            $table->boolean('no_real_user_mutation')->default(true);
            $table->boolean('no_hidden_suppression')->default(true);
            $table->boolean('no_auto_incident_closure')->default(true);
            $table->json('audit_trail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('performance_hotspot_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->integer('slow_chain_count')->default(0);
            $table->integer('expensive_graph_count')->default(0);
            $table->integer('replay_amplification_count')->default(0);
            $table->integer('high_cardinality_count')->default(0);
            $table->integer('noisy_tenant_count')->default(0);
            $table->integer('alert_spike_count')->default(0);
            $table->integer('expensive_hunt_count')->default(0);
            $table->float('hotspot_severity_score')->default(0);
            $table->boolean('advisory_only')->default(true);
            $table->boolean('no_destructive_load_test')->default(true);
            $table->json('hotspot_details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('noisy_enterprise_simulations', function (Blueprint $table) {
            $table->id();
            $table->string('simulation_id')->unique();
            $table->string('noise_scenario');
            $table->integer('events_generated')->default(0);
            $table->float('fp_resistance_score')->default(0);
            $table->float('alert_amplification_score')->default(0);
            $table->float('analyst_pressure_score')->default(0);
            $table->float('telemetry_quality_under_noise')->default(0);
            $table->boolean('is_synthetic')->default(true);
            $table->boolean('is_lab_safe')->default(true);
            $table->boolean('has_destructive_content')->default(false);
            $table->json('simulation_metrics')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('xdr_maturity_scorecards', function (Blueprint $table) {
            $table->id();
            $table->string('scorecard_id')->unique();
            $table->float('detection_quality_score')->default(0);
            $table->float('telemetry_quality_score')->default(0);
            $table->float('fp_fn_health_score')->default(0);
            $table->float('triage_realism_score')->default(0);
            $table->float('performance_health_score')->default(0);
            $table->float('noise_resilience_score')->default(0);
            $table->float('attack_coverage_score')->default(0);
            $table->float('overall_maturity_score')->default(0);
            $table->string('maturity_tier')->default('developing');
            $table->boolean('is_advisory')->default(true);
            $table->json('component_scores')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only
        Schema::create('xdr_readiness_report_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('artifact_id')->unique();
            $table->string('report_type');
            $table->json('report_json');
            $table->text('report_markdown')->nullable();
            $table->string('artifact_hash');
            $table->boolean('is_advisory')->default(true);
            $table->boolean('is_fabricated')->default(false);
            $table->boolean('evidence_linked')->default(true);
            $table->json('evidence_refs')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xdr_readiness_report_artifacts');
        Schema::dropIfExists('xdr_maturity_scorecards');
        Schema::dropIfExists('noisy_enterprise_simulations');
        Schema::dropIfExists('performance_hotspot_reports');
        Schema::dropIfExists('analyst_triage_simulation_runs');
        Schema::dropIfExists('telemetry_quality_scorecards');
        Schema::dropIfExists('false_positive_negative_reports');
        Schema::dropIfExists('detection_quality_scorecards');
        Schema::dropIfExists('synthetic_attack_fixtures');
    }
};
