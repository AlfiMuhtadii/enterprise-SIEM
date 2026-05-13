<?php

namespace App\Console\Commands;

use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocDetectionMaturityMonitorCommand extends Command
{
    protected $signature = 'soc:detection-maturity {--type=monitor}';
    protected $description = 'Track detection drift, noisy environment behavior, benchmark degradation, and alert volume anomalies.';

    public function handle(): int
    {
        $now = now();
        $currentStart = now()->subDay();
        $previousStart = now()->subDays(2);
        $currentAlerts = DB::table('security_alerts')->where('detected_at', '>=', $currentStart)->count();
        $previousAlerts = DB::table('security_alerts')->whereBetween('detected_at', [$previousStart, $currentStart])->count();
        $currentFp = DB::table('alert_feedback')->where('verdict', 'false_positive')->where('marked_at', '>=', $currentStart)->count();
        $previousFp = DB::table('alert_feedback')->where('verdict', 'false_positive')->whereBetween('marked_at', [$previousStart, $currentStart])->count();
        $latestQuality = DB::table('detection_quality_history')->orderByDesc('measured_at')->first();
        $previousQuality = DB::table('detection_quality_history')->orderByDesc('measured_at')->skip(1)->first();

        $metrics = [
            'alert_volume_24h' => $currentAlerts,
            'alert_volume_previous_24h' => $previousAlerts,
            'false_positive_24h' => $currentFp,
            'false_positive_previous_24h' => $previousFp,
            'latest_precision' => $latestQuality->precision ?? null,
            'previous_precision' => $previousQuality->precision ?? null,
            'latest_recall' => $latestQuality->recall ?? null,
            'previous_recall' => $previousQuality->recall ?? null,
            'replay_instability' => $latestQuality && $previousQuality ? abs((int) $latestQuality->alert_volume - (int) $previousQuality->alert_volume) : null,
        ];

        $warnings = $this->warnings($metrics);
        $runId = 'maturity-'.Str::uuid();
        DB::table('detection_maturity_runs')->insert([
            'run_id' => $runId,
            'run_type' => (string) $this->option('type'),
            'status' => 'completed',
            'started_at' => $now,
            'completed_at' => now(),
            'metrics' => json_encode($metrics),
            'warnings' => json_encode($warnings),
            'generated_by' => 'cli',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($warnings as $warning) {
            DB::table('detection_quality_warnings')->insert([
                'warning_id' => 'dq-'.Str::uuid(),
                'warning_type' => $warning['type'],
                'severity' => $warning['severity'],
                'message' => $warning['message'],
                'evidence' => json_encode($warning['evidence']),
                'detected_at' => now(),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        AuditLogger::log('cli', 'detection_maturity.run', 'detection_maturity', $runId, null, ['warnings' => count($warnings)]);
        $this->info('run_id='.$runId.' warnings='.count($warnings));
        return self::SUCCESS;
    }

    private function warnings(array $metrics): array
    {
        $warnings = [];
        $alertSpikeRatio = (float) config('soc.quality_alert_volume_spike_ratio', 2.0);
        $fpSpikeRatio = (float) config('soc.quality_fp_spike_ratio', 1.5);
        if (($metrics['alert_volume_previous_24h'] ?? 0) > 0 && $metrics['alert_volume_24h'] / max(1, $metrics['alert_volume_previous_24h']) >= $alertSpikeRatio) {
            $warnings[] = ['type' => 'alert_volume_anomaly', 'severity' => 'medium', 'message' => 'Alert volume increased beyond configured spike threshold.', 'evidence' => $metrics];
        }
        if (($metrics['false_positive_previous_24h'] ?? 0) > 0 && $metrics['false_positive_24h'] / max(1, $metrics['false_positive_previous_24h']) >= $fpSpikeRatio) {
            $warnings[] = ['type' => 'false_positive_trend_change', 'severity' => 'high', 'message' => 'False-positive trend increased beyond threshold.', 'evidence' => $metrics];
        }
        if ($metrics['latest_precision'] !== null && $metrics['previous_precision'] !== null && (float) $metrics['latest_precision'] < (float) $metrics['previous_precision'] - 0.1) {
            $warnings[] = ['type' => 'benchmark_degradation', 'severity' => 'high', 'message' => 'Detection precision degraded by more than 0.10.', 'evidence' => $metrics];
        }
        return $warnings;
    }
}
