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
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('alert_id', 64)->unique();
            $table->timestampTz('detected_at')->index();
            $table->string('alert_type', 64)->index();
            $table->string('severity', 16)->default('medium')->index();
            $table->string('ip', 45)->nullable()->index();
            $table->uuid('request_id')->nullable()->index();
            $table->unsignedBigInteger('event_id_ref')->nullable()->index();
            $table->float('score')->nullable();
            $table->string('model_label', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->json('raw_event')->nullable();
            $table->timestamps();

            $table->index(['alert_type', 'detected_at']);
            $table->index(['ip', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
