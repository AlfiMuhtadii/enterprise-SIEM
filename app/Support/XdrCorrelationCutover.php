<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class XdrCorrelationCutover
{
    public static function status(?string $engineOverride = null, ?string $scopeOverride = null): array
    {
        $configuredEngine = self::validEngine($engineOverride ?: (string) config('xdr.correlation.engine', 'shadow'));
        $scope = $scopeOverride ?: (string) config('xdr.correlation.scope', 'identity-cloud');
        $fallbackEnabled = (bool) config('xdr.correlation.fallback_to_legacy', true);
        $workerUrl = rtrim((string) config('xdr.services.xdr-correlation.runtime_url', ''), '/');
        $goHealth = self::goHealth($workerUrl);
        $report = self::latestParityReport();
        $monitoring = self::monitoring($report, $goHealth);

        $fallbackReason = null;
        $effectiveEngine = match ($configuredEngine) {
            'go' => 'go',
            default => 'legacy',
        };

        if ($configuredEngine === 'go' && ($goHealth['should_fallback'] ?? ! $goHealth['healthy']) && $fallbackEnabled) {
            $effectiveEngine = 'legacy';
            $fallbackReason = $goHealth['fallback_reason'] ?? 'go_worker_unhealthy';
        }

        return [
            'configured_engine' => $configuredEngine,
            'effective_engine' => $effectiveEngine,
            'scope' => $scope,
            'fallback_to_legacy' => $fallbackEnabled,
            'fallback_active' => $fallbackReason !== null,
            'fallback_reason' => $fallbackReason,
            'go_worker' => $goHealth,
            'source_of_truth' => $configuredEngine === 'shadow'
                ? 'legacy'
                : $effectiveEngine,
            'comparison_engine' => match ($configuredEngine) {
                'go' => 'legacy_shadow',
                'shadow' => 'go_shadow',
                default => $goHealth['healthy'] ? 'go_shadow' : 'none',
            },
            'cutover_gate' => self::gate($report),
            'monitoring' => $monitoring,
            'manual_rollback' => [
                'env' => 'XDR_CORRELATION_ENGINE=legacy',
                'scope_note' => 'Only identity-cloud is eligible for Go cutover. Endpoint/DNS/proxy stay legacy.',
            ],
        ];
    }

    public static function auditStatus(array $status, string $actor = 'system'): void
    {
        $targetType = 'xdr_correlation_cutover';
        $targetId = (string) ($status['scope'] ?? 'identity-cloud');
        $latest = DB::table('security_audit_trails')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->orderByDesc('occurred_at')
            ->first();

        $previous = null;
        if ($latest?->after_state) {
            $previous = json_decode((string) $latest->after_state, true);
        }

        $current = [
            'configured_engine' => $status['configured_engine'],
            'effective_engine' => $status['effective_engine'],
            'scope' => $status['scope'],
            'fallback_active' => $status['fallback_active'],
            'fallback_reason' => $status['fallback_reason'],
        ];

        if ($previous === $current) {
            return;
        }

        $action = $status['fallback_active']
            ? 'xdr.correlation.rollback_auto'
            : 'xdr.correlation.cutover_state_changed';

        AuditLogger::log($actor, $action, $targetType, $targetId, $previous, $current, [
            'go_worker' => $status['go_worker'],
            'cutover_gate' => $status['cutover_gate'],
            'monitoring' => $status['monitoring'],
        ]);
    }

    private static function validEngine(string $engine): string
    {
        $engine = strtolower(trim($engine));
        return in_array($engine, ['legacy', 'go', 'shadow'], true) ? $engine : 'shadow';
    }

    private static function goHealth(string $workerUrl): array
    {
        if ($workerUrl === '') {
            return ['healthy' => false, 'should_fallback' => true, 'status' => 'not_configured', 'url' => $workerUrl];
        }

        $timeout = max(1.0, (float) config('xdr.correlation.health_timeout_seconds', 5));
        $retries = max(0, (int) config('xdr.correlation.health_retries', 2));
        $sleepMs = max(0, (int) config('xdr.correlation.health_retry_sleep_ms', 150));
        $threshold = max(1, (int) config('xdr.correlation.fallback_failure_threshold', 3));
        $cacheKey = 'xdr:correlation:health_failures:'.sha1($workerUrl);
        $last = null;
        $started = microtime(true);

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout($timeout)
                    ->acceptJson()
                    ->withHeaders(['Connection' => 'keep-alive'])
                    ->get($workerUrl.'/health');

                $latency = round((microtime(true) - $started) * 1000, 2);
                if ($response->successful()) {
                    Cache::forget($cacheKey);

                    return [
                        'healthy' => true,
                        'should_fallback' => false,
                        'status' => 'healthy',
                        'http_status' => $response->status(),
                        'latency_ms' => $latency,
                        'attempts' => $attempt + 1,
                        'failure_count' => 0,
                        'failure_threshold' => $threshold,
                        'url' => $workerUrl,
                        'body' => $response->json() ?? $response->body(),
                    ];
                }

                $last = [
                    'http_status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ];
            } catch (\Throwable $exception) {
                $last = ['error' => $exception->getMessage()];
            }

            if ($attempt < $retries && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $failureCount = (int) Cache::get($cacheKey, 0) + 1;
        Cache::put($cacheKey, $failureCount, now()->addMinutes(30));
        $shouldFallback = $failureCount >= $threshold;

        return [
            'healthy' => ! $shouldFallback,
            'should_fallback' => $shouldFallback,
            'status' => $shouldFallback ? 'offline' : 'transient_unhealthy',
            'url' => $workerUrl,
            'latency_ms' => round((microtime(true) - $started) * 1000, 2),
            'attempts' => $retries + 1,
            'failure_count' => $failureCount,
            'failure_threshold' => $threshold,
            'fallback_reason' => $shouldFallback ? 'go_worker_unhealthy_threshold_exceeded' : null,
            'last_failure' => $last,
        ];
    }

    private static function latestParityReport(): ?array
    {
        $path = base_path((string) config('xdr.correlation.shadow_report_path', 'reports/xdr_correlation_identity_cloud_diff.json'));
        if (!File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function gate(?array $report): array
    {
        if (!$report) {
            return ['passed' => false, 'reason' => 'missing_parity_report'];
        }

        $comparison = $report['comparison'] ?? [];
        $go = $report['go_shadow_correlation'] ?? $report['go_correlation'] ?? [];
        $thresholds = config('xdr.correlation.cutover_gate', []);
        $pyCount = (int) data_get($report, 'python_laravel_correlation.alert_count', 0);
        $goCount = (int) ($go['alert_count'] ?? 0);
        $deltaRatio = abs($goCount - $pyCount) / max($pyCount, 1);
        $duplicateRate = max(
            (float) ($comparison['go_duplicate_rate'] ?? 1),
            (float) ($comparison['python_duplicate_rate'] ?? 1)
        );

        $gates = [
            'alert_type_match' => (float) ($comparison['alert_type_match_rate'] ?? 0) >= (float) ($thresholds['alert_type_match_min'] ?? 0.95),
            'alert_count_delta' => $deltaRatio <= (float) ($thresholds['alert_count_delta_max'] ?? 0.02),
            'evidence_match' => (float) ($comparison['evidence_match_rate'] ?? 0) >= (float) ($thresholds['evidence_match_min'] ?? 0.98),
            'p95_latency' => (float) ($go['p95_latency_ms'] ?? 999999) < (float) ($thresholds['p95_latency_ms_max'] ?? 300),
            'duplicate_rate' => $duplicateRate <= (float) ($thresholds['duplicate_rate_max'] ?? 0),
        ];

        return [
            'passed' => !in_array(false, $gates, true),
            'gates' => $gates,
            'alert_count_delta_ratio' => round($deltaRatio, 4),
            'thresholds' => $thresholds,
            'report_status' => $report['validation_status'] ?? null,
        ];
    }

    private static function monitoring(?array $report, array $goHealth): array
    {
        $comparison = $report['comparison'] ?? [];
        $go = $report['go_shadow_correlation'] ?? $report['go_correlation'] ?? [];
        $latestStream = DB::table('xdr_stream_metrics')->orderByDesc('measured_at')->first();

        return [
            'alert_count_delta' => $comparison['alert_count_delta'] ?? null,
            'p95_latency_ms' => $go['p95_latency_ms'] ?? null,
            'error_rate' => $goHealth['healthy'] ? 0 : 1,
            'worker_health' => $goHealth['status'] ?? 'unknown',
            'stream_lag' => $latestStream?->partition_lag,
            'latest_report_status' => $report['validation_status'] ?? null,
        ];
    }
}
