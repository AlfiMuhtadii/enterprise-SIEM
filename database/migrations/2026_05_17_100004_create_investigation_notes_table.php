<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id')->nullable()->index();
            $table->string('note_type', 40)->default('general')->index();
            $table->text('body');
            $table->string('trace_id', 120)->nullable()->index();
            $table->boolean('is_pinned')->default(false)->index();
            $table->timestamps();

            $table->index(['investigation_id', 'created_at'], 'inv_note_inv_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_notes');
    }
};
