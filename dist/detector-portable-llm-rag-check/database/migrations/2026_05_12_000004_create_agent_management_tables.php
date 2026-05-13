<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id', 80)->unique();
            $table->string('name', 160);
            $table->unsignedInteger('version')->default(1)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('collection_interval_seconds')->default(60);
            $table->jsonb('enabled_collectors');
            $table->unsignedInteger('max_batch_size')->default(500);
            $table->jsonb('retry_policy');
            $table->jsonb('telemetry_categories');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::table('endpoint_agents', function (Blueprint $table) {
            $table->string('policy_id', 80)->nullable()->after('agent_version')->index();
            $table->unsignedInteger('policy_version_seen')->default(0)->after('policy_id')->index();
            $table->string('config_hash', 96)->nullable()->after('policy_version_seen')->index();
            $table->unsignedInteger('retry_queue_depth')->default(0)->after('last_batch_event_count');
            $table->string('upgrade_status', 40)->default('current')->after('retry_queue_depth')->index();
            $table->string('target_version', 40)->nullable()->after('upgrade_status');
        });

        Schema::create('agent_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40)->unique();
            $table->boolean('is_latest')->default(false)->index();
            $table->string('minimum_supported_version', 40)->nullable();
            $table->jsonb('release_notes')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->string('command_id', 80)->unique();
            $table->string('agent_id', 96)->index();
            $table->string('command_type', 64)->index();
            $table->string('status', 32)->default('queued')->index();
            $table->jsonb('payload')->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('queued_at')->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->string('created_by', 120)->default('system')->index();
            $table->timestampsTz();
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
        Schema::dropIfExists('agent_releases');
        Schema::table('endpoint_agents', function (Blueprint $table) {
            foreach (['policy_id', 'policy_version_seen', 'config_hash', 'retry_queue_depth', 'upgrade_status', 'target_version'] as $column) {
                if (Schema::hasColumn('endpoint_agents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('agent_policies');
    }
};
