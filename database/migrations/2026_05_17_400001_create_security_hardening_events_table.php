<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_hardening_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 60)->index();
            $table->string('service_name', 80)->nullable();
            $table->string('actor', 255)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('details')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_hardening_events');
    }
};
