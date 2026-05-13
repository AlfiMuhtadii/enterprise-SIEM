<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class XdrStreamMaturityCommand extends Command
{
    protected $signature = 'xdr:stream-maturity {--consumers=3} {--partitions=6} {--replay-events=10000}';

    protected $description = 'Record stream reliability, partition lag, backpressure, retry, DLQ, and rebalance maturity metrics.';

    public function handle(): int
    {
        $runId = 'stream-maturity-'.Str::uuid();
        $consumers = max(1, (int) $this->option('consumers'));
        $partitions = max(1, (int) $this->option('partitions'));
        $replayEvents = max(0, (int) $this->option('replay-events'));

        foreach (config('xdr.topics', []) as $topic => $definition) {
            $latest = DB::table('xdr_stream_metrics')->where('topic', $topic)->orderByDesc('measured_at')->first();
            $produced = (int) ($latest->produced_count ?? 0);
            $consumed = (int) ($latest->consumed_count ?? 0);
            $lag = max(0, (int) ($latest->consumer_lag ?? ($produced - $consumed)));
            $replayPressure = $replayEvents > 0 ? min(1.0, $replayEvents / max(1, $partitions * $consumers * 5000)) : 0.0;
            $backpressure = min(1.0, ($lag + $replayEvents) / max(1, $partitions * $consumers * 10000));
            $saturation = min(1.0, $backpressure + ($replayPressure * 0.25));
            $warnings = [];
            if ($lag > 1000) {
                $warnings[] = 'partition_lag_high';
            }
            if ($backpressure >= 0.75) {
                $warnings[] = 'ingestion_pressure_alert';
            }
            if (($latest->dead_letter_count ?? 0) > 0) {
                $warnings[] = 'dead_letter_queue_non_empty';
            }

            DB::table('xdr_stream_reliability_metrics')->insert([
                'run_id' => $runId,
                'topic' => $topic,
                'consumer_group' => str_replace('.', '-', $topic).'-consumer',
                'partition_count' => $partitions,
                'parallel_consumers' => $consumers,
                'partition_lag' => $lag,
                'retry_count' => (int) ($latest->retry_count ?? 0),
                'dead_letter_count' => (int) ($latest->dead_letter_count ?? 0),
                'rebalance_count' => max(0, $partitions - $consumers),
                'backpressure_ratio' => round($backpressure, 4),
                'replay_pressure' => round($replayPressure, 4),
                'throughput_eps' => (float) ($latest->throughput_eps ?? 0),
                'saturation_ratio' => round($saturation, 4),
                'status' => empty($warnings) ? 'healthy' : 'warning',
                'warnings' => json_encode($warnings),
                'measured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info("run_id={$runId}");
        return self::SUCCESS;
    }
}
