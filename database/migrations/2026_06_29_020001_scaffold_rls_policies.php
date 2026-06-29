<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENTERPRISE-069 — PostgreSQL RLS Policy Scaffolding
 *
 * Defines RLS policy SQL for multi-tenant tables WITHOUT enabling enforcement.
 * Policies are created with IF NOT EXISTS (idempotent) so re-running migrate:fresh
 * is always safe.
 *
 * IMPORTANT: This migration only scaffolds policy definitions — it does NOT activate
 * enforcement. Activating enforcement requires an explicit operator decision documented
 * in docs/security/RLS_DECISION_RECORD.md.
 *
 * See: docs/security/RLS_DECISION_RECORD.md — current posture: RLS_ENABLED=false
 */
return new class extends Migration
{
    /**
     * Tables that will eventually be protected by RLS.
     * These policies are DEFINED but not ENFORCED until RLS is enabled.
     */
    private const RLS_TABLES = [
        'security_alerts',
        'security_incidents',
    ];

    /**
     * Policy expression: row visible when app.tenant_id setting matches OR is empty
     * (allows system/admin connections without a tenant context to read all rows).
     */
    private const POLICY_USING = <<<'SQL'
        tenant_id = current_setting('app.tenant_id', true)
        OR current_setting('app.tenant_id', true) IS NULL
        OR current_setting('app.tenant_id', true) = ''
    SQL;

    public function up(): void
    {
        // Only scaffold on PostgreSQL — skip for other DB drivers (e.g., SQLite in tests)
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::RLS_TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $policyName = "xdr_tenant_isolation_{$table}";

            // CREATE POLICY without ENABLE ROW LEVEL SECURITY — zero behavioral change.
            // Policy definition only lives in pg_policies catalog until RLS is enabled.
            DB::statement(<<<SQL
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_policies
                        WHERE tablename = '{$table}'
                          AND policyname = '{$policyName}'
                    ) THEN
                        EXECUTE 'CREATE POLICY {$policyName} ON {$table}
                            USING (
                                tenant_id = current_setting(''app.tenant_id'', true)
                                OR current_setting(''app.tenant_id'', true) IS NULL
                                OR current_setting(''app.tenant_id'', true) = ''''
                            )';
                    END IF;
                END $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::RLS_TABLES as $table) {
            $policyName = "xdr_tenant_isolation_{$table}";
            DB::statement("DROP POLICY IF EXISTS {$policyName} ON {$table}");
        }
    }
};
