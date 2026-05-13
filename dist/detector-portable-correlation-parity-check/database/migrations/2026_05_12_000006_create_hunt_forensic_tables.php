<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('endpoint_investigation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 80)->unique();
            $table->string('host_id', 128)->index();
            $table->string('created_by', 120)->index();
            $table->timestampTz('started_at')->index();
            $table->jsonb('filters')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();
        });

        Schema::create('forensic_collection_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id', 80)->unique();
            $table->string('agent_id', 96)->nullable()->index();
            $table->string('host_id', 128)->nullable()->index();
            $table->string('collection_type', 64)->index();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->string('requested_by', 120)->index();
            $table->string('approved_by', 120)->nullable()->index();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('artifact_path', 500)->nullable();
            $table->string('artifact_sha256', 96)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forensic_collection_jobs');
        Schema::dropIfExists('endpoint_investigation_sessions');
        Schema::dropIfExists('threat_hunt_runs');
        Schema::dropIfExists('threat_hunts');
    }
};
