<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SocRetentionCostReportCommand extends Command
{
    protected $signature = 'soc:retention-cost {--days=30 : Retention period to estimate} {--environment=local : Target environment label}';

    protected $description = 'Estimate SOC retention storage and operational cost from current table volumes.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $environment = (string) $this->option('environment');
        $tables = ['telemetry_events', 'security_alerts', 'security_incidents', 'security_audit_trails', 'ai_execution_history'];
        $rowCounts = [];

        foreach ($tables as $table) {
            $rowCounts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        $estimatedMb = round(
            ($rowCounts['telemetry_events'] * 1.5
            + $rowCounts['security_alerts'] * 2.0
            + $rowCounts['security_incidents'] * 3.0
            + $rowCounts['security_audit_trails'] * 1.0
            + $rowCounts['ai_execution_history'] * 2.5) / 1024,
            3
        );
        $monthlyGb = round(($estimatedMb / 1024) * (30 / $days), 3);
        $estimatedCost = [
            'estimated_current_mb' => $estimatedMb,
            'estimated_monthly_gb' => $monthlyGb,
            'storage_cost_usd_month_low' => round($monthlyGb * 0.10, 2),
            'storage_cost_usd_month_high' => round($monthlyGb * 0.25, 2),
            'assumption' => 'Logical row-size estimate, excluding DB indexes and backups.',
        ];
        $recommendations = [
            'hot_retention_days' => min($days, 30),
            'archive_after_days' => max(30, $days),
            'compress_archives' => true,
            'review_high_volume_tables' => array_keys(array_filter($rowCounts, fn ($count) => $count > 100000)),
        ];
        $reportId = 'cost-'.Str::uuid()->toString();

        DB::table('retention_cost_reports')->insert([
            'report_id' => $reportId,
            'environment' => $environment,
            'period_days' => $days,
            'storage_metrics' => json_encode(['rows' => $rowCounts]),
            'estimated_cost' => json_encode($estimatedCost),
            'recommendations' => json_encode($recommendations),
            'generated_by' => 'artisan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("report_id={$reportId} estimated_current_mb={$estimatedMb} estimated_monthly_gb={$monthlyGb}");

        return self::SUCCESS;
    }
}
