<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // append-only: adversarial replay validation runs
        Schema::create('adversarial_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('scenario_pack_id', 64)->nullable();
            $table->string('scenario_name', 255);
            $table->string('attack_tactic', 64)->nullable();
            $table->string('attack_technique', 32)->nullable();
            $table->string('verdict', 32)->default('pending');
            $table->boolean('detected')->default(false);
            $table->boolean('false_positive_free')->default(true);
            $table->decimal('detection_confidence', 5, 3)->default(0.0);
            $table->integer('replay_event_count')->default(0);
            $table->integer('matched_rules')->default(0);
            $table->jsonb('matched_rule_ids')->default('[]');
            $table->jsonb('validation_details')->default('{}');
            $table->string('triggered_by', 128);
            $table->timestamps();
        });

        // mutable: attack scenario packs (living definitions, versioned separately)
        Schema::create('attack_scenario_packs', function (Blueprint $table) {
            $table->id();
            $table->string('pack_id', 64)->unique();
            $table->string('name', 255);
            $table->string('attack_tactic', 64);
            $table->jsonb('technique_ids')->default('[]');
            $table->text('description')->nullable();
            $table->jsonb('fixture_event_types')->default('[]');
            $table->string('difficulty', 32)->default('medium');
            $table->boolean('is_active')->default(true);
            $table->string('owner', 128)->default('detection-engineering');
            $table->timestamps();
        });

        // append-only: chained behavioral detection graphs
        Schema::create('chained_detection_graphs', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id', 64)->unique();
            $table->string('chain_type', 64);
            $table->jsonb('node_sequence')->default('[]');
            $table->jsonb('tactic_sequence')->default('[]');
            $table->integer('hop_count')->default(0);
            $table->decimal('chain_confidence', 5, 3)->default(0.0);
            $table->string('host_id', 255)->nullable();
            $table->string('actor', 255)->nullable();
            $table->string('status', 32)->default('active');
            $table->jsonb('evidence_links')->default('[]');
            $table->string('triggered_by', 128);
            $table->timestamps();
        });

        // append-only: evasion resilience test reports
        Schema::create('evasion_resilience_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('evasion_type', 64);
            $table->string('target_rule_id', 128)->nullable();
            $table->boolean('detection_survived')->default(false);
            $table->decimal('confidence_degradation', 5, 3)->default(0.0);
            $table->decimal('resilience_score', 5, 3)->default(0.0);
            $table->jsonb('degradation_factors')->default('[]');
            $table->string('tested_by', 128);
            $table->timestamps();
        });

        // append-only: reconstructed attack chain timelines
        Schema::create('attack_chain_timelines', function (Blueprint $table) {
            $table->id();
            $table->string('timeline_id', 64)->unique();
            $table->string('chain_id', 64)->nullable();
            $table->string('tactic', 64);
            $table->string('technique_id', 32)->nullable();
            $table->string('host_id', 255)->nullable();
            $table->string('actor', 255)->nullable();
            $table->string('event_type', 128);
            $table->jsonb('evidence_snapshot')->default('{}');
            $table->integer('sequence_index')->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        // append-only: per-rule detection confidence reports
        Schema::create('detection_confidence_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('rule_id', 128);
            $table->decimal('confidence_score', 5, 3);
            $table->integer('true_positive_count')->default(0);
            $table->integer('false_positive_count')->default(0);
            $table->integer('replay_sample_size')->default(0);
            $table->decimal('fp_rate', 5, 3)->default(0.0);
            $table->string('assessment_method', 64)->default('replay_validation');
            $table->jsonb('contributing_factors')->default('[]');
            $table->string('evaluated_by', 128);
            $table->timestamps();
        });

        // mutable: replay attack fixture definitions
        Schema::create('replay_attack_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('fixture_id', 64)->unique();
            $table->string('name', 255);
            $table->string('attack_tactic', 64);
            $table->string('technique_id', 32)->nullable();
            $table->string('fixture_type', 64)->default('benign');
            $table->jsonb('event_sequence')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->string('owner', 128)->default('detection-engineering');
            $table->timestamps();
        });

        // append-only: tactic progression snapshots
        Schema::create('tactic_progression_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 64)->unique();
            $table->string('host_id', 255)->nullable();
            $table->string('actor', 255)->nullable();
            $table->jsonb('observed_tactics')->default('[]');
            $table->jsonb('observed_techniques')->default('[]');
            $table->integer('tactic_count')->default(0);
            $table->boolean('multi_stage')->default(false);
            $table->decimal('progression_score', 5, 3)->default(0.0);
            $table->string('detection_scope', 64)->default('endpoint');
            $table->timestamps();
        });

        // append-only: cross-host correlation runs
        Schema::create('cross_host_correlation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->jsonb('host_ids')->default('[]');
            $table->string('correlation_type', 64);
            $table->integer('host_count')->default(0);
            $table->boolean('propagation_detected')->default(false);
            $table->decimal('correlation_confidence', 5, 3)->default(0.0);
            $table->jsonb('shared_indicators')->default('[]');
            $table->string('triggered_by', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_host_correlation_runs');
        Schema::dropIfExists('tactic_progression_snapshots');
        Schema::dropIfExists('replay_attack_fixtures');
        Schema::dropIfExists('detection_confidence_reports');
        Schema::dropIfExists('attack_chain_timelines');
        Schema::dropIfExists('evasion_resilience_reports');
        Schema::dropIfExists('chained_detection_graphs');
        Schema::dropIfExists('attack_scenario_packs');
        Schema::dropIfExists('adversarial_validation_runs');
    }
};
