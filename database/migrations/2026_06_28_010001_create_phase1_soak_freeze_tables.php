<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-064: Phase 1 Soak Evidence Freeze
     *
     * Three append-only tables that capture an immutable snapshot of the
     * full P1G-01..P1G-08 evidence chain at the point when Decision: PASS
     * was achieved.
     *
     * NEVER UPDATE or DELETE rows in any of these tables.
     * freeze_approved = false ALWAYS — no freeze run authorizes promotion.
     */
    public function up(): void
    {
        Schema::create('phase1_soak_freeze_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->unique()->index();
            $table->string('freeze_version', 32)->default('E064');
            $table->unsignedTinyInteger('gates_total')->default(0);
            $table->unsignedTinyInteger('gates_passed')->default(0);
            $table->unsignedTinyInteger('gates_warned')->default(0);
            $table->unsignedTinyInteger('gates_failed')->default(0);
            $table->decimal('pass_score', 5, 3)->default(0.000);
            $table->string('verdict', 16)->default('PENDING');   // PASS|WARN|FAIL
            $table->boolean('no_promotion')->default(true);       // ALWAYS true
            $table->boolean('freeze_approved')->default(false);   // ALWAYS false
            $table->boolean('is_advisory')->default(true);
            $table->boolean('is_dry_run')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('frozen_at')->useCurrent();
        });

        Schema::create('phase1_soak_freeze_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gate_id', 16);                        // EV064-01..EV064-12
            $table->string('gate_name', 200);
            $table->string('status', 8)->default('warn');         // pass|warn|fail
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(false);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('evaluated_at')->useCurrent();
            $table->index(['freeze_run_id', 'gate_id']);
        });

        Schema::create('phase1_soak_freeze_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('evidence_type', 64);    // soak_run_decision, empirical_count, etc.
            $table->string('evidence_value', 255);
            $table->string('source_table', 128)->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('captured_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phase1_soak_freeze_evidence');
        Schema::dropIfExists('phase1_soak_freeze_gates');
        Schema::dropIfExists('phase1_soak_freeze_runs');
    }
};
