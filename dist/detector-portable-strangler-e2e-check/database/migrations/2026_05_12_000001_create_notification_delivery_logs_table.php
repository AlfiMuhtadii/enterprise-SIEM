<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('attempted_at')->index();
            $table->string('target_type', 40)->index();
            $table->string('target_url_hash', 64)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('incident_id', 80)->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();
            $table->index(['incident_id', 'event_type']);
            $table->index(['status', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_logs');
    }
};
