<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->string('event_type', 60)->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['investigation_id', 'occurred_at'], 'inv_evt_inv_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_events');
    }
};
