<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40)->index();
            $table->string('entity_key', 255);
            $table->string('display_name', 255)->nullable();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('observation_count')->default(1);
            $table->decimal('risk_score', 5, 2)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_key'], 'entities_type_key_unique');
        });

        Schema::create('entity_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('target_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('relationship_type', 80)->index();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('observation_count')->default(1);
            $table->decimal('confidence', 4, 3)->default(1.0);
            $table->string('relationship_origin', 60)->nullable();
            $table->string('source_table', 80)->nullable();
            $table->string('source_event_id', 255)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['source_entity_id', 'target_entity_id', 'relationship_type'],
                'entity_rel_pair_idx'
            );
        });

        Schema::create('entity_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('observation_type', 60)->index();
            $table->string('source_table', 80)->nullable();
            $table->string('source_event_id', 255)->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('observed_at')->index();
            $table->jsonb('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_observations');
        Schema::dropIfExists('entity_relationships');
        Schema::dropIfExists('entities');
    }
};
