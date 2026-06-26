<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-055: Stability Evidence Freeze v3
     *
     * Five append-only tables consolidating evidence across E045-E054.
     * freeze_approved = false always. NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        Schema::create('stability_v3_freeze_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->unique()->index();
            $table->string('freeze_version', 32)->default('v3');
            $table->string('phase_range', 64)->default('E045-E054');
            $table->unsignedInteger('total_gates')->default(0);
            $table->unsignedInteger('gates_passed')->default(0);
            $table->unsignedInteger('gates_failed')->default(0);
            $table->unsignedInteger('gates_warn')->default(0);
            $table->float('pass_score')->nullable();
            $table->unsignedInteger('total_phases')->default(0);
            $table->unsignedInteger('allowed_claim_count')->default(0);
            $table->unsignedInteger('forbidden_claim_count')->default(0);
            $table->unsignedInteger('gap_count')->default(0);
            $table->string('stability', 16)->nullable();       // STABLE | UNSTABLE
            $table->boolean('freeze_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('frozen_at')->useCurrent();
            $table->index('freeze_version');
        });

        Schema::create('stability_v3_freeze_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gate_id', 32);
            $table->string('gate_name', 200);
            $table->boolean('passed')->default(false);
            $table->string('status', 16)->default('pending');  // pass | warn | fail
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['freeze_run_id', 'gate_id']);
        });

        Schema::create('stability_v3_phase_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('enterprise_id', 16);    // E045 … E054
            $table->string('phase_name', 128);
            $table->string('status', 16)->default('reviewed');
            $table->jsonb('metrics')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['freeze_run_id', 'enterprise_id']);
        });

        Schema::create('stability_v3_readiness_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('claim_type', 16);       // allowed | forbidden
            $table->string('claim_text', 512);
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
        });

        Schema::create('stability_v3_gap_registry', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gap_id', 16);            // GAP-01 … GAP-07
            $table->string('severity', 16);          // critical | high | medium | low
            $table->string('description', 512);
            $table->string('resolution_path', 256)->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['freeze_run_id', 'gap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stability_v3_gap_registry');
        Schema::dropIfExists('stability_v3_readiness_claims');
        Schema::dropIfExists('stability_v3_phase_summaries');
        Schema::dropIfExists('stability_v3_freeze_gates');
        Schema::dropIfExists('stability_v3_freeze_runs');
    }
};
