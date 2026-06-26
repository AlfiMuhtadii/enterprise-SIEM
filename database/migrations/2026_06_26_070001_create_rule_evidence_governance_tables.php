<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-050: Empirical Rule Evidence & Replay Fixture Plan
     *
     * rule_fixture_backlogs  — mutable tracking, one row per rule (upsert-safe)
     * rule_evidence_batch_plans — append-only batch plan records
     *
     * NEVER DELETE rows from rule_evidence_batch_plans.
     */
    public function up(): void
    {
        Schema::create('rule_fixture_backlogs', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 128)->unique()->index();
            $table->string('domain', 64);
            $table->string('title', 256);
            $table->string('status', 32);                              // staged_active | shadow
            $table->float('confidence');
            $table->string('confidence_source', 32)->default('manual'); // manual | fixture_tested | empirical
            $table->boolean('has_replay_fixture')->default(false);
            $table->boolean('has_validation_evidence')->default(false);
            $table->string('fixture_path', 512)->nullable();
            $table->string('priority_tier', 64)->default('tier_3_deferred'); // tier_1_immediate | tier_2_next_batch | tier_3_deferred
            $table->string('batch_phase', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('last_inventoried_at')->nullable();
        });

        Schema::create('rule_evidence_batch_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->unique()->index();
            $table->string('domain', 64);
            $table->string('priority_tier', 64);
            $table->unsignedInteger('rules_count')->default(0);
            $table->unsignedInteger('missing_fixture_count')->default(0);
            $table->unsignedInteger('missing_evidence_count')->default(0);
            $table->integer('estimated_effort_days')->nullable();
            $table->jsonb('rule_ids')->nullable();
            $table->boolean('plan_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('generated_at')->useCurrent();
            $table->index(['domain', 'priority_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_evidence_batch_plans');
        Schema::dropIfExists('rule_fixture_backlogs');
    }
};
