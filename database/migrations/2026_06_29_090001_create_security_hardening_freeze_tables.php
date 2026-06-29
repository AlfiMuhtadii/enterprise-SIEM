<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ENTERPRISE-074: all tables append-only — never UPDATE or DELETE rows

        Schema::create('security_hardening_freeze_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('freeze_version', 16)->default('v1');
            $table->string('operator_id', 128)->nullable();
            $table->string('run_state', 24)->default('pending')->index();
            $table->integer('controls_total')->default(0);
            $table->integer('controls_passed')->default(0);
            $table->integer('controls_failed')->default(0);
            $table->decimal('coverage_score', 5, 4)->nullable();
            $table->boolean('advisory_only')->default(true);
            $table->boolean('autonomous_certification')->default(false);
            $table->boolean('self_approve_blocked')->default(true);
            $table->jsonb('run_metadata')->nullable();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_checks', function (Blueprint $table) {
            $table->id();
            $table->string('check_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->string('control_id', 64)->index();
            $table->string('control_category', 64)->nullable()->index();
            $table->string('result', 16)->default('pending')->index();
            $table->boolean('passed')->default(false)->index();
            $table->text('detail')->nullable();
            $table->boolean('advisory_only')->default(true);
            $table->jsonb('check_metadata')->nullable();
            $table->timestampTz('evaluated_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_control_evidence', function (Blueprint $table) {
            $table->id();
            $table->string('evidence_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->string('control_id', 64)->index();
            $table->string('evidence_type', 64)->nullable();
            $table->text('evidence_path')->nullable();
            $table->text('evidence_summary')->nullable();
            $table->boolean('verified')->default(false);
            $table->jsonb('evidence_metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_gate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->string('gate_name', 80)->index();
            $table->boolean('gate_passed')->default(false);
            $table->decimal('gate_score', 5, 4)->nullable();
            $table->string('gate_state', 24)->default('evaluated');
            $table->boolean('autonomous_waiver')->default(false);
            $table->jsonb('gate_detail')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_coverage_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->decimal('overall_score', 5, 4)->default(0);
            $table->integer('total_controls')->default(0);
            $table->integer('passing_controls')->default(0);
            $table->integer('failing_controls')->default(0);
            $table->boolean('meets_pass_threshold')->default(false);
            $table->boolean('advisory_only')->default(true);
            $table->jsonb('per_category_scores')->nullable();
            $table->jsonb('report_metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_remediation_guidance', function (Blueprint $table) {
            $table->id();
            $table->string('guidance_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->string('control_id', 64)->index();
            $table->string('priority', 16)->default('medium')->index();
            $table->text('guidance_text');
            $table->boolean('autonomous_remediation')->default(false);
            $table->jsonb('guidance_metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_certification_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('run_id', 64)->index();
            $table->string('requested_by', 128)->nullable();
            $table->string('request_state', 24)->default('pending')->index();
            $table->boolean('self_approve_blocked')->default(true);
            $table->boolean('autonomous_approval')->default(false);
            $table->boolean('advisory_only')->default(true);
            $table->text('justification')->nullable();
            $table->jsonb('request_metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('run_id', 64)->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('actor', 128)->nullable();
            $table->text('detail')->nullable();
            $table->jsonb('event_metadata')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('security_hardening_freeze_delta_reports', function (Blueprint $table) {
            $table->id();
            $table->string('delta_id', 64)->unique();
            $table->string('current_run_id', 64)->index();
            $table->string('previous_run_id', 64)->nullable()->index();
            $table->integer('controls_added')->default(0);
            $table->integer('controls_removed')->default(0);
            $table->integer('controls_regressed')->default(0);
            $table->integer('controls_improved')->default(0);
            $table->decimal('score_delta', 6, 4)->nullable();
            $table->boolean('regression_detected')->default(false);
            $table->jsonb('delta_metadata')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_hardening_freeze_delta_reports');
        Schema::dropIfExists('security_hardening_freeze_audit_events');
        Schema::dropIfExists('security_hardening_freeze_certification_requests');
        Schema::dropIfExists('security_hardening_freeze_remediation_guidance');
        Schema::dropIfExists('security_hardening_freeze_coverage_reports');
        Schema::dropIfExists('security_hardening_freeze_gate_snapshots');
        Schema::dropIfExists('security_hardening_freeze_control_evidence');
        Schema::dropIfExists('security_hardening_freeze_checks');
        Schema::dropIfExists('security_hardening_freeze_runs');
    }
};
