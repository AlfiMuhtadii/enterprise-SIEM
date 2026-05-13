<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('ts')->index();
            $table->string('event_id', 80)->unique();
            $table->string('telemetry_type', 32)->index();
            $table->string('event_type', 80)->index();
            $table->string('host_id', 128)->nullable()->index();
            $table->string('src_ip', 64)->nullable()->index();
            $table->string('dst_ip', 64)->nullable()->index();
            $table->integer('dst_port')->nullable()->index();
            $table->string('protocol', 24)->nullable();
            $table->string('process_name', 160)->nullable();
            $table->string('user_name_hash', 64)->nullable();
            $table->jsonb('payload');
            $table->timestampsTz();

            $table->index(['telemetry_type', 'event_type', 'ts']);
            $table->index(['host_id', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};
