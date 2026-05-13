<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class XdrStorageValidateCommand extends Command
{
    protected $signature = 'xdr:storage-validate';

    protected $description = 'Validate specialized XDR storage responsibilities and record health metrics.';

    public function handle(): int
    {
        foreach (config('xdr.storage', []) as $storeName => $definition) {
            $started = microtime(true);
            $status = 'configured';
            $metrics = ['mode' => 'configuration_validation'];

            try {
                if (($definition['driver'] ?? '') === 'postgresql') {
                    DB::select('select 1');
                    $status = 'healthy';
                    $metrics['roundtrip'] = 'ok';
                } elseif (($definition['driver'] ?? '') === 'clickhouse') {
                    $response = Http::timeout((int) config('xdr.infrastructure.clickhouse.timeout_seconds', 5))
                        ->withBasicAuth(config('xdr.infrastructure.clickhouse.user'), config('xdr.infrastructure.clickhouse.password'))
                        ->get(rtrim(config('xdr.infrastructure.clickhouse.http_url'), '/').'/ping');
                    $status = $response->successful() ? 'healthy' : 'degraded';
                    $metrics['http_status'] = $response->status();
                } elseif (($definition['driver'] ?? '') === 'opensearch') {
                    $request = Http::timeout((int) config('xdr.infrastructure.opensearch.timeout_seconds', 5));
                    if (config('xdr.infrastructure.opensearch.user')) {
                        $request = $request->withBasicAuth(config('xdr.infrastructure.opensearch.user'), config('xdr.infrastructure.opensearch.password'));
                    }
                    $response = $request->get(rtrim(config('xdr.infrastructure.opensearch.url'), '/').'/_cluster/health');
                    $status = $response->successful() ? 'healthy' : 'degraded';
                    $metrics['http_status'] = $response->status();
                    $metrics['cluster'] = $response->json();
                } elseif (($definition['driver'] ?? '') === 'qdrant') {
                    $response = Http::timeout((int) config('xdr.infrastructure.qdrant.timeout_seconds', 5))
                        ->get(rtrim(config('xdr.infrastructure.qdrant.url'), '/').'/');
                    $status = $response->successful() ? 'healthy' : 'degraded';
                    $metrics['http_status'] = $response->status();
                } else {
                    $status = 'not_connected';
                    $metrics['note'] = 'Unknown external store driver.';
                }
            } catch (\Throwable $e) {
                $status = 'degraded';
                $metrics['error'] = $e->getMessage();
            }

            DB::table('xdr_storage_health')->insert([
                'store_name' => $storeName,
                'driver' => $definition['driver'],
                'status' => $status,
                'retention_days' => $definition['retention_days'] ?? null,
                'query_latency_ms' => round((microtime(true) - $started) * 1000, 2),
                'metrics' => json_encode($metrics),
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->line("{$storeName}: {$definition['driver']} {$status}");
        }

        return self::SUCCESS;
    }
}
