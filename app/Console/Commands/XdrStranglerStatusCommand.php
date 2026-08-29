<?php

namespace App\Console\Commands;

use App\Services\InternalMtlsHttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class XdrStranglerStatusCommand extends Command
{
    protected $signature = 'xdr:strangler-status';

    protected $description = 'Record health and migration status for extracted XDR services.';

    public function handle(): int
    {
        $now = now();
        foreach (config('xdr.services', []) as $name => $definition) {
            $url = rtrim((string) ($definition['runtime_url'] ?? ''), '/');
            $checks = [
                'responsibility' => $definition['responsibility'] ?? '',
                'produces' => $definition['produces'] ?? [],
                'consumes' => $definition['consumes'] ?? [],
                'strangler_boundary' => $name !== 'soc-control-plane',
            ];
            $metrics = [
                'runtime_url' => $url,
                'migration_stage' => match ($name) {
                    'ingestion-gateway', 'telemetry-normalizer', 'ai-rag' => 'extracted_service_scaffolded',
                    'xdr-correlation' => 'shadow_worker_scaffolded',
                    default => 'laravel_control_plane',
                },
            ];
            $status = $name === 'soc-control-plane' ? 'healthy' : 'not_configured';

            if ($url !== '') {
                try {
                    $started = microtime(true);
                    $healthUrl = $url.'/health';
                    $response = InternalMtlsHttpClient::request($healthUrl, 2)->get($healthUrl);
                    $metrics['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
                    $metrics['http_status'] = $response->status();
                    $status = $response->successful() ? 'healthy' : 'unhealthy';
                    $checks['health_body'] = $response->json() ?? $response->body();
                } catch (\Throwable $exception) {
                    $status = 'offline';
                    $checks['error'] = $exception->getMessage();
                }
            }

            DB::table('xdr_service_health')->insert([
                'service_name' => $name,
                'status' => $status,
                'checks' => json_encode($checks),
                'metrics' => json_encode($metrics),
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->line("{$name}: {$status}");
        }

        return self::SUCCESS;
    }
}
