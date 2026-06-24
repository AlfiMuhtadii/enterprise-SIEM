<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easm_posture_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('run_id');
            $table->unsignedSmallInteger('posture_score')->default(100);
            $table->string('risk_tier', 20)->default('low');
            $table->unsignedInteger('finding_count_total')->default(0);
            $table->unsignedInteger('finding_count_high')->default(0);
            $table->unsignedInteger('finding_count_medium')->default(0);
            $table->unsignedInteger('finding_count_low')->default(0);
            $table->unsignedInteger('finding_count_info')->default(0);
            $table->unsignedInteger('new_findings')->default(0);
            $table->unsignedInteger('resolved_findings')->default(0);
            $table->unsignedInteger('regressed_findings')->default(0);
            $table->unsignedInteger('unchanged_findings')->default(0);
            $table->string('overall_status', 20)->default('PASS');
            $table->timestamp('snapshot_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('asset_id');
            $table->index(['asset_id', 'snapshot_at']);
            $table->foreign('asset_id')->references('id')->on('website_assets');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easm_posture_snapshots');
    }
};
