<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detection_quality_history', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('measured_at')->index();
            $table->string('metric_type', 64)->index();
            $table->string('source', 120)->default('local')->index();
            $table->float('precision')->nullable();
            $table->float('recall')->nullable();
            $table->float('false_positive_rate')->nullable();
            $table->float('false_negative_rate')->nullable();
            $table->float('avg_detection_latency_sec')->nullable();
            $table->unsignedInteger('alert_volume')->default(0);
            $table->unsignedInteger('incident_volume')->default(0);
            $table->unsignedInteger('rule_tests_passed')->default(0);
            $table->unsignedInteger('rule_tests_failed')->default(0);
            $table->jsonb('details')->nullable();
            $table->timestampsTz();
            $table->index(['metric_type', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_quality_history');
    }
};
