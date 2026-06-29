<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENTERPRISE-069 — Read-only RLS policy status check.
 * Reports whether RLS policies are defined and whether enforcement is enabled.
 * Advisory only — never enables or disables RLS autonomously.
 */
class TenantRlsStatusCommand extends Command
{
    protected $signature = 'tenant:rls-status {--output= : Optional JSON output file}';
    protected $description = 'Check PostgreSQL RLS policy status for tenant-isolated tables (advisory, read-only)';

    private const RLS_TABLES = ['security_alerts', 'security_incidents'];

    public function handle(): int
    {
        $results = [];
        $overall = 'OK';

        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('RLS check only supported on PostgreSQL. Driver: ' . DB::getDriverName());
            return self::SUCCESS;
        }

        foreach (self::RLS_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                $results[$table] = ['policy_defined' => false, 'rls_enabled' => false, 'note' => 'Table not found'];
                continue;
            }

            $policyName = "xdr_tenant_isolation_{$table}";

            $policyDefined = DB::table('pg_policies')
                ->where('tablename', $table)
                ->where('policyname', $policyName)
                ->exists();

            $rlsEnabled = DB::selectOne(
                "SELECT relrowsecurity FROM pg_class WHERE relname = ?",
                [$table]
            )?->relrowsecurity ?? false;

            $results[$table] = [
                'policy_defined' => $policyDefined,
                'rls_enabled'    => (bool) $rlsEnabled,
                'note'           => $policyDefined
                    ? ($rlsEnabled ? 'ENFORCED — queries are filtered by tenant_id' : 'DEFINED but not enforced (RLS disabled)')
                    : 'Policy NOT defined — run migrations to scaffold',
            ];

            if (!$policyDefined) {
                $overall = 'WARN';
            }
        }

        $report = [
            'command'      => 'tenant:rls-status',
            'overall'      => $overall,
            'rls_posture'  => 'RLS_ENABLED=false (advisory scaffold only)',
            'tables'       => $results,
            'note'         => 'Advisory only. Enabling RLS requires explicit operator decision — see docs/security/RLS_DECISION_RECORD.md',
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        if ($output = $this->option('output')) {
            @mkdir(dirname($output), 0755, true);
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$output}");
        }

        return self::SUCCESS;
    }
}
