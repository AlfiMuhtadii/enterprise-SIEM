<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASSET-INVENTORY: asset/CMDB catalog for advisory alert-enrichment context.
 * Read/rank-only — never referenced by any response/execution path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_inventory', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('external_id', 64)->unique();
            $table->string('hostname');
            $table->string('ip_address')->nullable();
            $table->string('owner')->nullable();
            $table->string('environment', 20)->default('production');
            $table->string('asset_type', 30)->default('unknown');
            $table->string('source', 30)->default('manual');
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'hostname']);
            $table->index(['tenant_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_inventory');
    }
};
