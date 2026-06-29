<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\TenantBoundaryService;

/**
 * ENTERPRISE-070 — Tenant Null Backfill Pre-Flight Validation
 *
 * Audits all mutable tables for null tenant_id records and reports
 * whether the system is safe to run TenantNullBackfillCommand.
 *
 * Advisory only — this command NEVER modifies any data.
 * To apply a backfill, run: php artisan tenant:backfill-nulls --dry-run=false
 */
class TenantBackfillPreflightCommand extends Command
{
    protected $signature   = 'tenant:backfill-preflight {--output= : Optional JSON output file}';
    protected $description = 'Pre-flight check: audit mutable tables for null tenant_id (advisory, read-only)';

    private const CHECKS = [
        'CHK-01' => 'Mutable tables accessible',
        'CHK-02' => 'Null tenant_id count across mutable tables',
        'CHK-03' => 'TenantNullBackfillCommand class available',
        'CHK-04' => 'TenantContextAuthority class available',
        'CHK-05' => 'RLS scaffold migration present',
        'CHK-06' => 'TENANT_STRICT_MODE is currently off',
    ];

    public function handle(): int
    {
        $results  = [];
        $overall  = 'PASS';
        $nullRows = [];

        // CHK-01: tables accessible
        $accessible = 0;
        foreach (TenantBoundaryService::MUTABLE_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $accessible++;
            }
        }
        $results['CHK-01'] = [
            'name'   => self::CHECKS['CHK-01'],
            'status' => $accessible > 0 ? 'PASS' : 'WARN',
            'detail' => "{$accessible} of " . count(TenantBoundaryService::MUTABLE_TABLES) . ' mutable tables accessible',
        ];

        // CHK-02: null counts
        $totalNull = 0;
        foreach (TenantBoundaryService::MUTABLE_TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $count = DB::table($table)->whereNull('tenant_id')->count();
            if ($count > 0) {
                $nullRows[$table] = $count;
                $totalNull += $count;
            }
        }
        $results['CHK-02'] = [
            'name'   => self::CHECKS['CHK-02'],
            'status' => $totalNull === 0 ? 'PASS' : 'WARN',
            'detail' => $totalNull === 0
                ? 'No null tenant_id records found — backfill not required'
                : "Total null records: {$totalNull} across " . count($nullRows) . ' tables',
            'null_by_table' => $nullRows,
        ];

        // CHK-03: backfill command exists
        $bf = class_exists(\App\Console\Commands\TenantNullBackfillCommand::class);
        $results['CHK-03'] = [
            'name'   => self::CHECKS['CHK-03'],
            'status' => $bf ? 'PASS' : 'FAIL',
            'detail' => $bf ? 'TenantNullBackfillCommand is registered' : 'TenantNullBackfillCommand not found',
        ];
        if (!$bf) {
            $overall = 'FAIL';
        }

        // CHK-04: TenantContextAuthority exists
        $ca = class_exists(\App\Services\TenantContextAuthority::class);
        $results['CHK-04'] = [
            'name'   => self::CHECKS['CHK-04'],
            'status' => $ca ? 'PASS' : 'FAIL',
            'detail' => $ca ? 'TenantContextAuthority is in place' : 'TenantContextAuthority not found',
        ];
        if (!$ca) {
            $overall = 'FAIL';
        }

        // CHK-05: RLS scaffold migration
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $rlsMig     = !empty($migrations);
        $results['CHK-05'] = [
            'name'   => self::CHECKS['CHK-05'],
            'status' => $rlsMig ? 'PASS' : 'WARN',
            'detail' => $rlsMig ? 'RLS scaffold migration found' : 'RLS scaffold migration not found — run ENTERPRISE-069',
        ];

        // CHK-06: strict mode off
        $strictMode = (bool) env('XDR_TENANT_STRICT_MODE', false);
        $results['CHK-06'] = [
            'name'   => self::CHECKS['CHK-06'],
            'status' => !$strictMode ? 'PASS' : 'WARN',
            'detail' => !$strictMode
                ? 'XDR_TENANT_STRICT_MODE=false — safe to backfill in current posture'
                : 'XDR_TENANT_STRICT_MODE=true — backfill while strict mode is active will affect live request flow',
        ];

        $report = [
            'command'         => 'tenant:backfill-preflight',
            'overall'         => $overall,
            'total_null_rows' => $totalNull,
            'checks'          => $results,
            'recommendation'  => $totalNull > 0
                ? 'Run: php artisan tenant:backfill-nulls --dry-run=true to preview changes'
                : 'No action required — mutable tables have no null tenant_id records',
            'note'            => 'Advisory only. This command never modifies data.',
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
