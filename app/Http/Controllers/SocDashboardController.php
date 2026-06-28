<?php

namespace App\Http\Controllers;

use App\Services\TenantContextAuthority;
use App\Support\XdrOperationalMetrics;
use App\Support\XdrSoakReport;
use App\Support\XdrSeparatedServiceMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SocDashboardController extends Controller
{
    public function __construct(private readonly TenantContextAuthority $tenantAuthority) {}

    public function __invoke(Request $request): View
    {
        $minutes  = max(15, min(10080, (int) $request->query('minutes', 1440)));
        $q        = trim((string) $request->query('q', ''));
        $status   = trim((string) $request->query('status', ''));
        $severity = trim((string) $request->query('severity', ''));
        $since    = now()->subMinutes($minutes);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);

        $scopeIncidents = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;
        $scopeAlerts    = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;

        $incidentsQuery = $scopeIncidents(DB::table('security_incidents'))->where('last_seen_at', '>=', $since);
        if ($q !== '') {
            $incidentsQuery->where(function ($inner) use ($q) {
                $inner->where('incident_id', 'ilike', "%{$q}%")
                    ->orWhere('title', 'ilike', "%{$q}%")
                    ->orWhere('assigned_analyst', 'ilike', "%{$q}%");
            });
        }
        if ($status !== '') {
            $incidentsQuery->where('status', $status);
        }
        if ($severity !== '') {
            $incidentsQuery->where('severity', $severity);
        }

        $incidents = (clone $incidentsQuery)
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();

        $recentAlerts = $scopeAlerts(DB::table('security_alerts'))
            ->select('alert_id', 'detected_at', 'alert_type', 'severity', 'ip', 'actor_key', 'incident_id', 'score', 'evidence')
            ->where('detected_at', '>=', $since)
            ->whereRaw('COALESCE(is_suppressed, false)=false')
            ->orderByDesc('detected_at')
            ->limit(80)
            ->get();

        $severitySummary = $scopeIncidents(DB::table('security_incidents'))
            ->select('severity', DB::raw('count(*) as total'))
            ->where('last_seen_at', '>=', $since)
            ->groupBy('severity')
            ->orderByDesc('total')
            ->get();

        $statusSummary = $scopeIncidents(DB::table('security_incidents'))
            ->select('status', DB::raw('count(*) as total'))
            ->where('last_seen_at', '>=', $since)
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $alertTrend = DB::table('security_alerts')
            ->select(DB::raw("date_trunc('hour', detected_at) as bucket"), DB::raw('count(*) as total'))
            ->where('detected_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $incidentTrend = DB::table('security_incidents')
            ->select(DB::raw("date_trunc('hour', first_seen_at) as bucket"), DB::raw('count(*) as total'))
            ->where('first_seen_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $topEntities = DB::table('security_incidents')
            ->select('affected_entities')
            ->where('last_seen_at', '>=', $since)
            ->limit(500)
            ->get()
            ->flatMap(function ($row) {
                $entities = json_decode($row->affected_entities ?: '[]', true) ?: [];

                return collect($entities)->map(function ($entity) {
                    if (is_array($entity)) {
                        return ($entity['type'] ?? 'unknown') . ':' . ($entity['value'] ?? 'unknown');
                    }

                    return (string) $entity;
                });
            })
            ->countBy()
            ->sortDesc()
            ->take(10);

        $mitreOverview = DB::table('security_incidents')
            ->select('mitre_mapping')
            ->where('last_seen_at', '>=', $since)
            ->limit(500)
            ->get()
            ->flatMap(function ($row) {
                $items = json_decode($row->mitre_mapping ?: '[]', true) ?: [];

                return collect($items)->map(function ($item) {
                    if (is_array($item)) {
                        return $item['technique_id']
                            ?? $item['technique']
                            ?? $item['id']
                            ?? json_encode($item);
                    }

                    return (string) $item;
                });
            })
            ->filter()
            ->countBy()
            ->sortDesc();

        $qualityHistory = DB::table('detection_quality_history')
            ->select('measured_at', 'metric_type', 'precision', 'recall', 'false_positive_rate', 'avg_detection_latency_sec', 'alert_volume', 'incident_volume')
            ->orderByDesc('measured_at')
            ->limit(20)
            ->get();

        $latestTelemetry = DB::table('telemetry_events')->max('ts');
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);
        $agentRows = DB::table('endpoint_agents')
            ->select('agent_id', 'host_id', 'agent_version', 'os_family', 'last_seen_at', 'event_count_total', 'error_count_total', 'last_batch_event_count', 'last_error')
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->get()
            ->map(function ($agent) use ($offlineAfter) {
                $agent->computed_status = $agent->last_seen_at && now()->diffInSeconds($agent->last_seen_at) <= $offlineAfter ? 'online' : 'offline';
                return $agent;
            });
        $onlineAgents = $agentRows->where('computed_status', 'online')->count();
        $operationalSummary = [
            'queue_connection' => config('queue.default'),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'telemetry_lag_seconds' => $latestTelemetry ? now()->diffInSeconds($latestTelemetry) : null,
            'notification_failures_24h' => DB::table('notification_delivery_logs')->where('attempted_at', '>=', now()->subDay())->where('status', 'failed')->count(),
            'overdue_incidents' => DB::table('security_incidents')->whereIn('status', ['open', 'triaged', 'investigating'])->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
            'agents_online' => $onlineAgents,
            'agents_total' => $agentRows->count(),
        ];

        $timelineSpikeHosts = DB::table('telemetry_events')
            ->select('host_id', DB::raw('count(*) as total'))
            ->where('ts', '>=', now()->subHour())
            ->whereNotNull('host_id')
            ->groupBy('host_id')
            ->havingRaw('count(*) >= 50')
            ->count();

        $investigationSummary = [
            'active_hunts_24h' => DB::table('soc_hunt_run_sessions')->where('started_at', '>=', now()->subDay())->count(),
            'hunt_matches_24h' => (int) DB::table('soc_hunt_run_sessions')->where('started_at', '>=', now()->subDay())->sum('result_count'),
            'endpoint_sessions_24h' => DB::table('endpoint_investigation_sessions')->where('started_at', '>=', now()->subDay())->count(),
            'forensic_pending' => DB::table('forensic_collection_jobs')->where('status', 'pending_approval')->count(),
            'forensic_completed_24h' => DB::table('forensic_collection_jobs')->where('completed_at', '>=', now()->subDay())->count(),
            'timeline_spike_hosts_1h' => $timelineSpikeHosts,
            'agent_integrity_warnings' => DB::table('endpoint_agents')
                ->where(function ($q) {
                    $q->whereRaw("metadata->>'startup_integrity_ok' = 'false'")
                        ->orWhereRaw("COALESCE(NULLIF(metadata->>'unexpected_restart_count', '')::int, 0) > 0");
                })
                ->count(),
        ];

        $aiSummary = [
            'generated_24h' => DB::table('ai_analyst_suggestions')->where('created_at', '>=', now()->subDay())->count(),
            'accepted_7d' => DB::table('ai_analyst_suggestions')->where('status', 'accepted')->where('reviewed_at', '>=', now()->subDays(7))->count(),
            'rejected_7d' => DB::table('ai_analyst_suggestions')->where('status', 'rejected')->where('reviewed_at', '>=', now()->subDays(7))->count(),
            'kb_entries' => DB::table('soc_knowledge_base')->count(),
            'kb_updates_7d' => DB::table('soc_knowledge_base')->where('updated_at', '>=', now()->subDays(7))->count(),
            'quality_warnings_open' => DB::table('detection_quality_warnings')->where('status', 'open')->count(),
            'avg_latency_ms_24h' => (int) DB::table('ai_execution_history')->where('executed_at', '>=', now()->subDay())->avg('latency_ms'),
            'retrieval_citations_24h' => DB::table('ai_analyst_suggestions')->where('created_at', '>=', now()->subDay())->whereNotNull('retrieval_citations')->count(),
            'guardrail_events_7d' => DB::table('ai_guardrail_events')->where('detected_at', '>=', now()->subDays(7))->count(),
            'latest_eval' => optional(DB::table('ai_evaluation_runs')->orderByDesc('evaluated_at')->first())->metrics,
        ];

        $xdrSummary = [
            'cross_domain_incidents' => DB::table('security_incidents')
                ->where('last_seen_at', '>=', $since)
                ->whereRaw("metadata->>'source' = 'xdr_correlation'")
                ->count(),
            'identity_risk' => DB::table('telemetry_events')
                ->where('ts', '>=', $since)
                ->where('telemetry_type', 'identity')
                ->where('risk_score', '>=', 0.7)
                ->count(),
            'cloud_risk' => DB::table('telemetry_events')
                ->where('ts', '>=', $since)
                ->where('telemetry_type', 'cloud')
                ->where('risk_score', '>=', 0.7)
                ->count(),
            'email_threats' => DB::table('telemetry_events')
                ->where('ts', '>=', $since)
                ->where('telemetry_type', 'email')
                ->where('risk_score', '>=', 0.7)
                ->count(),
            'saas_activity' => DB::table('telemetry_events')
                ->where('ts', '>=', $since)
                ->where('telemetry_type', 'saas')
                ->count(),
            'proxy_anomalies' => DB::table('telemetry_events')
                ->where('ts', '>=', $since)
                ->whereIn('telemetry_type', ['firewall', 'proxy'])
                ->where('risk_score', '>=', 0.6)
                ->count(),
        ];

        $xdrDomainBreakdown = DB::table('telemetry_events')
            ->select('telemetry_type', DB::raw('count(*) as total'))
            ->where('ts', '>=', $since)
            ->whereIn('telemetry_type', ['email', 'identity', 'cloud', 'saas', 'firewall', 'proxy'])
            ->groupBy('telemetry_type')
            ->orderByDesc('total')
            ->get();

        $xdrRecent = DB::table('security_alerts')
            ->select('alert_id', 'detected_at', 'alert_type', 'severity', 'actor_key', 'incident_id', 'score', 'evidence')
            ->where('detected_at', '>=', $since)
            ->where('detector_name', 'xdr-correlation')
            ->orderByDesc('detected_at')
            ->limit(8)
            ->get();

        return view('soc.dashboard', [
            'minutes' => $minutes,
            'q' => $q,
            'status' => $status,
            'severity' => $severity,
            'incidents' => $incidents,
            'recentAlerts' => $recentAlerts,
            'severitySummary' => $severitySummary,
            'statusSummary' => $statusSummary,
            'alertTrend' => $alertTrend,
            'incidentTrend' => $incidentTrend,
            'topEntities' => $topEntities,
            'mitreOverview' => $mitreOverview,
            'qualityHistory' => $qualityHistory,
            'operationalSummary' => $operationalSummary,
            'investigationSummary' => $investigationSummary,
            'aiSummary' => $aiSummary,
            'qualityWarnings' => DB::table('detection_quality_warnings')->where('status', 'open')->orderByDesc('detected_at')->limit(8)->get(),
            'aiSuggestions' => DB::table('ai_analyst_suggestions')->orderByDesc('created_at')->limit(8)->get(),
            'aiProviderUsage' => DB::table('ai_execution_history')->select('provider', DB::raw('count(*) as total'))->where('executed_at', '>=', now()->subDays(7))->groupBy('provider')->orderByDesc('total')->get(),
            'aiModelUsage' => DB::table('ai_execution_history')->select('model', DB::raw('count(*) as total'))->where('executed_at', '>=', now()->subDays(7))->groupBy('model')->orderByDesc('total')->limit(6)->get(),
            'aiConfidenceDistribution' => DB::table('ai_analyst_suggestions')->select('confidence_label', DB::raw('count(*) as total'))->where('created_at', '>=', now()->subDays(7))->groupBy('confidence_label')->get(),
            'aiEvalRuns' => DB::table('ai_evaluation_runs')->orderByDesc('evaluated_at')->limit(5)->get(),
            'xdrSummary' => $xdrSummary,
            'xdrDomainBreakdown' => $xdrDomainBreakdown,
            'xdrRecent' => $xdrRecent,
            'xdrDistributed' => [
                'services' => XdrOperationalMetrics::serviceHealth(),
                'streams' => XdrOperationalMetrics::streamSummary(),
                'storage' => XdrOperationalMetrics::storageSummary(),
                'latestValidation' => DB::table('xdr_validation_runs')->orderByDesc('completed_at')->first(),
                'maturity' => XdrOperationalMetrics::maturitySummary(),
                'correlationSoak' => XdrSoakReport::latest(),
                'separatedServices' => XdrSeparatedServiceMetrics::summary(),
            ],
            'agentRows' => $agentRows,
            'totals' => [
                'incidents' => $incidents->total(),
                'alerts' => $recentAlerts->count(),
                'critical' => $severitySummary->firstWhere('severity', 'critical')->total ?? 0,
                'open' => $statusSummary->firstWhere('status', 'open')->total ?? 0,
            ],
        ]);
    }
}
