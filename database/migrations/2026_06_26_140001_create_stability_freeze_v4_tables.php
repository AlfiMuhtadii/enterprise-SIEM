<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-059: Stability Evidence Freeze v4
     *
     * Five append-only tables covering E055-E058 delta.
     * freeze_approved = false always. NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        Schema::create('stability_v4_freeze_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->unique()->index();
            $table->string('freeze_version', 32)->default('v4');
            $table->string('phase_range', 64)->default('E055-E058');
            $table->unsignedInteger('total_gates')->default(0);
            $table->unsignedInteger('gates_passed')->default(0);
            $table->unsignedInteger('gates_failed')->default(0);
            $table->unsignedInteger('gates_warn')->default(0);
            $table->float('pass_score')->nullable();
            $table->unsignedInteger('total_phases')->default(0);
            $table->unsignedInteger('allowed_claim_count')->default(0);
            $table->unsignedInteger('forbidden_claim_count')->default(0);
            $table->unsignedInteger('gap_count')->default(0);
            $table->string('stability', 16)->nullable();
            $table->boolean('freeze_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('frozen_at')->useCurrent();
            $table->index('freeze_version');
        });

        Schema::create('stability_v4_freeze_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gate_id', 32);
            $table->string('gate_name', 200);
            $table->boolean('passed')->default(false);
            $table->string('status', 16)->default('pending');
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['freeze_run_id', 'gate_id']);
        });

        Schema::create('stability_v4_phase_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('enterprise_id', 16);
            $table->string('phase_name', 128);
            $table->string('status', 16)->default('reviewed');
            $table->jsonb('metrics')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['freeze_run_id', 'enterprise_id']);
        });

        Schema::create('stability_v4_readiness_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('claim_type', 16);
            $table->string('claim_text', 512);
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
        });

        Schema::create('stability_v4_gap_registry', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gap_id', 16);
            $table->string('severity', 16);
            $table->string('description', 512);
            $table->string('resolution_path', 256)->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['freeze_run_id', 'gap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stability_v4_gap_registry');
        Schema::dropIfExists('stability_v4_readiness_claims');
        Schema::dropIfExists('stability_v4_phase_summaries');
        Schema::dropIfExists('stability_v4_freeze_gates');
        Schema::dropIfExists('stability_v4_freeze_runs');
    }
};
