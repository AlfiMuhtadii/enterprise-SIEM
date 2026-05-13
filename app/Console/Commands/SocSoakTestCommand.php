<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocSoakTestCommand extends Command
{
    protected $signature = 'soc:soak-test {--events=10000 : Number of synthetic events to model} {--environment=local : Target environment label}';

    protected $description = 'Run a safe high-volume SOC processing soak model and store performance metrics.';

    public function handle(): int
    {
        $events = max(1, (int) $this->option('events'));
        $environment = (string) $this->option('environment');
        $startedAt = now();

        $batches = (int) ceil($events / 500);
        $estimatedSeconds = max(1, $batches * 2);
        $metrics = [
            'mode' => 'safe_modelled_soak',
            'synthetic_events' => $events,
            'batch_size' => 500,
            'estimated_batches' => $batches,
            'estimated_processing_seconds' => $estimatedSeconds,
            'estimated_throughput_eps' => round($events / $estimatedSeconds, 2),
            'queue_lag_warning_threshold' => 5000,
            'dashboard_latency_warning_ms' => 1000,
        ];
        $warnings = [];
        if ($events >= 100000) {
            $warnings[] = 'Use async queue workers and partitioned ingestion for replay above 100k events.';
        }
        if (config('queue.default') === 'sync') {
            $warnings[] = 'QUEUE_CONNECTION=sync limits realistic soak behavior.';
        }

        $runId = 'soak-'.Str::uuid()->toString();
        DB::table('enterprise_validation_runs')->insert([
            'run_id' => $runId,
            'run_type' => 'soak_test',
            'environment' => $environment,
            'status' => empty($warnings) ? 'completed' : 'completed_with_warnings',
            'metrics' => json_encode($metrics),
            'warnings' => json_encode($warnings),
            'generated_by' => 'artisan',
            'started_at' => $startedAt,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("run_id={$runId} events={$events} estimated_throughput_eps={$metrics['estimated_throughput_eps']}");
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
