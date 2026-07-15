<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TENANT-HUNT-CORRELATION-ISOLATION: soc_hunt_sessions (saved
        // "quick hunts") and soc_hunt_run_sessions (each run's results) had
        // no tenant_id at all -- SocHuntController::index() rendered both
        // globally, leaking hunt histories across tenants. Both are
        // insert-only (no UPDATE statement anywhere in the codebase),
        // matching the append-only tenant_id convention.
        Schema::table('soc_hunt_sessions', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->after('id');
            $table->index(['tenant_id', 'updated_at'], 'shs_tenant_updated_idx');
        });

        Schema::table('soc_hunt_run_sessions', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->after('id');
            $table->index(['tenant_id', 'started_at'], 'shrs_tenant_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('soc_hunt_sessions', function (Blueprint $table) {
            $table->dropIndex('shs_tenant_updated_idx');
            $table->dropColumn('tenant_id');
        });

        Schema::table('soc_hunt_run_sessions', function (Blueprint $table) {
            $table->dropIndex('shrs_tenant_started_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
