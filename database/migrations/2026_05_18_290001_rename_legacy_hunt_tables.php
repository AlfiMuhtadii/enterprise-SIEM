<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migrates the legacy SOC hunt tables to new names so the new
 * ThreatHuntingService tables (2026_05_18_300001) can use 'threat_hunts'.
 *
 * Creates:
 *   soc_hunt_sessions     — replaces old threat_hunts
 *   soc_hunt_run_sessions — replaces old threat_hunt_runs
 *
 * Then drops the old tables (and their named indexes) cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create soc_hunt_sessions to replace old threat_hunts
        if (!Schema::hasTable('soc_hunt_sessions')) {
            Schema::create('soc_hunt_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('hunt_id', 80)->unique();
                $table->string('name', 160);
                $table->string('template_key', 80)->nullable()->index();
                $table->string('created_by', 120)->index();
                $table->jsonb('filters');
                $table->jsonb('query')->nullable();
                $table->boolean('saved')->default(false)->index();
                $table->timestampTz('last_run_at')->nullable()->index();
                $table->unsignedInteger('last_result_count')->default(0);
                $table->timestampsTz();
            });
        }

        // Create soc_hunt_run_sessions to replace old threat_hunt_runs
        if (!Schema::hasTable('soc_hunt_run_sessions')) {
            Schema::create('soc_hunt_run_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('run_id', 80)->unique();
                $table->string('hunt_id', 80)->nullable()->index();
                $table->string('executed_by', 120)->index();
                $table->timestampTz('started_at')->index();
                $table->timestampTz('completed_at')->nullable();
                $table->jsonb('filters');
                $table->unsignedInteger('result_count')->default(0);
                $table->jsonb('results')->nullable();
                $table->timestampsTz();
            });
        }

        // Drop the old tables — this also cleans up all their named indexes and constraints
        Schema::dropIfExists('threat_hunt_runs');
        Schema::dropIfExists('threat_hunts');
    }

    public function down(): void
    {
        if (!Schema::hasTable('threat_hunts')) {
            Schema::create('threat_hunts', function (Blueprint $table) {
                $table->id();
                $table->string('hunt_id', 80)->unique();
                $table->string('name', 160);
                $table->string('template_key', 80)->nullable()->index();
                $table->string('created_by', 120)->index();
                $table->jsonb('filters');
                $table->jsonb('query')->nullable();
                $table->boolean('saved')->default(false)->index();
                $table->timestampTz('last_run_at')->nullable()->index();
                $table->unsignedInteger('last_result_count')->default(0);
                $table->timestampsTz();
            });
        }
        if (!Schema::hasTable('threat_hunt_runs')) {
            Schema::create('threat_hunt_runs', function (Blueprint $table) {
                $table->id();
                $table->string('run_id', 80)->unique();
                $table->string('hunt_id', 80)->nullable()->index();
                $table->string('executed_by', 120)->index();
                $table->timestampTz('started_at')->index();
                $table->timestampTz('completed_at')->nullable();
                $table->jsonb('filters');
                $table->unsignedInteger('result_count')->default(0);
                $table->jsonb('results')->nullable();
                $table->timestampsTz();
            });
        }
        Schema::dropIfExists('soc_hunt_run_sessions');
        Schema::dropIfExists('soc_hunt_sessions');
    }
};
