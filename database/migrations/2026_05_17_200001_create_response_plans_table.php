<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_id', 40)->unique();
            $table->string('title', 255);
            $table->text('description')->nullable();
            // states: draft|pending_approval|approved|rejected|completed_documented|cancelled
            // completed_documented = analyst documented manual actions externally; zero system execution
            $table->string('state', 30)->default('draft')->index();
            $table->string('risk_level', 20)->nullable()->index();
            $table->unsignedBigInteger('investigation_id')->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('entity_ids')->nullable();
            $table->jsonb('related_alert_ids')->nullable();
            $table->jsonb('related_incident_ids')->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->foreign('created_by')->references('id')->on('users');
            $table->unsignedBigInteger('assigned_approver')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['state', 'risk_level'], 'rp_state_risk_idx');
            $table->index(['created_by', 'state'], 'rp_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_plans');
    }
};
