<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-058: Confidence Source Refresh
     *
     * Two append-only tables tracking confidence_source refresh runs.
     * rule_fixture_backlogs (mutable) is updated in-place.
     * NEVER UPDATE or DELETE rows in these audit tables.
     */
    public function up(): void
    {
        Schema::create('confidence_source_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('refresh_run_id')->index();
            $table->string('rule_id', 128)->index();
            $table->string('domain', 64);
            $table->string('old_confidence_source', 32)->nullable();  // manual | fixture_tested | empirical
            $table->string('new_confidence_source', 32);
            $table->boolean('changed')->default(false);
            $table->boolean('has_fixture')->default(false);
            $table->boolean('has_evidence')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['refresh_run_id', 'rule_id']);
        });

        Schema::create('confidence_source_distribution_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('refresh_run_id')->unique()->index();
            $table->unsignedInteger('total_rules')->default(0);
            $table->unsignedInteger('empirical_count')->default(0);
            $table->unsignedInteger('fixture_tested_count')->default(0);
            $table->unsignedInteger('manual_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->float('empirical_rate')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('run_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidence_source_distribution_runs');
        Schema::dropIfExists('confidence_source_audit_events');
    }
};
