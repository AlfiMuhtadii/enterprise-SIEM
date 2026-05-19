<?php

namespace App\Services;

use App\Models\QueueLagMetric;
use App\Models\ServiceHealthSnapshot;
use App\Models\StreamPressureMetric;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operations Health Service — observability and degraded-mode visibility.
 *
 * Operational hardening and recovery tooling only. No autonomous infrastructure mutation.
 * Read-only health checks. Append-only metric snapshots.
 * No auto-healing. No infrastructure mutation.
 */
class OperationsHealthService
{
    // -----------------------------------------------------------------------
    // Service health checks — non-destructive probes
    // -----------------------------------------------------------------------

    /**
     * Record a health snapshot for a service.
     * Advisory — records observation, never triggers remediation.
     */
    public function recordServiceHealth(
        string  $service,
        string  $state,
        ?int    $responseMs = null,
        ?string $errorMessage = null,
        array   $details = []
    ): ServiceHealthSnapshot {
        return ServiceHealthSnapshot::create([
            'service_name'     => $service,
            'health_state'     => in_array($state, ServiceHealthSnapshot::STATES, true)
                ? $state : ServiceHealthSnapshot::STATE_UNKNOWN,
            'response_time_ms' => $responseMs,
            'error_message'    => $errorMessage ? substr($errorMessage, 0, 500) : null,
            'dependencies_ok'  => $state === ServiceHealthSnapshot::STATE_HEALTHY,
            'details'          => $details,
            'trace_id'         => 'ops-' . Str::uuid(),
        ]);
    }

    /**
     * Probe all platform services (non-destructive).
     * Uses DB connectivity checks and table existence as proxy health signals.
     */
    public function probeAllServices(): array
    {
        $results = [];

        // PostgreSQL probe
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = (int) ((microtime(true) - $start) * 1000);
            $results['postgresql'] = $this->recordServiceHealth('postgresql', 'healthy', $ms);
        } catch (\Throwable $e) {
            $results['postgresql'] = $this->recordServiceHealth('postgresql', 'down', null, $e->getMessage());
        }

        // Table existence proxy checks for Laravel SOC
        foreach (['security_alerts', 'security_incidents', 'endpoint_agents', 'endpoint_stream_events'] as $table) {
            try {
                DB::table($table)->count();
            } catch (\Throwable) {
                $this->recordServiceHealth('laravel-soc', 'degraded', null, "Table {$table} not accessible");
            }
        }
        $results['laravel-soc'] = $this->recordServiceHealth('laravel-soc', 'healthy', 0);

        return $results;
    }

    // -----------------------------------------------------------------------
    // Queue lag metrics
    // -----------------------------------------------------------------------

    public function recordQueueLag(
        string $consumerGroup,
        string $topic,
        int    $partition,
        int    $lagCount,
        int    $lagAgeSeconds
    ): QueueLagMetric {
        $prevLag = QueueLagMetric::where('consumer_group', $consumerGroup)
            ->where('topic', $topic)
            ->where('partition', $partition)
            ->orderByDesc('id')
            ->value('lag_count') ?? 0;

        $trend = match(true) {
            $lagCount > $prevLag + 100 => QueueLagMetric::TREND_INCREASING,
            $lagCount < $prevLag - 100 => QueueLagMetric::TREND_DECREASING,
            default                    => QueueLagMetric::TREND_STABLE,
        };

        return QueueLagMetric::create([
            'consumer_group'  => $consumerGroup,
            'topic'           => $topic,
            'partition'       => $partition,
            'lag_count'       => $lagCount,
            'lag_age_seconds' => min($lagAgeSeconds, 65535),
            'trend'           => $trend,
        ]);
    }

    // -----------------------------------------------------------------------
    // Stream pressure metrics
    // -----------------------------------------------------------------------

    public function recordStreamPressure(
        string $metricType,
        string $serviceName,
        float  $value,
        string $unit = 'count',
        ?float $warnThreshold  = null,
        ?float $critThreshold  = null
    ): StreamPressureMetric {
        $state = StreamPressureMetric::classifyState($value, $warnThreshold, $critThreshold);

        return StreamPressureMetric::create([
            'metric_type'        => $metricType,
            'service_name'       => $serviceName,
            'value'              => $value,
            'unit'               => $unit,
            'threshold_warning'  => $warnThreshold,
            'threshold_critical' => $critThreshold,
            'state'              => $state,
            'trace_id'           => 'ops-' . Str::uuid(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Composite health scoring — deterministic
    // -----------------------------------------------------------------------

    /**
     * Returns a health score 0.0–1.0 for the overall platform.
     * Lower = worse.
     */
    public function scoreOverallHealth(): float
    {
        $snapshots = $this->getLatestHealthSnapshots();
        if ($snapshots->isEmpty()) {
            return 0.5; // unknown state
        }

        $healthyCount  = $snapshots->where('health_state', 'healthy')->count();
        $degradedCount = $snapshots->where('health_state', 'degraded')->count();
        $downCount     = $snapshots->where('health_state', 'down')->count();
        $total         = $snapshots->count();

        $score = ($healthyCount - ($degradedCount * 0.5) - ($downCount * 1.0)) / max($total, 1);
        return (float) round(max(0.0, min(1.0, $score)), 2);
    }

    // -----------------------------------------------------------------------
    // Degraded-mode visibility
    // -----------------------------------------------------------------------

    public function getDegradedServices(): Collection
    {
        return $this->getLatestHealthSnapshots()
            ->whereIn('health_state', ['degraded', 'down', 'unknown']);
    }

    public function getOperationalWarnings(): array
    {
        $warnings = [];

        // Check queue lag
        $criticalLag = QueueLagMetric::where('lag_count', '>', QueueLagMetric::LAG_CRITICAL_COUNT)
            ->orderByDesc('id')
            ->take(5)
            ->get();
        foreach ($criticalLag as $lag) {
            $warnings[] = [
                'severity' => 'critical',
                'type'     => 'queue_lag',
                'message'  => "Critical queue lag: {$lag->consumer_group}/{$lag->topic} at {$lag->lag_count} messages",
                'at'       => $lag->created_at,
            ];
        }

        // Check stream pressure
        $criticalPressure = StreamPressureMetric::where('state', StreamPressureMetric::STATE_CRITICAL)
            ->orderByDesc('id')
            ->take(5)
            ->get();
        foreach ($criticalPressure as $p) {
            $warnings[] = [
                'severity' => 'critical',
                'type'     => 'stream_pressure',
                'message'  => "Critical {$p->metric_type} on {$p->service_name}: {$p->value} {$p->unit}",
                'at'       => $p->created_at,
            ];
        }

        // Check degraded services
        foreach ($this->getDegradedServices() as $svc) {
            $warnings[] = [
                'severity' => $svc->health_state === 'down' ? 'critical' : 'warning',
                'type'     => 'service_health',
                'message'  => "Service {$svc->service_name} is {$svc->health_state}" .
                    ($svc->error_message ? ": {$svc->error_message}" : ''),
                'at'       => $svc->created_at,
            ];
        }

        usort($warnings, fn ($a, $b) => $b['severity'] <=> $a['severity']);
        return $warnings;
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function getLatestHealthSnapshots(): Collection
    {
        return DB::table('service_health_snapshots as s1')
            ->whereNotExists(function ($q) {
                $q->from('service_health_snapshots as s2')
                  ->whereColumn('s2.service_name', 's1.service_name')
                  ->whereColumn('s2.id', '>', 's1.id');
            })
            ->get()
            ->map(fn ($row) => (object) $row);
    }

    public function getServiceHealthHistory(string $service, int $limit = 50): Collection
    {
        return ServiceHealthSnapshot::where('service_name', $service)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function getRecentQueueLag(int $limit = 50): Collection
    {
        return QueueLagMetric::orderByDesc('id')->limit($limit)->get();
    }

    public function getRecentStreamPressure(int $limit = 50): Collection
    {
        return StreamPressureMetric::orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Build service dependency graph for deployment visibility.
     * Read-only structural metadata — no infrastructure probes.
     */
    public function buildDependencyGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'laravel-soc',            'type' => 'app',         'role' => 'control-plane'],
                ['id' => 'ingestion-gateway',       'type' => 'service',     'role' => 'ingest'],
                ['id' => 'normalizer-worker',       'type' => 'service',     'role' => 'normalize'],
                ['id' => 'correlation-worker',      'type' => 'service',     'role' => 'correlate'],
                ['id' => 'alert-writer-service',    'type' => 'service',     'role' => 'persist'],
                ['id' => 'incident-builder-service','type' => 'service',     'role' => 'aggregate'],
                ['id' => 'ai-rag-service',          'type' => 'service',     'role' => 'analytics'],
                ['id' => 'postgresql',              'type' => 'infra',       'role' => 'database'],
                ['id' => 'redpanda',                'type' => 'infra',       'role' => 'streaming'],
                ['id' => 'opensearch',              'type' => 'infra',       'role' => 'indexing'],
                ['id' => 'clickhouse',              'type' => 'infra',       'role' => 'analytics-db'],
                ['id' => 'qdrant',                  'type' => 'infra',       'role' => 'vector-store'],
                ['id' => 'grafana',                 'type' => 'infra',       'role' => 'observability'],
            ],
            'edges' => [
                ['from' => 'ingestion-gateway',        'to' => 'redpanda',              'label' => 'telemetry.raw'],
                ['from' => 'normalizer-worker',        'to' => 'redpanda',              'label' => 'telemetry.normalized'],
                ['from' => 'correlation-worker',       'to' => 'redpanda',              'label' => 'xdr.alerts'],
                ['from' => 'alert-writer-service',     'to' => 'postgresql',            'label' => 'security_alerts'],
                ['from' => 'alert-writer-service',     'to' => 'opensearch',            'label' => 'alert-indexing'],
                ['from' => 'incident-builder-service', 'to' => 'postgresql',            'label' => 'security_incidents'],
                ['from' => 'laravel-soc',              'to' => 'postgresql',            'label' => 'control-plane-rw'],
                ['from' => 'ai-rag-service',           'to' => 'qdrant',                'label' => 'vector-store'],
                ['from' => 'correlation-worker',       'to' => 'clickhouse',            'label' => 'analytics'],
                ['from' => 'grafana',                  'to' => 'postgresql',            'label' => 'metrics-source'],
            ],
        ];
    }
}
