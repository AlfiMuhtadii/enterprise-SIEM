<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_isolation_audits', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->string('tenant_id');
            $table->string('scope'); // telemetry|graph|replay|export|hunt|dashboard|soar|evidence|namespace
            $table->string('verdict'); // pass|fail|partial
            $table->boolean('isolation_ok');
            $table->integer('checks_total')->default(0);
            $table->integer('checks_passed')->default(0);
            $table->integer('checks_failed')->default(0);
            $table->json('findings')->nullable();
            $table->string('operator_id')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_context_propagation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->string('trace_id');
            $table->boolean('context_ok');
            $table->integer('hops_total')->default(0);
            $table->integer('hops_validated')->default(0);
            $table->integer('attribution_failures')->default(0);
            $table->json('context_frames')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_replay_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('tenant_id');
            $table->string('replay_id');
            $table->boolean('replay_isolated');
            $table->boolean('ordering_deterministic');
            $table->integer('events_replayed')->default(0);
            $table->integer('cross_tenant_detected')->default(0);
            $table->string('verdict'); // pass|fail|contaminated
            $table->json('lineage_refs')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_graph_isolation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->string('graph_id');
            $table->boolean('isolation_ok');
            $table->integer('nodes_validated')->default(0);
            $table->integer('edges_validated')->default(0);
            $table->integer('cross_tenant_edges_detected')->default(0);
            $table->integer('shared_evidence_detected')->default(0);
            $table->integer('traversal_depth')->default(0);
            $table->string('verdict'); // pass|fail|partial
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_export_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('tenant_id');
            $table->string('export_id');
            $table->string('export_scope'); // investigation|hunt|evidence|alerts|incidents
            $table->boolean('scope_ok');
            $table->boolean('integrity_ok');
            $table->string('checksum')->nullable();
            $table->boolean('approval_required');
            $table->boolean('approved')->default(false);
            $table->string('approved_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('verdict'); // pass|fail|expired
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_namespace_validation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->json('namespaces_checked'); // cache|queue|index|spool|replay
            $table->integer('namespaces_valid')->default(0);
            $table->integer('namespaces_invalid')->default(0);
            $table->boolean('crossover_detected');
            $table->json('invalid_namespaces')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_boundary_violation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->string('violation_type'); // graph_crossover|replay_contamination|export_leakage|context_override|namespace_crossover
            $table->string('source_tenant_id')->nullable();
            $table->string('target_tenant_id')->nullable();
            $table->text('description');
            $table->string('severity'); // low|medium|high|critical
            $table->json('evidence_refs')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_replay_lineage', function (Blueprint $table) {
            $table->id();
            $table->string('lineage_id')->unique();
            $table->string('tenant_id');
            $table->string('replay_id');
            $table->string('parent_replay_id')->nullable();
            $table->string('origin_tenant_id');
            $table->integer('replay_depth')->default(0);
            $table->json('lineage_chain')->nullable();
            $table->boolean('lineage_clean'); // no cross-tenant contamination
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_evidence_integrity_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id')->unique();
            $table->string('tenant_id');
            $table->integer('evidence_refs_checked')->default(0);
            $table->integer('integrity_ok')->default(0);
            $table->integer('integrity_failed')->default(0);
            $table->integer('cross_tenant_refs')->default(0);
            $table->string('verdict'); // pass|fail|partial
            $table->json('failed_refs')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_evidence_integrity_reports');
        Schema::dropIfExists('tenant_replay_lineage');
        Schema::dropIfExists('tenant_boundary_violation_reports');
        Schema::dropIfExists('tenant_namespace_validation_reports');
        Schema::dropIfExists('tenant_export_validation_runs');
        Schema::dropIfExists('tenant_graph_isolation_reports');
        Schema::dropIfExists('tenant_replay_validation_runs');
        Schema::dropIfExists('tenant_context_propagation_runs');
        Schema::dropIfExists('tenant_isolation_audits');
    }
};
