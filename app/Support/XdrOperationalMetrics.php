<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class XdrOperationalMetrics
{
    public static function serviceHealth(): array
    {
        $services = config('xdr.services', []);
        $rows = DB::table('xdr_service_health')
            ->select('service_name', 'status', 'checked_at', 'checks', 'metrics')
            ->whereIn('id', function ($query) {
                $query->selectRaw('max(id)')
                    ->from('xdr_service_health')
                    ->groupBy('service_name');
            })
            ->get()
            ->keyBy('service_name');

        return collect($services)->map(function ($definition, $name) use ($rows) {
            $latest = $rows->get($name);
            return [
                'service' => $name,
                'status' => $latest->status ?? 'not_reported',
                'responsibility' => $definition['responsibility'] ?? '',
                'produces' => $definition['produces'] ?? [],
                'consumes' => $definition['consumes'] ?? [],
                'checked_at' => $latest->checked_at ?? null,
                'checks' => $latest ? json_decode($latest->checks ?: '{}', true) : [],
                'metrics' => $latest ? json_decode($latest->metrics ?: '{}', true) : [],
            ];
        })->values()->all();
    }

    public static function streamSummary(): array
    {
        $topics = config('xdr.topics', []);
        $rows = DB::table('xdr_stream_metrics')
            ->whereIn('id', function ($query) {
                $query->selectRaw('max(id)')
                    ->from('xdr_stream_metrics')
                    ->groupBy('topic', 'consumer_group');
            })
            ->orderBy('topic')
            ->get();

        return [
            'topics_configured' => array_keys($topics),
            'latest_metrics' => $rows,
            'total_lag' => (int) $rows->sum('consumer_lag'),
            'dlq_total' => (int) $rows->sum('dead_letter_count'),
            'avg_latency_ms' => round((float) $rows->avg('avg_processing_latency_ms'), 2),
        ];
    }

    public static function storageSummary(): array
    {
        $stores = config('xdr.storage', []);
        $rows = DB::table('xdr_storage_health')
            ->whereIn('id', function ($query) {
                $query->selectRaw('max(id)')
                    ->from('xdr_storage_health')
                    ->groupBy('store_name');
            })
            ->get()
            ->keyBy('store_name');

        return collect($stores)->map(function ($definition, $name) use ($rows) {
            $latest = $rows->get($name);
            return [
                'store' => $name,
                'driver' => $definition['driver'] ?? 'unknown',
                'retention_days' => $definition['retention_days'] ?? null,
                'status' => $latest->status ?? 'not_reported',
                'query_latency_ms' => $latest->query_latency_ms ?? null,
                'checked_at' => $latest->checked_at ?? null,
                'metrics' => $latest ? json_decode($latest->metrics ?: '{}', true) : [],
            ];
        })->values()->all();
    }

    public static function maturitySummary(): array
    {
        $latestValidation = DB::table('xdr_validation_runs')->orderByDesc('completed_at')->first();
        $quality = $latestValidation ? json_decode($latestValidation->quality_metrics ?: '{}', true) : [];
        $throughput = $latestValidation ? json_decode($latestValidation->throughput_metrics ?: '{}', true) : [];

        return [
            'stream' => [
                'warning_topics' => DB::table('xdr_stream_reliability_metrics')->where('status', 'warning')->where('measured_at', '>=', now()->subDay())->count(),
                'max_saturation' => round((float) DB::table('xdr_stream_reliability_metrics')->where('measured_at', '>=', now()->subDay())->max('saturation_ratio'), 4),
                'max_partition_lag' => (int) DB::table('xdr_stream_reliability_metrics')->where('measured_at', '>=', now()->subDay())->max('partition_lag'),
            ],
            'validation' => [
                'latest_dataset' => $latestValidation->dataset_name ?? null,
                'correlation_accuracy' => $quality['correlation_accuracy'] ?? null,
                'ingestion_eps' => $throughput['ingestion_eps'] ?? null,
                'degradation_warnings' => $quality['degradation_warnings'] ?? [],
            ],
            'rules' => [
                'rules_total' => DB::table('xdr_detection_rule_maturity')->count(),
                'production_rules' => DB::table('xdr_detection_rule_maturity')->where('environment', 'production')->count(),
                'avg_quality_score' => round((float) DB::table('xdr_detection_rule_maturity')->avg('quality_score'), 3),
                'drift_warnings' => DB::table('xdr_detection_rule_maturity')->whereRaw("(drift_metrics->>'rule_drift_score')::float >= 0.12")->count(),
            ],
            'identity' => [
                'tracked_users' => DB::table('xdr_identity_risk_timelines')->where('created_at', '>=', now()->subDay())->distinct('user_key')->count('user_key'),
                'high_risk_users' => DB::table('xdr_identity_risk_timelines')->where('created_at', '>=', now()->subDay())->where('risk_score', '>=', 0.7)->count(),
                'max_risk_score' => round((float) DB::table('xdr_identity_risk_timelines')->where('created_at', '>=', now()->subDay())->max('risk_score'), 3),
            ],
            'attack_reconstruction' => [
                'campaigns_24h' => DB::table('xdr_attack_reconstructions')->where('created_at', '>=', now()->subDay())->count(),
                'avg_chain_confidence' => round((float) DB::table('xdr_attack_reconstructions')->where('created_at', '>=', now()->subDay())->avg('chain_confidence'), 3),
            ],
            'storage_maturity' => [
                'tiers_reported' => DB::table('xdr_storage_maturity_metrics')->where('measured_at', '>=', now()->subDay())->distinct('tier')->count('tier'),
                'estimated_monthly_cost_usd' => round((float) DB::table('xdr_storage_maturity_metrics')->where('measured_at', '>=', now()->subDay())->sum('estimated_monthly_cost_usd'), 3),
            ],
            'recovery' => [
                'latest_status' => optional(DB::table('xdr_recovery_reports')->orderByDesc('completed_at')->first())->status,
                'warnings_24h' => DB::table('xdr_recovery_reports')->where('created_at', '>=', now()->subDay())->whereRaw("jsonb_array_length(COALESCE(warnings, '[]'::jsonb)) > 0")->count(),
            ],
            'service_extraction' => [
                'extracted_services' => collect(config('xdr.services', []))
                    ->filter(fn ($service, $name) => in_array($name, ['ingestion-gateway', 'telemetry-normalizer', 'xdr-correlation', 'alert-writer', 'incident-builder', 'ai-rag'], true))
                    ->count(),
                'healthy_extracted_services' => DB::table('xdr_service_health')
                    ->whereIn('service_name', ['ingestion-gateway', 'telemetry-normalizer', 'xdr-correlation', 'alert-writer', 'incident-builder', 'ai-rag'])
                    ->whereIn('id', function ($query) {
                        $query->selectRaw('max(id)')
                            ->from('xdr_service_health')
                            ->groupBy('service_name');
                    })
                    ->where('status', 'healthy')
                    ->count(),
            ],
        ];
    }
}
