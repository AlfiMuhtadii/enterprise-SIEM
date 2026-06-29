<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TENANT-UNSCOPED-TABLES: add tenant_id to workflow tables that previously lacked it
        foreach (['investigations', 'response_plans', 'entities'] as $table) {
            if (!Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('tenant_id', 36)->nullable()->index();
                });
            }
        }

        // threat_hunts is append-only — add tenant_id for scoping but never UPDATE rows
        if (!Schema::hasColumn('threat_hunts', 'tenant_id')) {
            Schema::table('threat_hunts', function (Blueprint $t) {
                $t->string('tenant_id', 36)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['investigations', 'response_plans', 'entities', 'threat_hunts'] as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('tenant_id');
                });
            }
        }
    }
};
