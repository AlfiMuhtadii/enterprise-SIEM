<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_audit_logs', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('export_id');
            $table->index(['tenant_id', 'exported_at'], 'eal_tenant_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('export_audit_logs', function (Blueprint $table) {
            $table->dropIndex('eal_tenant_time_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
