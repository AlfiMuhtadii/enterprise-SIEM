<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable rule version snapshots — append-only (no updated_at)
        Schema::create('detection_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->string('version', 20);
            // hash of canonical rule fields — deterministic, sha256
            $table->string('rule_hash', 64)->index();
            $table->string('status', 40);
            $table->string('stage', 40);
            $table->boolean('shadow_only')->default(true);
            $table->string('output_topic', 120)->nullable();
            $table->string('owner', 120)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->string('previous_version_id', 40)->nullable()->index();
            // snapshot of full registry entry at time of version
            $table->jsonb('rule_snapshot')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Test cases for rules — mutable (can update expected outcome as detection matures)
        Schema::create('detection_rule_test_cases', function (Blueprint $table) {
            $table->id();
            $table->string('test_case_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // true_positive | false_positive | true_negative
            $table->string('expected_outcome', 40)->default('true_positive');
            // JSONL fixture events or fixture_path reference
            $table->jsonb('event_fixtures')->nullable();
            $table->string('fixture_path', 512)->nullable();
            $table->jsonb('expected_fields')->nullable();  // fields that must appear in match
            $table->string('expected_severity', 40)->nullable();
            $table->boolean('expect_trace_id')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // Replay packs — named collections of test cases for a rule — mutable
        Schema::create('detection_replay_packs', function (Blueprint $table) {
            $table->id();
            $table->string('pack_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // expected_matches, expected_non_matches counts
            $table->unsignedInteger('expected_match_count')->default(0);
            $table->unsignedInteger('expected_non_match_count')->default(0);
            $table->string('pack_version', 20)->default('v1');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // Replay execution results — append-only (no updated_at)
        Schema::create('detection_replay_results', function (Blueprint $table) {
            $table->id();
            $table->string('result_id', 40)->unique()->index();
            $table->string('pack_id', 40)->index();
            $table->string('rule_id', 120)->index();
            $table->boolean('passed');
            $table->unsignedInteger('cases_run')->default(0);
            $table->unsignedInteger('cases_passed')->default(0);
            $table->unsignedInteger('cases_failed')->default(0);
            $table->jsonb('failure_details')->nullable();  // per-case failure reasons
            $table->boolean('evidence_mismatch')->default(false);
            $table->boolean('trace_id_missing')->default(false);
            $table->boolean('unexpected_enforcement')->default(false);
            $table->string('runner', 120)->nullable();  // who/what ran the replay
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
            $table->string('report_path', 512)->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Analyst false-positive reports — append-only (no updated_at)
        Schema::create('detection_false_positive_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
            // reason taxonomy: benign_activity | noisy_condition | misconfiguration | context_gap | other
            $table->string('reason_type', 60)->index();
            $table->text('reason_detail');
            // alert evidence linkage
            $table->string('alert_id', 120)->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('event_evidence')->nullable();
            // suppression recommendation
            $table->boolean('recommends_suppression')->default(false);
            $table->text('suppression_scope')->nullable();
            $table->string('analyst_verdict', 40)->default('under_review');
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Suppression records — mutable (can expire, revoke, extend)
        Schema::create('detection_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('suppression_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            // scope: global | host_scoped | user_scoped | ip_scoped
            $table->string('scope', 40)->default('global');
            $table->string('scope_value', 512)->nullable();  // e.g., hostname, username, IP
            $table->text('reason');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->string('approved_by', 120)->nullable();
            // pending | approved | rejected | expired | revoked
            $table->string('approval_state', 40)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_active')->default(false)->index();
            // fp_report linkage
            $table->string('fp_report_id', 40)->nullable();
            $table->timestamps();
        });

        // MITRE ATT&CK mappings with confidence and evidence — mutable
        Schema::create('detection_attack_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->string('tactic', 120);
            $table->string('technique_id', 40)->index();   // e.g., T1059.001
            $table->string('technique_name', 255);
            $table->string('sub_technique_id', 40)->nullable();
            $table->float('confidence')->default(0.75);
            // registry | analyst | threat_intel | test_validation
            $table->string('mapping_source', 60)->default('registry');
            $table->text('evidence_reference')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Promotion requests — append-only (no updated_at)
        Schema::create('detection_promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 40)->unique()->index();
            $table->string('rule_id', 120)->index();
            $table->string('from_stage', 40);
            $table->string('to_stage', 40);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->text('rationale')->nullable();
            // pending | approved | rejected | withdrawn
            $table->string('status', 40)->default('pending')->index();
            $table->string('reviewed_by', 120)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            // evidence references
            $table->string('replay_result_id', 40)->nullable();
            $table->string('soak_report_path', 512)->nullable();
            $table->jsonb('gate_snapshot')->nullable();  // gate state at time of request
            $table->jsonb('fp_summary')->nullable();     // FP rate summary at time of request
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Rule quality metrics — mutable (updated on each quality computation)
        Schema::create('detection_quality_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 120)->unique()->index();
            $table->float('quality_score')->default(0.0);    // 0.0–1.0
            $table->unsignedInteger('replay_pass_count')->default(0);
            $table->unsignedInteger('replay_fail_count')->default(0);
            $table->unsignedInteger('fp_report_count')->default(0);
            $table->unsignedInteger('suppression_count')->default(0);
            $table->unsignedInteger('version_count')->default(0);
            $table->float('fp_rate_7d')->default(0.0);
            $table->float('fp_rate_30d')->default(0.0);
            $table->string('quality_trend', 20)->default('stable');  // improving | stable | degrading
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_quality_metrics');
        Schema::dropIfExists('detection_promotion_requests');
        Schema::dropIfExists('detection_attack_mappings');
        Schema::dropIfExists('detection_suppressions');
        Schema::dropIfExists('detection_false_positive_reports');
        Schema::dropIfExists('detection_replay_results');
        Schema::dropIfExists('detection_replay_packs');
        Schema::dropIfExists('detection_rule_test_cases');
        Schema::dropIfExists('detection_rule_versions');
    }
};
