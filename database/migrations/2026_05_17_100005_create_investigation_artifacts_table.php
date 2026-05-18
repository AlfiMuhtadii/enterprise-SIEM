<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->string('artifact_type', 60)->index();
            $table->string('title', 255);
            $table->string('reference', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('added_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->index('investigation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_artifacts');
    }
};
