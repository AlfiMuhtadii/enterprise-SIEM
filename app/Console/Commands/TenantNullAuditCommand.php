<?php

namespace App\Console\Commands;

use App\Services\TenantBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BACKLOG-TENANCY-023 — Tenant Null Audit
 *
 * Read-only posture report for existing null tenant_id records across all
 * tenant-isolated tables. Never mutates any record. Safe to run against
 * append-only tables.
 *
 * Usage:
 *   php artisan tenant:null-audit
 *   php artisan tenant:null-audit --output=reports/tenant_null_audit.json
 *   php artisan tenant:null-audit --table=advisory_findings
 *
 * Exit codes:
 *   0 — all audited tables have zero null-tenant records
 *   1 — one or more tables have null-tenant records (action may be needed)
 */
class TenantNullAuditCommand extends Command
{
    protected $signature = 'tenant:null-audit
                            {--output= : Write JSON report to this path}
                            {--table=  : Audit a single table instead of all isolated tables}';

    protected $description = 'Report-only: list null tenant_id record counts across tenant-scoped tables (BACKLOG-023)';

    public function handle(): int
    {
        $specific = $this->option('table');
        if ($specific !== null && !in_array($specific, TenantBoundaryService::ISOLATED_TABLES, true)) {
            $this->error("Table '{$specific}' is not a registered tenant-isolated table. Use php artisan tenant:null-audit without --table to audit all isolated tables.");
            return self::FAILURE;
        }

        $tables = $this->resolveTables();

        $this->line('');
        $this->line('<fg=cyan>Tenant NULL Audit — read-only posture report</>');
        $this->line('<fg=gray>No records are modified by this command.</>');
        $this->line('');

        $rows    = [];
        $hasNull = false;

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $rows[] = [
                    'table'        => $table,
                    'null_records' => 'N/A',
                    'total'        => 'N/A',
                    'null_pct'     => 'N/A',
                    'status'       => 'TABLE_MISSING',
                ];
                continue;
            }

            $hasColumn = Schema::hasColumn($table, 'tenant_id');

            if (!$hasColumn) {
                $rows[] = [
                    'table'        => $table,
                    'null_records' => 'N/A',
                    'total'        => 'N/A',
                    'null_pct'     => 'N/A',
                    'status'       => 'NO_TENANT_COLUMN',
                ];
                continue;
            }

            $nullCount  = DB::table($table)->whereNull('tenant_id')->count();
            $totalCount = DB::table($table)->count();
            $pct        = $totalCount > 0
                ? round(($nullCount / $totalCount) * 100, 1)
                : 0.0;

            if ($nullCount > 0) {
                $hasNull = true;
            }

            $rows[] = [
                'table'        => $table,
                'null_records' => $nullCount,
                'total'        => $totalCount,
                'null_pct'     => $pct . '%',
                'status'       => $nullCount === 0 ? 'CLEAN' : 'HAS_NULL',
            ];
        }

        $this->table(
            ['Table', 'Null Tenant Records', 'Total', 'Null %', 'Status'],
            array_map(fn($r) => [
                $r['table'],
                $r['null_records'],
                $r['total'],
                $r['null_pct'],
                $r['status'],
            ], $rows),
        );

        $nullTables = array_filter($rows, fn($r) => $r['status'] === 'HAS_NULL');

        if (empty($nullTables)) {
            $this->info('All audited tables are clean (zero null tenant_id records).');
        } else {
            $this->warn(count($nullTables) . ' table(s) have null tenant_id records.');
            $this->line('Run Phase 3 of docs/security/TENANT_NULL_MIGRATION_PLAN.md to backfill.');
        }

        $outputPath = $this->option('output');
        if ($outputPath) {
            $report = [
                'generated_at'   => now()->toIso8601String(),
                'has_null_records' => $hasNull,
                'tables'         => $rows,
                'summary' => [
                    'total_tables_audited' => count($rows),
                    'tables_with_null'     => count(array_filter($rows, fn($r) => $r['status'] === 'HAS_NULL')),
                    'tables_clean'         => count(array_filter($rows, fn($r) => $r['status'] === 'CLEAN')),
                    'tables_missing'       => count(array_filter($rows, fn($r) => $r['status'] === 'TABLE_MISSING')),
                ],
            ];

            $dir = dirname($outputPath);
            if ($dir && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT) . "\n");
            $this->line("Report written to: {$outputPath}");
        }

        return $hasNull ? self::FAILURE : self::SUCCESS;
    }

    private function resolveTables(): array
    {
        $specific = $this->option('table');
        if ($specific) {
            return [$specific];
        }

        return TenantBoundaryService::ISOLATED_TABLES;
    }
}
