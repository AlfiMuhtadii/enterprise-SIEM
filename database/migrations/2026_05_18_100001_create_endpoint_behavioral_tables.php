<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Process snapshot metadata — one row per behavioral collection cycle per agent
        Schema::create('endpoint_process_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->timestamp('collected_at')->index();
            $table->unsignedInteger('process_count')->default(0);
            $table->unsignedInteger('shell_count')->default(0);
            $table->unsignedInteger('long_lived_count')->default(0);
            $table->unsignedInteger('suspicious_count')->default(0);
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamps();
        });

        // Per-process entries within a snapshot — full behavioral record
        Schema::create('endpoint_process_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('endpoint_process_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->unsignedBigInteger('pid')->nullable();
            $table->unsignedBigInteger('ppid')->nullable();
            $table->string('process_name', 255)->nullable()->index();
            $table->string('parent_process_name', 255)->nullable()->index();
            $table->text('executable_path')->nullable();
            $table->text('command_line')->nullable();
            $table->string('user', 255)->nullable()->index();
            $table->string('session_id', 40)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->boolean('is_shell')->default(false)->index();
            $table->boolean('is_long_lived')->default(false)->index();
            $table->boolean('is_suspicious')->default(false)->index();
            $table->string('trace_id', 120)->nullable();
            $table->timestamp('created_at');
        });

        // Persistence inventory — upserted per agent per item_key
        Schema::create('endpoint_persistence_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->string('item_type', 60)->index();   // systemd_service | cron_job | startup_script
            $table->string('item_key', 255)->index();   // unique identifier per agent
            $table->string('item_name', 255)->nullable();
            $table->text('item_path')->nullable();
            $table->boolean('is_new')->default(true)->index(); // new since last snapshot
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('trace_id', 120)->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'item_key'], 'uq_agent_persistence_item');
        });

        // Process-to-network correlation per snapshot (approximate, uid-based)
        Schema::create('endpoint_network_correlations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('endpoint_process_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->unsignedBigInteger('pid')->nullable();
            $table->string('process_name', 255)->nullable()->index();
            $table->string('remote_ip', 45)->nullable()->index();
            $table->unsignedInteger('remote_port')->nullable();
            $table->string('proto', 10)->nullable();
            $table->decimal('correlation_confidence', 3, 2)->nullable();
            $table->string('trace_id', 120)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_network_correlations');
        Schema::dropIfExists('endpoint_process_entries');
        Schema::dropIfExists('endpoint_persistence_items');
        Schema::dropIfExists('endpoint_process_snapshots');
    }
};
