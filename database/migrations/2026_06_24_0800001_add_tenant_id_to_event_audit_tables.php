<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Append-only event tables that were in ISOLATED_TABLES but lacked tenant_id (DB-1).
    public function up(): void
    {
        Schema::table('advisory_finding_events', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('analyst_id');
            $table->index('tenant_id');
        });

        Schema::table('dlq_normalization_events', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('analyst_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('advisory_finding_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('dlq_normalization_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
