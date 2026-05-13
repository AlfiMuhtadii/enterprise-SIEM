<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SimInjectionCommand extends Command
{
    protected $signature = 'sim:injection
                            {--base-url=http://127.0.0.1:8000 : Target app base URL}
                            {--ip=192.0.2.55 : Source IP sent via X-Forwarded-For}
                            {--repeats=1 : Repeat payload set N times}
                            {--sleep-ms=0 : Delay between requests in milliseconds}
                            {--tag=default : Scenario tag for metadata}';

    protected $description = 'Simulate suspicious search payloads for injection indicators';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $sourceIp = (string) $this->option('ip');
        $repeats = max(1, (int) $this->option('repeats'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $tag = (string) $this->option('tag');
        $payloads = [
            "' OR 1=1--",
            '<script>alert(1)</script>',
        ];
        $runId = DB::table('attack_runs')->insertGetId([
            'attack_type' => 'injection',
            'started_at' => DB::raw('CURRENT_TIMESTAMP'),
            'metadata' => json_encode([
                'base_url' => $baseUrl,
                'payload_count' => count($payloads),
                'source_ip' => $sourceIp,
                'repeats' => $repeats,
                'sleep_ms' => $sleepMs,
                'tag' => $tag,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $client = Http::withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            for ($round = 1; $round <= $repeats; $round++) {
                foreach ($payloads as $payload) {
                    $client
                        ->withHeaders(['X-Forwarded-For' => $sourceIp])
                        ->get($baseUrl . '/search', ['q' => $payload]);

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }

            $totalRequests = count($payloads) * $repeats;
            $this->info('Injection simulation done: ' . $totalRequests . ' requests.');

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
