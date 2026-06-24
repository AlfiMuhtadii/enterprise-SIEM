<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easm_asset_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id')->unique();
            $table->unsignedSmallInteger('risk_score')->default(100);
            $table->string('risk_tier', 20)->default('low');
            $table->string('trend_direction', 20)->default('stable');
            $table->unsignedSmallInteger('previous_score')->nullable();
            $table->unsignedInteger('total_scans')->default(0);
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'asset_id']);
            $table->index('risk_score');
            $table->foreign('asset_id')->references('id')->on('website_assets');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easm_asset_risk_scores');
    }
};
