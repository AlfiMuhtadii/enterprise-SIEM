<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class XdrSeparatedServiceMetrics
{
    public static function summary(): array
    {
        return [
            'alert_writer' => self::service('alert-writer'),
            'incident_builder' => self::service('incident-builder'),
            'ai_rag' => self::service('ai-rag'),
            'endpoint_dns_proxy_shadow' => self::shadowPrep(),
        ];
    }

    private static function service(string $name): array
    {
        $definition = config("xdr.services.{$name}", []);
        $url = rtrim((string) ($definition['runtime_url'] ?? ''), '/');
        if ($url === '') {
            return ['status' => 'not_configured', 'metrics' => []];
        }

        $health = self::get($url.'/health');
        $metrics = self::get($url.'/metrics');

        return [
            'status' => isset($health['error']) ? 'offline' : ($health['status'] ?? 'unknown'),
            'url' => $url,
            'health' => $health,
            'metrics' => $metrics,
        ];
    }

    private static function get(string $url): array
    {
        try {
            return Http::timeout(2)->get($url)->json() ?? [];
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    private static function shadowPrep(): array
    {
        $path = base_path('reports/xdr_endpoint_dns_proxy_shadow_prep.json');
        if (!File::exists($path)) {
            return [
                'status' => 'not_run',
                'report_path' => 'reports/xdr_endpoint_dns_proxy_shadow_prep.json',
                'cutover_allowed' => false,
            ];
        }

        $report = json_decode(File::get($path), true) ?: [];
        return [
            'status' => $report['validation_status'] ?? 'unknown',
            'report_path' => 'reports/xdr_endpoint_dns_proxy_shadow_prep.json',
            'cutover_allowed' => false,
            'scope' => $report['scope'] ?? 'endpoint-dns-proxy',
            'alert_count' => $report['alert_count'] ?? 0,
            'p95_latency_ms' => $report['p95_latency_ms'] ?? null,
            'required_before_cutover' => $report['required_before_cutover'] ?? [],
        ];
    }
}
