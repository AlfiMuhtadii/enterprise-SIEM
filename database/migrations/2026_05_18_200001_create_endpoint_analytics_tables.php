<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Behavioral analytics findings — append-only
        Schema::create('endpoint_behavioral_findings', function (Blueprint $table) {
            $table->id();
            $table->string('finding_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->foreign('snapshot_id')->references('id')->on('endpoint_process_snapshots')->nullOnDelete();
            $table->string('finding_type', 60)->index();
            $table->string('severity', 20)->index();
            $table->decimal('confidence', 3, 2);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('detected_at')->index();
            $table->timestamp('created_at');
        });

        // Detected execution chains (parent-child process paths)
        Schema::create('endpoint_execution_chains', function (Blueprint $table) {
            $table->id();
            $table->string('chain_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->foreign('snapshot_id')->references('id')->on('endpoint_process_snapshots')->nullOnDelete();
            $table->jsonb('chain_steps');           // [{pid, process_name, parent_name, is_shell, cmd}]
            $table->unsignedSmallInteger('chain_length')->default(0);
            $table->boolean('involves_shell')->default(false)->index();
            $table->boolean('involves_outbound')->default(false)->index();
            $table->boolean('involves_persistence')->default(false)->index();
            $table->decimal('chain_score', 3, 2)->default(0.0)->index();
            $table->string('trace_id', 120)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('created_at');
        });

        // Beacon-like outbound pattern analysis
        Schema::create('endpoint_beacon_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->foreign('snapshot_id')->references('id')->on('endpoint_process_snapshots')->nullOnDelete();
            $table->string('process_name', 255)->nullable()->index();
            $table->string('remote_ip', 45)->nullable()->index();
            $table->unsignedInteger('remote_port')->nullable();
            $table->unsignedInteger('connection_count')->default(0);
            $table->decimal('avg_interval_seconds', 10, 2)->nullable();
            $table->decimal('interval_variance', 10, 4)->nullable();
            $table->decimal('destination_reuse_score', 3, 2)->default(0.0);
            $table->string('trace_id', 120)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_beacon_patterns');
        Schema::dropIfExists('endpoint_execution_chains');
        Schema::dropIfExists('endpoint_behavioral_findings');
    }
};
