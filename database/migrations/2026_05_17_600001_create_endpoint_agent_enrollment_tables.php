<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add Phase 1 hardening columns to the existing endpoint_agents table
        Schema::table('endpoint_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('endpoint_agents', 'enrollment_token_hash')) {
                $table->string('enrollment_token_hash', 128)->nullable();
            }
            if (!Schema::hasColumn('endpoint_agents', 'public_key_fingerprint')) {
                $table->string('public_key_fingerprint', 128)->nullable();
            }
            if (!Schema::hasColumn('endpoint_agents', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
            if (!Schema::hasColumn('endpoint_agents', 'hostname')) {
                $table->string('hostname', 255)->nullable();
            }
            if (!Schema::hasColumn('endpoint_agents', 'platform')) {
                $table->string('platform', 30)->nullable();
            }
            if (!Schema::hasColumn('endpoint_agents', 'health_state')) {
                $table->string('health_state', 20)->default('offline');
            }
        });

        // Agent config policy history
        if (!Schema::hasTable('endpoint_agent_configs')) {
            Schema::create('endpoint_agent_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
                $table->string('policy_version', 20)->default('1.0.0');
                $table->jsonb('config');
                $table->timestamp('applied_at');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Heartbeat log — append-only
        if (!Schema::hasTable('endpoint_agent_heartbeats')) {
            Schema::create('endpoint_agent_heartbeats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
                $table->string('signature', 128)->nullable();
                $table->boolean('signature_valid')->default(false);
                $table->string('health_state', 20)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->jsonb('metrics')->nullable();
                $table->timestamp('heartbeat_at')->index();
                $table->timestamp('created_at');
            });
        }

        // Telemetry quality metrics (upsert by agent_id)
        if (!Schema::hasTable('endpoint_agent_metrics')) {
            Schema::create('endpoint_agent_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->foreign('agent_id')->references('id')->on('endpoint_agents')->cascadeOnDelete();
                $table->unique('agent_id');
                $table->decimal('events_per_sec', 10, 2)->default(0);
                $table->integer('dropped_events')->default(0);
                $table->integer('retry_count')->default(0);
                $table->integer('buffer_depth')->default(0);
                $table->integer('total_sent')->default(0);
                $table->integer('collection_cycles')->default(0);
                $table->timestamp('last_successful_send')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_agent_metrics');
        Schema::dropIfExists('endpoint_agent_heartbeats');
        Schema::dropIfExists('endpoint_agent_configs');

        Schema::table('endpoint_agents', function (Blueprint $table) {
            $cols = ['enrollment_token_hash', 'public_key_fingerprint', 'ip_address', 'hostname', 'platform', 'health_state'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('endpoint_agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
