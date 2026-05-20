<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — evidence integrity validation run records
        Schema::create('evidence_integrity_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            // scope: investigation, hunt_result, trace, detection_rule, cross_domain, full_sweep
            $table->string('scope', 80)->index();
            $table->string('scope_id', 120)->nullable()->index();
            // status: running, passed, failed, warning
            $table->string('status', 40)->default('running')->index();
            $table->integer('total_records_checked')->default(0);
            $table->integer('integrity_failures')->default(0);
            $table->integer('linkage_failures')->default(0);
            $table->boolean('trace_continuity_ok')->default(false);
            $table->string('integrity_hash', 64)->nullable()->index();
            $table->jsonb('check_summary')->nullable();
            $table->string('triggered_by', 120)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — per-evidence integrity failure detail
        Schema::create('evidence_integrity_failures', function (Blueprint $table) {
            $table->id();
            $table->string('failure_id', 40)->unique()->index();
            $table->string('run_id', 40)->index();
            $table->string('evidence_type', 80)->index();
            $table->string('evidence_id', 120)->index();
            // failure_type: hash_mismatch, linkage_broken, trace_gap, tamper_indicator, missing_record
            $table->string('failure_type', 80)->index();
            $table->text('description')->nullable();
            $table->string('expected_hash', 64)->nullable();
            $table->string('actual_hash', 64)->nullable();
            $table->boolean('drift_detected')->default(false);
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable — versioned snapshot of retention policy state (governance audit)
        Schema::create('retention_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_id', 40)->unique()->index();
            $table->string('policy_id', 80)->index();
            $table->string('version', 20)->default('1.0.0');
            $table->string('version_hash', 64)->index();
            $table->jsonb('policy_snapshot');
            // review_status: pending, reviewed, approved, rejected
            $table->string('review_status', 40)->default('pending')->index();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('change_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // Mutable — governed audit export request workflow
        Schema::create('audit_export_requests', function (Blueprint $table) {
            $table->id();
            $table->string('export_id', 40)->unique()->index();
            // scope: investigation, trace, incident, hunt_results, full_audit
            $table->string('scope', 80)->index();
            $table->string('scope_id', 120)->nullable()->index();
            $table->string('export_format', 20)->default('json');
            // status: draft, pending_approval, approved, packaged, expired, rejected
            $table->string('status', 40)->default('draft')->index();
            $table->string('integrity_checksum', 64)->nullable()->index();
            $table->boolean('pii_masked')->default(true);
            $table->text('export_reason')->nullable();
            $table->string('tenant_scope', 120)->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('packaged_at')->nullable();
            $table->timestamps();
        });

        // Append-only — log of who accessed each export
        Schema::create('audit_export_access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_id', 40)->unique()->index();
            $table->string('export_id', 40)->index();
            $table->string('access_type', 40)->default('view');
            $table->unsignedBigInteger('accessed_by')->nullable();
            $table->foreign('accessed_by')->references('id')->on('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — tenant/scope isolation validation run
        Schema::create('tenant_isolation_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            $table->string('tenant_scope', 120)->index();
            // check_type: query_scope, evidence_bound, graph_traversal, replay_pack, export_scope
            $table->string('check_type', 80)->index();
            // status: passed, failed, warning
            $table->string('status', 40)->default('passed')->index();
            $table->integer('violations_found')->default(0);
            $table->jsonb('violation_details')->nullable();
            $table->boolean('cross_tenant_detected')->default(false);
            $table->string('triggered_by', 120)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — PII sensitive field access audit
        Schema::create('pii_access_audit', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 40)->unique()->index();
            $table->string('field_name', 120)->index();
            // pii_category: email, name, ip_address, credential, token, session_id, custom
            $table->string('pii_category', 80)->index();
            $table->string('accessed_by_user', 120)->nullable()->index();
            $table->string('accessed_by_role', 80)->nullable();
            $table->string('source_entity', 120)->nullable()->index();
            // access_context: export, api_view, investigation, trace_view, report
            $table->string('access_context', 80)->index();
            $table->boolean('was_masked')->default(true);
            $table->boolean('masking_policy_applied')->default(true);
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable — privileged access review lifecycle
        Schema::create('governance_access_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('review_id', 40)->unique()->index();
            $table->string('subject_user', 120)->index();
            $table->string('privileged_role', 80)->index();
            // review_status: open, in_review, approved, revoked, expired
            $table->string('review_status', 40)->default('open')->index();
            $table->date('review_window_start')->nullable();
            $table->date('review_window_end')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->integer('days_since_last_review')->default(0);
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->foreign('reviewer_id')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_decision_rationale')->nullable();
            $table->timestamps();
        });

        // Append-only — governance review findings
        Schema::create('governance_review_findings', function (Blueprint $table) {
            $table->id();
            $table->string('finding_id', 40)->unique()->index();
            $table->string('review_id', 40)->nullable()->index();
            // finding_type: stale_privilege, excess_access, policy_violation, pii_exposure, export_anomaly
            $table->string('finding_type', 80)->index();
            // severity: informational, warning, critical
            $table->string('severity', 40)->default('informational')->index();
            $table->text('description');
            $table->jsonb('evidence_links')->nullable();
            $table->boolean('remediation_required')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('trace_id', 120)->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_review_findings');
        Schema::dropIfExists('governance_access_reviews');
        Schema::dropIfExists('pii_access_audit');
        Schema::dropIfExists('tenant_isolation_validation_runs');
        Schema::dropIfExists('audit_export_access_logs');
        Schema::dropIfExists('audit_export_requests');
        Schema::dropIfExists('retention_policy_versions');
        Schema::dropIfExists('evidence_integrity_failures');
        Schema::dropIfExists('evidence_integrity_runs');
    }
};
