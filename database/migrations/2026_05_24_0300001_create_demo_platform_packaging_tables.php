<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — tracks each demo scenario run
        Schema::create('demo_scenario_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('scenario_name');
            $table->string('scenario_state')->default('completed');
            $table->boolean('is_deterministic')->default(true);
            $table->boolean('is_lab_safe')->default(true);
            $table->boolean('is_destructive')->default(false);
            $table->boolean('demo_mode')->default(true);
            $table->boolean('has_real_malware')->default(false);
            $table->boolean('has_autonomous_remediation')->default(false);
            $table->integer('event_count')->default(0);
            $table->float('run_duration_ms')->default(0);
            $table->json('run_output')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only — point-in-time platform readiness snapshots
        Schema::create('demo_readiness_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->integer('php_tests_passed')->default(0);
            $table->integer('python_tests_passed')->default(0);
            $table->boolean('rule_registry_pass')->default(false);
            $table->boolean('contracts_pass')->default(false);
            $table->boolean('resilience_pass')->default(false);
            $table->boolean('fleet_sim_pass')->default(false);
            $table->integer('domain_count')->default(0);
            $table->integer('total_rules')->default(0);
            $table->boolean('overall_ready')->default(false);
            $table->string('snapshot_hash');
            $table->timestamp('created_at')->useCurrent();
        });

        // Append-only — exported showcase artifacts (capability matrix, architecture exports, reports)
        Schema::create('platform_showcase_exports', function (Blueprint $table) {
            $table->id();
            $table->string('export_id')->unique();
            $table->string('export_type');
            $table->string('export_format')->default('json');
            $table->string('artifact_hash');
            $table->boolean('is_deterministic')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('is_fabricated')->default(false);
            $table->json('export_content');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_showcase_exports');
        Schema::dropIfExists('demo_readiness_snapshots');
        Schema::dropIfExists('demo_scenario_runs');
    }
};
