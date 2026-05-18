<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_risk_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('entity_type', 40)->index();
            $table->string('entity_key', 255)->index();
            $table->decimal('risk_score', 5, 2)->index();
            $table->string('risk_level', 20)->index();
            $table->jsonb('risk_factors');
            $table->jsonb('alert_ids')->nullable();
            $table->jsonb('incident_ids')->nullable();
            $table->jsonb('trace_ids')->nullable();
            $table->timestamp('calculated_at')->index();
            $table->timestamps();

            $table->index(['entity_id', 'calculated_at'], 'risk_snap_entity_time_idx');
            $table->index(['entity_type', 'risk_level', 'calculated_at'], 'risk_snap_type_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_risk_snapshots');
    }
};
