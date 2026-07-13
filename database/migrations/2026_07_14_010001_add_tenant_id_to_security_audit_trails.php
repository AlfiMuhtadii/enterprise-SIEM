<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TENANT-AUDIT-LEAK: security_audit_trails was registered in
        // TenantBoundaryService::UNISOLATED_TABLES -- /soc/api/audit
        // returned every tenant's audit entries globally. Nullable +
        // append-only, matching honeytoken_hits' tenant_id migration
        // convention: existing rows stay tenant_id=NULL (pre-existing
        // null-tenant data is an accepted, documented condition elsewhere
        // in this codebase), new rows are scoped where the calling
        // AuditLogger::log() call site passes a tenantId.
        Schema::table('security_audit_trails', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('target_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_trails', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
