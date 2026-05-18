<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to_user_id')->index();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable()->index();
            $table->string('role', 30)->default('primary');
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['investigation_id', 'is_active'], 'inv_asgn_inv_active_idx');
            $table->index(['assigned_to_user_id', 'is_active'], 'inv_asgn_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_assignments');
    }
};
