<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IMPORTANT: This table stores planning recommendations ONLY.
        // There are intentionally NO execute_at, executed_at, execute_command,
        // or external_api_endpoint columns. No system execution is permitted.
        // completed_documented = analyst documented manual external action.
        Schema::create('response_plan_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_plan_id')->constrained('response_plans')->cascadeOnDelete();
            $table->string('action_type', 60)->index();
            $table->string('target_entity_type', 40)->nullable();
            $table->string('target_entity_key', 255)->nullable();
            $table->unsignedBigInteger('target_entity_id')->nullable()->index();
            $table->text('reasoning')->nullable();
            $table->jsonb('evidence_refs')->nullable();
            $table->boolean('advisory_only')->default(false)->index();
            // statuses: pending|approved|completed_documented|rejected|waived
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['response_plan_id', 'action_type'], 'rpa_plan_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_plan_actions');
    }
};
