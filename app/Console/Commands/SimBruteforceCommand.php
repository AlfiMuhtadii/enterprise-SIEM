<?php

namespace App\Console\Commands;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SimBruteforceCommand extends Command
{
    protected $signature = 'sim:bruteforce
                            {--base-url=http://127.0.0.1:8000 : Target app base URL}
                            {--attempts=40 : Number of failed login attempts}
                            {--ip=203.0.113.10 : Source IP sent via X-Forwarded-For}
                            {--vary-ip=0 : Use a different IP per request (0/1)}
                            {--sleep-ms=0 : Delay between attempts in milliseconds}
                            {--tag=default : Scenario tag for metadata}';

    protected $description = 'Simulate repeated failed login attempts';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $attempts = max(1, (int) $this->option('attempts'));
        $defaultIp = (string) $this->option('ip');
        $varyIp = (bool) ((int) $this->option('vary-ip'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $tag = (string) $this->option('tag');
        $runId = DB::table('attack_runs')->insertGetId([
            'attack_type' => 'bruteforce',
            'started_at' => DB::raw('CURRENT_TIMESTAMP'),
            'metadata' => json_encode([
                'base_url' => $baseUrl,
                'attempts' => $attempts,
                'ip' => $defaultIp,
                'vary_ip' => $varyIp,
                'sleep_ms' => $sleepMs,
                'tag' => $tag,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jar = new CookieJar();
        $client = Http::withOptions([
            'allow_redirects' => false,
            'http_errors' => false,
            'cookies' => $jar,
        ]);

        try {
            $loginPage = $client->get($baseUrl . '/login');
            if (!$loginPage->successful()) {
                $this->error('Failed to open /login. Is the app running?');
                return self::FAILURE;
            }

            if (!preg_match('/name="_token"\s+value="([^"]+)"/', $loginPage->body(), $matches)) {
                $this->error('CSRF token not found on /login page.');
                return self::FAILURE;
            }

            $token = $matches[1];

            for ($i = 1; $i <= $attempts; $i++) {
                $ip = $varyIp ? '203.0.113.' . (($i % 200) + 1) : $defaultIp;
                $email = "attacker{$i}@example.com";

                $client
                    ->asForm()
                    ->withHeaders(['X-Forwarded-For' => $ip])
                    ->post($baseUrl . '/login', [
                        '_token' => $token,
                        'email' => $email,
                        'password' => 'wrongpassword',
                    ]);

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            $this->info("Bruteforce simulation done: {$attempts} attempts.");

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
