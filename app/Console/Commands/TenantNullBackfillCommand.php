<?php

namespace App\Console\Commands;

use App\Services\TenantBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENTERPRISE-046 — Tenant Null Backfill
 *
 * Phase 3 of docs/security/TENANT_NULL_MIGRATION_PLAN.md.
 *
 * Assigns a canonical tenant_id to every record where tenant_id IS NULL
 * on MUTABLE tables only (security_alerts, security_incidents, dlq_records).
 * Append-only tables are never touched.
 *
 * Usage:
 *   php artisan tenant:backfill-nulls --dry-run
 *   php artisan tenant:backfill-nulls --tenant=default
 *   php artisan tenant:backfill-nulls --tenant=my-org --batch=500
 *
 * Exit codes:
 *   0 — all mutable tables are clean (zero null-tenant rows) after the operation
 *   1 — null rows remain (dry-run shows pending work, or write error)
 */
class TenantNullBackfillCommand extends Command
{
    protected $signature = 'tenant:backfill-nulls
                            {--tenant=default : Tenant ID to assign to null records}
                            {--dry-run        : Report counts only; do not write}
                            {--batch=1000     : Records per UPDATE batch}';

    protected $description = 'Phase 3: Backfill null tenant_id on mutable tables (security_alerts, security_incidents, dlq_records)';

    public function handle(): int
    {
        $tenantId  = (string) $this->option('tenant');
        $dryRun    = (bool)   $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch'));

        if ($tenantId === '') {
            $this->error('--tenant must be a non-empty string.');
            return self::FAILURE;
        }

        $this->line('');
        $mode = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[WRITE]</>';
        $this->line("{$mode} Tenant Null Backfill — Phase 3");
        $this->line("  tenant   : {$tenantId}");
        $this->line("  batch    : {$batchSize}");
        $this->line('');

        $results   = [];
        $anyNulls  = false;

        foreach (TenantBoundaryService::MUTABLE_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                $results[] = ['table' => $table, 'null_before' => 'N/A', 'updated' => 0, 'null_after' => 'N/A', 'status' => 'TABLE_MISSING'];
                continue;
            }

            if (!Schema::hasColumn($table, 'tenant_id')) {
                $results[] = ['table' => $table, 'null_before' => 'N/A', 'updated' => 0, 'null_after' => 'N/A', 'status' => 'NO_COLUMN'];
                continue;
            }

            $nullBefore = DB::table($table)->whereNull('tenant_id')->count();

            if ($nullBefore === 0) {
                $results[] = ['table' => $table, 'null_before' => 0, 'updated' => 0, 'null_after' => 0, 'status' => 'CLEAN'];
                continue;
            }

            if ($dryRun) {
                $anyNulls = true;
                $results[] = ['table' => $table, 'null_before' => $nullBefore, 'updated' => 0, 'null_after' => $nullBefore, 'status' => 'PENDING'];
                continue;
            }

            $updated   = $this->backfillTable($table, $tenantId, $batchSize);
            $nullAfter = DB::table($table)->whereNull('tenant_id')->count();

            $results[] = [
                'table'       => $table,
                'null_before' => $nullBefore,
                'updated'     => $updated,
                'null_after'  => $nullAfter,
                'status'      => $nullAfter === 0 ? 'DONE' : 'PARTIAL',
            ];

            if ($nullAfter > 0) {
                $anyNulls = true;
            }
        }

        $this->table(
            ['Table', 'Null Before', 'Updated', 'Null After', 'Status'],
            array_map(fn ($r) => [
                $r['table'],
                $r['null_before'],
                $r['updated'],
                $r['null_after'],
                $r['status'],
            ], $results),
        );

        $appendOnly = TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES;
        $this->line('');
        $this->line('Append-only tables (not backfilled — accepted risk per TENANT_NULL_MIGRATION_PLAN.md):');
        foreach ($appendOnly as $t) {
            $this->line("  - {$t}");
        }
        $this->line('');

        if ($dryRun && $anyNulls) {
            $this->warn('Dry-run complete. Re-run without --dry-run to apply.');
            return self::FAILURE;
        }

        if (!$dryRun && $anyNulls) {
            $this->error('Backfill incomplete — partial failure.');
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry-run complete (all tables already clean).' : 'Backfill complete. All mutable tables are clean.');
        return self::SUCCESS;
    }

    private function backfillTable(string $table, string $tenantId, int $batchSize): int
    {
        $total = 0;
        do {
            $affected = DB::table($table)
                ->whereNull('tenant_id')
                ->limit($batchSize)
                ->update(['tenant_id' => $tenantId]);
            $total += $affected;
        } while ($affected === $batchSize);

        return $total;
    }
}
