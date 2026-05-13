<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class XdrSoakReport
{
    public static function latest(): array
    {
        $candidates = [
            'reports/xdr_correlation_soak_24h.json',
            'reports/xdr_correlation_soak_6h.json',
            'reports/xdr_correlation_soak_smoke.json',
        ];

        foreach ($candidates as $relativePath) {
            $path = base_path($relativePath);
            if (!File::exists($path)) {
                continue;
            }

            $decoded = json_decode(File::get($path), true);
            if (!is_array($decoded)) {
                continue;
            }

            return self::decision($decoded, $relativePath);
        }

        return [
            'available' => false,
            'decision' => 'keep_shadow',
            'reason' => 'missing_soak_report',
            'report_path' => null,
            'status' => 'missing',
            'metrics' => [],
            'checks' => [],
        ];
    }

    public static function decision(array $report, string $path): array
    {
        $metrics = $report['metrics'] ?? [];
        $checks = [
            'validation_passed' => ($report['validation_status'] ?? null) === 'PASS',
            'fallback_count_zero' => (int) ($metrics['fallback_count'] ?? 999999) === 0,
            'request_failures_zero' => (int) ($metrics['failure_count'] ?? 999999) === 0,
            'p95_under_300ms' => (float) ($metrics['p95_latency_ms'] ?? 999999) < 300,
            'goroutine_growth_zero' => (int) ($metrics['goroutine_growth'] ?? 999999) === 0,
            'memory_growth_stable' => (float) ($metrics['memory_growth_mb'] ?? 999999) <= 128,
            'latency_not_drifting' => (float) data_get($metrics, 'latency_ms_trend.delta', 999999) <= 0,
        ];

        $duration = (float) ($report['duration_minutes_requested'] ?? 0);
        $allGatesPass = !in_array(false, $checks, true);
        $decision = 'keep_shadow';
        $reason = 'soak_not_long_enough';

        if (!$allGatesPass) {
            $decision = 'rollback';
            $reason = 'soak_gate_failed';
        } elseif ($duration >= 360) {
            $decision = 'staged_active';
            $reason = 'six_hour_or_longer_soak_passed';
        }

        return [
            'available' => true,
            'decision' => $decision,
            'reason' => $reason,
            'report_path' => $path,
            'status' => $report['validation_status'] ?? 'unknown',
            'duration_minutes' => $duration,
            'scope' => $report['scope'] ?? 'identity-cloud',
            'metrics' => [
                'fallback_count' => (int) ($metrics['fallback_count'] ?? 0),
                'failure_count' => (int) ($metrics['failure_count'] ?? 0),
                'p95_latency_ms' => (float) ($metrics['p95_latency_ms'] ?? 0),
                'worker_p95_latency_ms' => (float) ($metrics['worker_p95_latency_ms'] ?? 0),
                'avg_throughput_eps' => (float) ($metrics['avg_throughput_eps'] ?? 0),
                'memory_growth_mb' => (float) ($metrics['memory_growth_mb'] ?? 0),
                'goroutine_growth' => (int) ($metrics['goroutine_growth'] ?? 0),
                'latency_drift_ms' => (float) data_get($metrics, 'latency_ms_trend.delta', 0),
                'heap_max_mb' => (float) data_get($metrics, 'heap_alloc_mb_trend.max', 0),
            ],
            'checks' => $checks,
            'gate_summary' => self::gateSummary($checks),
        ];
    }

    private static function gateSummary(array $checks): string
    {
        $failed = array_keys(array_filter($checks, fn (bool $passed) => !$passed));
        return $failed === [] ? 'all_gates_passed' : 'failed: '.implode(',', $failed);
    }
}
