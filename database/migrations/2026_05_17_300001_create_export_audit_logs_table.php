<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('export_id', 40)->unique();
            $table->string('export_type', 60)->index();   // investigation|response_plan|entity_risk|trace|incident_bundle
            $table->string('export_format', 20)->index(); // json|markdown|html
            $table->unsignedBigInteger('exported_by')->index();
            $table->foreign('exported_by')->references('id')->on('users');
            $table->timestamp('exported_at')->index();
            $table->text('export_reason')->nullable();
            $table->string('source_id', 255)->index();
            $table->string('source_type', 60)->index();
            $table->unsignedInteger('export_size_bytes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['exported_by', 'exported_at'], 'eal_user_time_idx');
            $table->index(['export_type', 'exported_at'], 'eal_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_audit_logs');
    }
};
