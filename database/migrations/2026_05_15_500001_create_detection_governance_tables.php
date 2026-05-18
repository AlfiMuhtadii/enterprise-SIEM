<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detection_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 120)->unique();
            $table->string('stage', 40)->default('draft');
            $table->string('version', 20)->default('v1');
            $table->string('owner', 120)->nullable();
            $table->boolean('replay_validated')->default(false);
            $table->timestamp('replay_validated_at')->nullable();
            $table->boolean('suppression_active')->default(false);
            $table->text('suppression_reason')->nullable();
            $table->timestamp('suppression_expires_at')->nullable();
            $table->decimal('false_positive_rate', 5, 4)->nullable();
            $table->string('promotion_approved_by', 120)->nullable();
            $table->timestamp('promotion_approved_at')->nullable();
            $table->jsonb('gate_results')->nullable();
            $table->jsonb('checklist_state')->nullable();
            $table->timestamps();
        });

        Schema::create('detection_rule_notes', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 120)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('note_type', 40)->default('general');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_rule_notes');
        Schema::dropIfExists('detection_rules');
    }
};
