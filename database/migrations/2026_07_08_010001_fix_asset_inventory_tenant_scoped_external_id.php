<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASSET-TENANT-OVERWRITE fix: external_id was globally unique, so a CSV
 * import for tenant B supplying tenant A's external_id would match A's row
 * and flip its tenant_id to B, overwriting A's asset. Scope uniqueness to
 * (tenant_id, external_id) — the same external_id may now exist once per
 * tenant, but never lets one tenant's import touch another tenant's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_inventory', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_inventory', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->unique('external_id');
        });
    }
};
