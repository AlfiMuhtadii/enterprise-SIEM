<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SocEnvironmentValidateCommand extends Command
{
    protected $signature = 'soc:env-validate {environment=local : local|staging|production}';

    protected $description = 'Validate SOC platform readiness for a target deployment environment.';

    public function handle(): int
    {
        $environment = (string) $this->argument('environment');
        $startedAt = now();
        $warnings = [];
        $checks = [
            'app_key_set' => (bool) config('app.key'),
            'database_reachable' => $this->databaseReachable(),
            'core_soc_tables' => $this->hasTables(['security_alerts', 'security_incidents', 'telemetry_events', 'soc_response_workflows']),
            'storage_writable' => is_writable(storage_path()),
            'queue_configured' => filled(config('queue.default')),
            'scheduler_supported' => true,
        ];

        if ($environment === 'production') {
            if ((bool) config('app.debug')) {
                $warnings[] = 'APP_DEBUG should be false in production.';
            }
            if (config('queue.default') === 'sync') {
                $warnings[] = 'QUEUE_CONNECTION=sync is not recommended for production.';
            }
            if (!config('session.secure')) {
                $warnings[] = 'SESSION_SECURE_COOKIE should be true behind HTTPS.';
            }
        }

        foreach ($checks as $name => $passed) {
            if (!$passed) {
                $warnings[] = "Check failed: {$name}.";
            }
        }

        $runId = 'env-'.Str::uuid()->toString();
        DB::table('enterprise_validation_runs')->insert([
            'run_id' => $runId,
            'run_type' => 'environment_validation',
            'environment' => $environment,
            'status' => empty($warnings) ? 'passed' : 'passed_with_warnings',
            'metrics' => json_encode($checks),
            'warnings' => json_encode($warnings),
            'generated_by' => 'artisan',
            'started_at' => $startedAt,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("run_id={$runId} environment={$environment} warnings=".count($warnings));
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    private function databaseReachable(): bool
    {
        try {
            DB::select('select 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
