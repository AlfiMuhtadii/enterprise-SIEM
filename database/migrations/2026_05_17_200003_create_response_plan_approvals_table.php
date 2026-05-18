<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_plan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_plan_id')->constrained('response_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('approver_user_id')->index();
            $table->string('decision', 20)->index();  // approved|rejected|submitted|completed|cancelled
            $table->timestamp('decision_at')->index();
            $table->text('notes')->nullable();
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->timestamps();

            $table->index(['response_plan_id', 'decision_at'], 'rp_approval_plan_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_plan_approvals');
    }
};
