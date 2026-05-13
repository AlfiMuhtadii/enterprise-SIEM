<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_audit_trails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('occurred_at')->index();
            $table->string('actor', 128)->default('system');
            $table->string('action', 128)->index();
            $table->string('target_type', 64)->index();
            $table->string('target_id', 128)->nullable()->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_trails');
    }
};
