<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_plan_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_plan_id')->constrained('response_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id')->nullable()->index();
            $table->string('note_type', 40)->default('general')->index();
            $table->text('body');
            $table->timestamps();

            $table->index(['response_plan_id', 'created_at'], 'rpn_plan_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_plan_notes');
    }
};
