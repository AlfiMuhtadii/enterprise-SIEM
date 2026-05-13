<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_agents', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 96)->unique();
            $table->string('host_fingerprint', 128)->index();
            $table->string('host_id', 128)->index();
            $table->string('agent_version', 40)->default('unknown')->index();
            $table->string('os_family', 40)->nullable()->index();
            $table->string('status', 24)->default('online')->index();
            $table->text('agent_secret')->nullable();
            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->unsignedBigInteger('event_count_total')->default(0);
            $table->unsignedBigInteger('error_count_total')->default(0);
            $table->unsignedInteger('last_batch_event_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('agent_delivery_failures', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 96)->nullable()->index();
            $table->string('failure_type', 64)->index();
            $table->text('message')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('failed_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_delivery_failures');
        Schema::dropIfExists('endpoint_agents');
    }
};
