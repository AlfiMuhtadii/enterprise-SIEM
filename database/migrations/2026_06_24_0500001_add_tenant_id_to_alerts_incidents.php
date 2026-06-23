<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKLOG-TENANCY-019: Add tenant_id to security_alerts and security_incidents.
 *
 * Both tables previously had no tenant_id column, making multi-tenant row
 * isolation impossible without application-layer guards.
 *
 * Nullable with index — existing rows remain accessible (null = unscoped /
 * legacy single-tenant). Set tenant_id on new rows to enable future scoping.
 *
 * NOTE: PostgreSQL Row-Level Security (RLS) is NOT enabled by this migration.
 * See docs/security/TENANT_ISOLATION_POSTURE.md for current isolation posture
 * and the roadmap to full RLS enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->index()->after('alert_id');
        });

        Schema::table('security_incidents', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->index()->after('incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        Schema::table('security_incidents', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
