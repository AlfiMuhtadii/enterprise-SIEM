<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-053: Real Endpoint Telemetry Enrollment
     *
     * real_endpoint_enrollments — append-only; one row per enrolled endpoint.
     * NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        Schema::create('real_endpoint_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('enrollment_id')->unique()->index();
            $table->string('enrollment_token', 128)->unique()->index();
            $table->string('hostname', 256);
            $table->string('os_platform', 64)->default('unknown');   // windows | linux | darwin
            $table->string('os_version', 128)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->string('tenant_id')->nullable()->index();
            $table->boolean('heartbeat_received')->default(false);
            $table->boolean('snapshot_received')->default(false);
            $table->unsignedInteger('process_count')->default(0);
            $table->unsignedInteger('persistence_count')->default(0);
            $table->jsonb('collector_summary')->nullable();           // {collectors: [...], counts: {...}}
            $table->boolean('is_real')->default(true);               // true = real OS data, false = simulated
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('enrolled_at')->useCurrent();
            $table->timestampTz('last_heartbeat_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_endpoint_enrollments');
    }
};
