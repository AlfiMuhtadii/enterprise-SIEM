<?php

namespace App\Http\Controllers;

use App\Support\XdrOperationalMetrics;
use App\Support\XdrCorrelationCutover;
use App\Support\XdrSoakReport;
use App\Support\XdrSeparatedServiceMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class OpsHealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['ok' => true, 'service' => 'detector-soc', 'ts' => now()->toIso8601String()]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => false,
            'storage' => is_writable(storage_path()),
        ];
        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }

        $ok = !in_array(false, $checks, true);

        return response()->json(['ok' => $ok, 'checks' => $checks, 'ts' => now()->toIso8601String()], $ok ? 200 : 503);
    }

    public function service(string $service): JsonResponse
    {
        $definition = config("xdr.services.{$service}");
        abort_if(!$definition, 404);

        $checks = [
            'database' => false,
            'storage' => is_writable(storage_path()),
            'service_configured' => true,
        ];
        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }
        $ok = !in_array(false, $checks, true);

        DB::table('xdr_service_health')->insert([
            'service_name' => $service,
            'status' => $ok ? 'healthy' : 'degraded',
            'checks' => json_encode($checks),
            'metrics' => json_encode([
                'produces' => $definition['produces'] ?? [],
                'consumes' => $definition['consumes'] ?? [],
            ]),
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => $ok,
            'service' => $service,
            'responsibility' => $definition['responsibility'],
            'produces' => $definition['produces'] ?? [],
            'consumes' => $definition['consumes'] ?? [],
            'checks' => $checks,
            'ts' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    public function metrics(): JsonResponse
    {
        $latestTelemetry = DB::table('telemetry_events')->max('ts');
        $latestAlert = DB::table('security_alerts')->max('detected_at');
        $heartbeatPath = storage_path('app/scheduler_heartbeat.json');
        $heartbeat = File::exists($heartbeatPath) ? json_decode(File::get($heartbeatPath), true) : null;

        $notificationMetrics = DB::table('notification_delivery_logs')
            ->select('target_type', 'status', DB::raw('count(*) as total'))
            ->where('attempted_at', '>=', now()->subDay())
            ->groupBy('target_type', 'status')
            ->get();

        return response()->json([
            'application' => [
                'env' => app()->environment(),
                'debug' => config('app.debug'),
                'now' => now()->toIso8601String(),
            ],
            'scheduler' => [
                'last_run' => $heartbeat['last_run'] ?? null,
                'status' => $heartbeat['status'] ?? 'unknown',
            ],
            'ingestion' => [
                'latest_telemetry_at' => $latestTelemetry,
                'telemetry_lag_seconds' => $latestTelemetry ? now()->diffInSeconds($latestTelemetry) : null,
                'latest_alert_at' => $latestAlert,
                'events_24h' => DB::table('telemetry_events')->where('ts', '>=', now()->subDay())->count(),
                'alerts_24h' => DB::table('security_alerts')->where('detected_at', '>=', now()->subDay())->count(),
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'notifications' => $notificationMetrics,
            'incidents' => [
                'open' => DB::table('security_incidents')->whereIn('status', ['open', 'triaged', 'investigating'])->count(),
                'overdue' => DB::table('security_incidents')->whereIn('status', ['open', 'triaged', 'investigating'])->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
            ],
            'agents' => [
                'total' => DB::table('endpoint_agents')->count(),
                'online' => DB::table('endpoint_agents')->where('last_seen_at', '>=', now()->subSeconds((int) config('soc.agent_offline_after_seconds', 180)))->count(),
                'stale' => DB::table('endpoint_agents')->where(function ($q) {
                    $q->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', now()->subSeconds((int) config('soc.agent_offline_after_seconds', 180)));
                })->count(),
                'delivery_failures_24h' => DB::table('agent_delivery_failures')->where('failed_at', '>=', now()->subDay())->count(),
                'queued_commands' => DB::table('agent_commands')->whereIn('status', ['queued', 'sent', 'retry'])->count(),
                'upgrade_available' => DB::table('endpoint_agents')->where('upgrade_status', 'upgrade_available')->count(),
            ],
            'xdr_distributed' => [
                'services' => XdrOperationalMetrics::serviceHealth(),
                'streams' => XdrOperationalMetrics::streamSummary(),
                'storage' => XdrOperationalMetrics::storageSummary(),
                'maturity' => XdrOperationalMetrics::maturitySummary(),
                'correlation_cutover' => XdrCorrelationCutover::status(),
                'correlation_soak' => XdrSoakReport::latest(),
                'separated_services' => XdrSeparatedServiceMetrics::summary(),
                'latest_validation' => DB::table('xdr_validation_runs')->orderByDesc('completed_at')->first(),
            ],
        ]);
    }
}
