<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-049: Stability Evidence Freeze v2
     *
     * Three append-only tables capturing the frozen stability snapshot
     * covering ENTERPRISE-045 through ENTERPRISE-048.
     * freeze_approved = false; final sign-off is a human gate.
     * NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        // Master freeze record — one per freeze run
        Schema::create('stability_freeze_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->unique()->index();
            $table->string('freeze_version', 32)->default('v2');
            $table->string('phase_range', 64)->default('E045-E048');
            $table->unsignedInteger('total_gates')->default(0);
            $table->unsignedInteger('gates_passed')->default(0);
            $table->unsignedInteger('gates_failed')->default(0);
            $table->unsignedInteger('gates_warn')->default(0);
            $table->float('pass_score')->nullable();    // gates_passed / total_gates
            $table->boolean('freeze_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('frozen_at')->useCurrent();
            $table->index('freeze_version');
        });

        // Per-gate evidence record for the freeze
        Schema::create('stability_freeze_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('gate_id', 32);
            $table->string('gate_name', 192);
            $table->boolean('passed')->default(false);
            $table->string('status', 16)->default('pending');  // pass | warn | fail
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['freeze_run_id', 'gate_id']);
        });

        // Phase-level summary snapshot within a freeze run
        Schema::create('stability_freeze_phase_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('freeze_run_id')->index();
            $table->string('enterprise_id', 16);    // E045, E046, E047, E048
            $table->string('phase_name', 128);
            $table->string('status', 16)->default('reviewed'); // reviewed | warn | not_run
            $table->jsonb('metrics')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['freeze_run_id', 'enterprise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stability_freeze_phase_summaries');
        Schema::dropIfExists('stability_freeze_gates');
        Schema::dropIfExists('stability_freeze_runs');
    }
};
