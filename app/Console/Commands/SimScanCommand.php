<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SimScanCommand extends Command
{
    protected $signature = 'sim:scan
                            {--base-url=http://127.0.0.1:8000 : Target app base URL}
                            {--count=50 : Total number of scan paths}
                            {--ip=198.51.100.77 : Source IP sent via X-Forwarded-For}
                            {--prefix=/scan : Prefix for generated scan paths}
                            {--include-sensitive=1 : Include /.env,/phpMyAdmin,/wp-admin,/vendor probes (0/1)}
                            {--sleep-ms=0 : Delay between requests in milliseconds}
                            {--tag=default : Scenario tag for metadata}';

    protected $description = 'Simulate path scanning and sensitive path probes';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $count = max(4, (int) $this->option('count'));
        $sourceIp = (string) $this->option('ip');
        $prefix = '/' . trim((string) $this->option('prefix'), '/');
        $includeSensitive = (bool) ((int) $this->option('include-sensitive'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $tag = (string) $this->option('tag');
        $runId = DB::table('attack_runs')->insertGetId([
            'attack_type' => 'scan',
            'started_at' => DB::raw('CURRENT_TIMESTAMP'),
            'metadata' => json_encode([
                'base_url' => $baseUrl,
                'count' => $count,
                'source_ip' => $sourceIp,
                'prefix' => $prefix,
                'include_sensitive' => $includeSensitive,
                'sleep_ms' => $sleepMs,
                'tag' => $tag,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $paths = [];
            if ($includeSensitive) {
                $paths = [
                    '/.env',
                    '/phpMyAdmin',
                    '/wp-admin',
                    '/vendor',
                ];
            }

            for ($i = count($paths); $i < $count; $i++) {
                $paths[] = $prefix . '/' . Str::random(12);
            }

            $client = Http::withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            foreach ($paths as $path) {
                $client
                    ->withHeaders(['X-Forwarded-For' => $sourceIp])
                    ->get($baseUrl . $path);

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            $this->info('Scan simulation done: ' . count($paths) . ' paths.');

            return self::SUCCESS;
        } finally {
            DB::table('attack_runs')
                ->where('id', $runId)
                ->update([
                    'ended_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'updated_at' => now(),
                ]);
        }
    }
}
