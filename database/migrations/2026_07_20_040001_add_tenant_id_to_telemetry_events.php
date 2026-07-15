<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TENANT-POSTGRES-FALLBACK-TELEMETRY: ClickHouse's telemetry_events
        // has carried tenant_id since it was first created (ARCH-DB-SPLIT
        // write path); Postgres's own copy never got one. Nullable -- null
        // means legacy/unscoped, matching every other tenant_id column in
        // this codebase (TenantBoundaryService's documented convention).
        Schema::table('telemetry_events', function (Blueprint $table) {
            $table->string('tenant_id', 64)->nullable()->after('id');
            $table->index(['tenant_id', 'ts'], 'telemetry_events_tenant_ts_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_events', function (Blueprint $table) {
            $table->dropIndex('telemetry_events_tenant_ts_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
