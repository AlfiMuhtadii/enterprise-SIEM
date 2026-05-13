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
        Schema::create('security_responses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('response_id', 64)->unique();
            $table->unsignedBigInteger('alert_ref')->nullable()->index();
            $table->timestampTz('created_at_event')->index();
            $table->string('mode', 16)->default('recommend')->index(); // recommend|auto
            $table->string('action_type', 64)->index();
            $table->string('target_type', 32)->index(); // ip|user
            $table->string('target_id', 128)->nullable()->index();
            $table->string('status', 32)->default('recommended')->index(); // recommended|executed|suppressed|failed
            $table->string('severity', 16)->default('medium')->index();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['action_type', 'created_at_event']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_responses');
    }
};
