<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->string('investigation_id', 40)->unique();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('state', 30)->default('new')->index();
            $table->string('severity', 16)->default('medium')->index();
            $table->unsignedTinyInteger('priority')->default(3)->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->foreign('created_by')->references('id')->on('users');
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('escalated_to')->nullable()->index();
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('alert_ids')->nullable();
            $table->jsonb('incident_ids')->nullable();
            $table->jsonb('entity_ids')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->timestamp('triage_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('sla_breach_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['state', 'severity'], 'inv_state_sev_idx');
            $table->index(['assigned_to', 'state'], 'inv_assigned_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigations');
    }
};
