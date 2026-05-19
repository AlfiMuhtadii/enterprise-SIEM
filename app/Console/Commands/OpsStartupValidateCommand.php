<?php

namespace App\Console\Commands;

use App\Models\ServiceHealthSnapshot;
use App\Services\OperationsHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsStartupValidateCommand extends Command
{
    protected $signature   = 'ops:startup-validate {--record : Record results to service_health_snapshots}';
    protected $description = 'Run non-destructive startup validation checks for platform dependencies';

    public function handle(OperationsHealthService $health): int
    {
        $this->info('=== XDR Platform Startup Validation ===');
        $this->line('Operational hardening checks only. Non-destructive. No infrastructure mutation.');
        $this->newLine();

        $checks  = [];
        $hasWarn = false;
        $hasFail = false;

        // 1. Required secrets check
        $requiredSecrets = ['APP_KEY', 'DB_PASSWORD', 'REDPANDA_HOST'];
        $missingSecrets  = array_filter($requiredSecrets, fn ($k) => empty(env($k)));
        if (!empty($missingSecrets)) {
            $checks[] = ['WARN', 'secrets',    'Missing env vars: ' . implode(', ', $missingSecrets)];
            $hasWarn  = true;
        } else {
            $checks[] = ['PASS', 'secrets',    'Required env vars present'];
        }

        // 2. PostgreSQL connectivity
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = (int) ((microtime(true) - $start) * 1000);
            $checks[] = ['PASS', 'postgresql', "Connected ({$ms}ms)"];
        } catch (\Throwable $e) {
            $checks[] = ['FAIL', 'postgresql', 'Connection failed: ' . $e->getMessage()];
            $hasFail  = true;
        }

        // 3. Migration compatibility check
        try {
            $migCount = DB::table('migrations')->count();
            $checks[] = ['PASS', 'migrations', "{$migCount} migrations applied"];
        } catch (\Throwable $e) {
            $checks[] = ['WARN', 'migrations', 'Cannot read migrations table: ' . $e->getMessage()];
            $hasWarn  = true;
        }

        // 4. Schema version — critical tables
        $criticalTables = ['security_alerts', 'endpoint_agents', 'endpoint_stream_events', 'retention_policies'];
        foreach ($criticalTables as $table) {
            try {
                DB::table($table)->limit(1)->exists();
                $checks[] = ['PASS', "schema:{$table}", 'Table accessible'];
            } catch (\Throwable $e) {
                $checks[] = ['FAIL', "schema:{$table}", 'Table not accessible: ' . $e->getMessage()];
                $hasFail  = true;
            }
        }

        // 5. Storage writability check (temp file)
        try {
            $tmpPath = storage_path('app/.startup_write_test');
            file_put_contents($tmpPath, 'test');
            unlink($tmpPath);
            $checks[] = ['PASS', 'storage',    'Storage path is writable'];
        } catch (\Throwable $e) {
            $checks[] = ['WARN', 'storage',    'Storage write failed: ' . $e->getMessage()];
            $hasWarn  = true;
        }

        // 6. OpenSearch availability (non-blocking — graceful DLQ fallback is expected)
        $osHost = config('services.opensearch.host', env('OPENSEARCH_HOST', ''));
        if (!$osHost) {
            $checks[] = ['WARN', 'opensearch', 'OPENSEARCH_HOST not set (graceful DLQ fallback active)'];
            $hasWarn  = true;
        } else {
            $checks[] = ['INFO', 'opensearch', "Host configured: {$osHost}"];
        }

        // 7. Redpanda/Kafka configuration
        $rpHost = env('REDPANDA_HOST', '');
        if (!$rpHost) {
            $checks[] = ['WARN', 'redpanda',   'REDPANDA_HOST not configured'];
            $hasWarn  = true;
        } else {
            $checks[] = ['PASS', 'redpanda',   "Host configured: {$rpHost}"];
        }

        // Output results
        foreach ($checks as [$status, $check, $message]) {
            $color = match ($status) {
                'PASS' => 'info',
                'WARN' => 'warn',
                'FAIL' => 'error',
                default => 'line',
            };
            $this->{$color}(sprintf('  [%-4s] %-25s %s', $status, $check, $message));
        }

        $this->newLine();
        $summary = $hasFail ? 'STARTUP VALIDATION FAILED' : ($hasWarn ? 'STARTUP VALIDATION PASSED WITH WARNINGS' : 'STARTUP VALIDATION PASSED');
        $this->line($summary);

        if ($this->option('record')) {
            $state = $hasFail ? 'degraded' : ($hasWarn ? 'degraded' : 'healthy');
            $health->recordServiceHealth('laravel-soc', $state, null, null, [
                'startup_checks' => count($checks),
                'has_warnings'   => $hasWarn,
                'has_failures'   => $hasFail,
                'summary'        => $summary,
            ]);
            $this->line('Results recorded to service_health_snapshots.');
        }

        return $hasFail ? self::FAILURE : self::SUCCESS;
    }
}
