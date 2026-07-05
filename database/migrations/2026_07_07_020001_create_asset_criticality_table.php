<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASSET-INVENTORY: advisory business-criticality tier per asset. Used only to
 * rank the analyst queue — never to trigger or gate any response action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_criticality', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id')->unique();
            $table->string('criticality_tier', 20)->default('medium');
            $table->text('justification')->nullable();
            $table->string('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
            $table->foreign('asset_id')->references('id')->on('asset_inventory');
            $table->index('tenant_id');
            $table->index('criticality_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_criticality');
    }
};
