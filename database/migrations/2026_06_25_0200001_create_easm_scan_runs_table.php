<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easm_scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('scan_policy', 30)->default('passive');
            $table->string('target_hostname');
            $table->string('target_url');
            $table->string('status', 20)->default('started');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->json('checks_run')->nullable();
            $table->text('error_summary')->nullable();
            $table->boolean('is_dry_run')->default(false);
            $table->string('actor')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('asset_id');
            $table->foreign('asset_id')->references('id')->on('website_assets');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easm_scan_runs');
    }
};
