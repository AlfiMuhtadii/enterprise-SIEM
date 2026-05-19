<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Script/interpreter execution events — append-only
        Schema::create('endpoint_script_executions', function (Blueprint $table) {
            $table->id();
            $table->string('execution_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->string('process_name', 255)->index();
            $table->string('parent_process_name', 255)->nullable()->index();
            $table->text('command_line')->nullable();
            // source: 'inline', 'file', 'encoded', 'piped'
            $table->string('script_source', 40)->default('inline');
            $table->boolean('is_encoded')->default(false)->index();
            // first 256 chars of decoded content — advisory preview only
            $table->string('decoded_preview', 256)->nullable();
            $table->string('script_hash', 64)->nullable()->index();
            $table->string('user', 255)->nullable()->index();
            // 'agent_proc', 'sysmon', 'powershell_operational', 'etw', 'security_event'
            $table->string('telemetry_source', 60)->default('agent_proc');
            $table->string('host_id', 120)->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->boolean('is_advisory')->default(true);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Privilege escalation indicators — append-only
        Schema::create('endpoint_privilege_escalations', function (Blueprint $table) {
            $table->id();
            $table->string('escalation_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->string('process_name', 255)->index();
            $table->unsignedBigInteger('pid')->nullable();
            $table->unsignedInteger('original_uid')->nullable();
            $table->unsignedInteger('escalated_uid')->nullable();
            $table->string('original_user', 255)->nullable();
            $table->string('escalated_user', 255)->nullable();
            // uid_transition, setuid_exec, sudo_invocation, su_invocation,
            // integrity_level_high, token_impersonation
            $table->string('escalation_type', 60)->index();
            $table->text('command_line')->nullable();
            $table->string('telemetry_source', 60)->default('agent_proc');
            $table->string('host_id', 120)->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->boolean('is_advisory')->default(true);
            $table->float('confidence')->default(0.0);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Container lifecycle and namespace visibility — append-only
        Schema::create('endpoint_container_activities', function (Blueprint $table) {
            $table->id();
            $table->string('activity_id', 40)->unique()->index();
            $table->unsignedBigInteger('agent_id');
            $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
            $table->string('container_id', 120)->nullable()->index();
            $table->string('container_name', 255)->nullable();
            $table->string('image_name', 512)->nullable();
            // container_start, container_stop, namespace_detected, breakout_indicator
            $table->string('activity_type', 60)->index();
            $table->unsignedBigInteger('pid')->nullable();
            $table->string('process_name', 255)->nullable()->index();
            // docker, containerd, lxc, kubernetes, unknown
            $table->string('namespace_type', 60)->nullable();
            $table->string('host_id', 120)->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->boolean('is_advisory')->default(true);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_container_activities');
        Schema::dropIfExists('endpoint_privilege_escalations');
        Schema::dropIfExists('endpoint_script_executions');
    }
};
