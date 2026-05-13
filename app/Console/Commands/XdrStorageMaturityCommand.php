<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class XdrStorageMaturityCommand extends Command
{
    protected $signature = 'xdr:storage-maturity';

    protected $description = 'Record ClickHouse/OpenSearch/Qdrant retention tiering, rollover, compression, and storage cost metrics.';

    public function handle(): int
    {
        $stores = [
            ['raw_telemetry', 'hot', 30, 'clickhouse', 0.28],
            ['normalized_telemetry', 'warm', 90, 'clickhouse', 0.35],
            ['searchable_telemetry', 'hot', 90, 'opensearch', 0.52],
            ['rag_vectors', 'warm', 365, 'qdrant', 0.42],
            ['archive_pipeline', 'cold', 730, 'object_storage', 0.18],
        ];
        $eventCount = DB::table('telemetry_events')->count();
        foreach ($stores as [$name, $tier, $days, $driver, $compression]) {
            $gb = round(max(0.001, ($eventCount * 1.2 / 1024 / 1024) * ($days / 30)), 4);
            $cost = round($gb * match ($tier) {
                'hot' => 0.25,
                'warm' => 0.12,
                'cold' => 0.03,
                default => 0.1,
            }, 4);
            DB::table('xdr_storage_maturity_metrics')->insert([
                'store_name' => $name,
                'tier' => $tier,
                'retention_days' => $days,
                'compression_ratio' => $compression,
                'estimated_storage_gb' => $gb,
                'estimated_monthly_cost_usd' => $cost,
                'optimization_actions' => json_encode([
                    'driver' => $driver,
                    'partitioning' => $driver === 'clickhouse' ? 'ORDER BY ts, telemetry_type + TTL' : null,
                    'archive_after_days' => $tier === 'hot' ? $days : null,
                ]),
                'rollover_policy' => json_encode([
                    'enabled' => in_array($driver, ['opensearch', 'clickhouse'], true),
                    'max_age_days' => min($days, 30),
                    'max_size_gb' => $tier === 'hot' ? 50 : 200,
                ]),
                'measured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->line("{$name}: {$tier} {$gb}GB cost={$cost}");
        }

        return self::SUCCESS;
    }
}
