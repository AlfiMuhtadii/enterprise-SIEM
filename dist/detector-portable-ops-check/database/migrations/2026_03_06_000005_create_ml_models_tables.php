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
        Schema::create('ml_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('model_key', 128)->unique();
            $table->string('model_type', 64)->default('logreg');
            $table->string('artifact_path', 512);
            $table->string('artifact_sha256', 64)->index();
            $table->timestampTz('training_data_start')->nullable()->index();
            $table->timestampTz('training_data_end')->nullable()->index();
            $table->string('feature_hash', 64)->index();
            $table->json('metrics')->nullable();
            $table->json('train_config')->nullable();
            $table->json('drift_profile')->nullable();
            $table->string('git_commit', 64)->default('unknown');
            $table->timestamps();
        });

        Schema::create('ml_model_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('model_id')->index();
            $table->string('environment', 32)->default('local')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('lock_enabled')->default(true);
            $table->string('expected_artifact_sha256', 64)->nullable();
            $table->timestampTz('deployed_at')->index();
            $table->string('deployed_by', 128)->default('system');
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index(['environment', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_model_deployments');
        Schema::dropIfExists('ml_models');
    }
};
