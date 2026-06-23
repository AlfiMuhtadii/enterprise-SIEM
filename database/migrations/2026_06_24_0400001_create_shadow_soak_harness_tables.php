<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable: tracks the active soak run lifecycle
        Schema::create('shadow_soak_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('domain');            // endpoint | network | ueba
            $table->integer('window_hours');
            $table->string('status');            // running | completed | failed | dry_run
            $table->boolean('dry_run')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('initiated_by')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('gate_summary')->nullable();    // pass/fail per gate
            $table->boolean('evidence_pass')->default(false);
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: raw evidence snapshot taken at start of soak
        Schema::create('shadow_soak_evidence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->integer('total_findings');
            $table->integer('high_confidence_findings');
            $table->float('high_confidence_rate');
            $table->integer('suppressed_findings');
            $table->float('suppression_rate');
            $table->integer('rules_covered');
            $table->float('rule_coverage_rate');
            $table->json('severity_distribution')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: individual gate check results
        Schema::create('shadow_soak_gate_checks', function (Blueprint $table) {
            $table->id();
            $table->string('check_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->string('gate_name');
            $table->string('result');           // pass | fail | skip
            $table->float('actual_value')->nullable();
            $table->float('threshold_value')->nullable();
            $table->string('comparison')->nullable();  // >= | <= | =
            $table->text('note')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: per-domain overall assessment record
        Schema::create('shadow_soak_domain_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('assessment_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->integer('gates_checked');
            $table->integer('gates_passed');
            $table->integer('gates_failed');
            $table->float('pass_rate');
            $table->boolean('evidence_pass');
            $table->boolean('promotion_recommended')->default(false);
            $table->text('recommendation_note')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: summary of findings by rule during soak window
        Schema::create('shadow_soak_finding_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('summary_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->string('rule_id');
            $table->integer('finding_count');
            $table->integer('high_confidence_count');
            $table->float('avg_confidence');
            $table->string('max_severity')->nullable();
            $table->boolean('has_suppressed')->default(false);
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: confidence band distribution snapshot
        Schema::create('shadow_soak_confidence_bands', function (Blueprint $table) {
            $table->id();
            $table->string('band_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->integer('band_low');        // findings with confidence < 0.50
            $table->integer('band_medium');     // 0.50 – 0.65
            $table->integer('band_high');       // 0.65 – 0.80
            $table->integer('band_critical');   // >= 0.80
            $table->float('high_plus_rate');    // (high + critical) / total
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: suppression statistics per soak run
        Schema::create('shadow_soak_suppression_stats', function (Blueprint $table) {
            $table->id();
            $table->string('stat_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->integer('total_findings');
            $table->integer('suppressed_count');
            $table->float('suppression_rate');
            $table->boolean('within_threshold');  // <= MAX_SUPPRESSION_RATE
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: rule coverage statistics per soak run
        Schema::create('shadow_soak_coverage_stats', function (Blueprint $table) {
            $table->id();
            $table->string('stat_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->integer('total_shadow_rules');
            $table->integer('rules_with_findings');
            $table->float('coverage_rate');
            $table->json('rules_without_findings')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        // Append-only: immutable audit trail for all soak lifecycle events
        Schema::create('shadow_soak_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('run_id');
            $table->string('domain');
            $table->string('event_type');       // run_started | evidence_computed | gate_checked | assessment_recorded | run_completed | run_failed
            $table->string('actor')->nullable();
            $table->json('payload')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_soak_audit_events');
        Schema::dropIfExists('shadow_soak_coverage_stats');
        Schema::dropIfExists('shadow_soak_suppression_stats');
        Schema::dropIfExists('shadow_soak_confidence_bands');
        Schema::dropIfExists('shadow_soak_finding_summaries');
        Schema::dropIfExists('shadow_soak_domain_assessments');
        Schema::dropIfExists('shadow_soak_gate_checks');
        Schema::dropIfExists('shadow_soak_evidence_snapshots');
        Schema::dropIfExists('shadow_soak_runs');
    }
};
