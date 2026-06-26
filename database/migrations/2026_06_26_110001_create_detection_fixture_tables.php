<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-056: Detection Replay Fixture Batch 1
     *
     * Two append-only tables tracking fixture batch runs and per-rule results.
     * NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        Schema::create('detection_fixture_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->unique()->index();
            $table->string('batch_name', 128);
            $table->string('tier', 64);                          // tier_1_immediate | tier_2_next_batch
            $table->unsignedInteger('rules_total')->default(0);
            $table->unsignedInteger('fixtures_valid')->default(0);
            $table->unsignedInteger('fixtures_invalid')->default(0);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('promotion_blocked')->default(true);  // always true
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('run_at')->useCurrent();
        });

        Schema::create('detection_fixture_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->string('rule_id', 128)->index();
            $table->string('domain', 64);
            $table->string('fixture_path', 512)->nullable();
            $table->boolean('fixture_valid')->default(false);
            $table->string('event_type_matched', 128)->nullable();
            $table->text('validation_notes')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['batch_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_fixture_results');
        Schema::dropIfExists('detection_fixture_batches');
    }
};
