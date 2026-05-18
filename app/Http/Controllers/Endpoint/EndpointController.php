<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EndpointController extends Controller
{
    private const OFFLINE_AFTER_SECONDS = 180;

    private const ENDPOINT_ALERT_TYPES = [
        'suspicious_parent_child_process',
        'powershell_encoded_command',
        'suspicious_temp_file_write',
        'failed_login_burst',
        'suspicious_dns_query',
        'suspicious_outbound_connection',
        'scheduled_task_persistence',
        'new_service_persistence',
        'c2_beacon_pattern',
    ];

    // -----------------------------------------------------------------------
    // Inventory — all enrolled agents with risk summary
    // -----------------------------------------------------------------------

    public function index(Request $request): View
    {
        $statusFilter = $request->get('status');
        $search       = trim((string) $request->get('q', ''));

        $agents = DB::table('endpoint_agents')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(function ($agent) {
                $meta   = json_decode($agent->metadata ?? '{}', true) ?? [];
                $online = $agent->last_seen_at
                    && now()->diffInSeconds($agent->last_seen_at) <= self::OFFLINE_AFTER_SECONDS;
                $agent->status          = $online ? 'online' : 'offline';
                $agent->integrity_ok    = (bool) ($meta['startup_integrity_ok'] ?? true);
                $agent->restarts        = (int) ($meta['unexpected_restart_count'] ?? 0);
                $agent->platform        = $meta['platform'] ?? null;
                return $agent;
            });

        if ($statusFilter) {
            $agents = $agents->filter(fn ($a) => $a->status === $statusFilter);
        }
        if ($search !== '') {
            $agents = $agents->filter(
                fn ($a) => str_contains(strtolower($a->host_id ?? ''), strtolower($search))
                    || str_contains(strtolower($a->agent_id ?? ''), strtolower($search))
            );
        }

        // Shadow alert counts per host
        $alertCounts = DB::table('security_alerts')
            ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
            ->select('actor_key', DB::raw('count(*) as alert_count'))
            ->groupBy('actor_key')
            ->get()
            ->keyBy('actor_key');

        $telemetryVolume = DB::table('xdr_operational_events')
            ->where('source_service', 'LIKE', '%endpoint%')
            ->selectRaw("DATE(occurred_at) as day, count(*) as cnt")
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit(7)
            ->get();

        $stats = [
            'total'         => $agents->count(),
            'online'        => $agents->where('status', 'online')->count(),
            'offline'       => $agents->where('status', 'offline')->count(),
            'integrity_fail'=> $agents->where('integrity_ok', false)->count(),
            'shadow_alerts' => DB::table('security_alerts')
                ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)->count(),
            'critical_hosts'=> DB::table('security_alerts')
                ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
                ->where('severity', 'critical')
                ->distinct('actor_key')->count('actor_key'),
        ];

        return view('endpoint.index', compact(
            'agents', 'alertCounts', 'telemetryVolume', 'stats', 'statusFilter', 'search'
        ));
    }

    // -----------------------------------------------------------------------
    // Host detail — timeline of shadow alerts + pipeline events
    // -----------------------------------------------------------------------

    public function show(string $agentId): View
    {
        $agent = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        abort_if(!$agent, 404);

        $meta         = json_decode($agent->metadata ?? '{}', true) ?? [];
        $online       = $agent->last_seen_at
            && now()->diffInSeconds($agent->last_seen_at) <= self::OFFLINE_AFTER_SECONDS;
        $agent->status     = $online ? 'online' : 'offline';
        $agent->platform   = $meta['platform'] ?? null;
        $agent->integrity  = (bool) ($meta['startup_integrity_ok'] ?? true);

        // Shadow alerts for this host (match by actor_key or host_id)
        $alerts = DB::table('security_alerts')
            ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
            ->where(function ($q) use ($agent) {
                $q->where('actor_key', $agent->host_id)
                  ->orWhere('ip', 'LIKE', '%' . substr($agent->host_id ?? '', 0, 12) . '%');
            })
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        // All shadow alerts (show even without host match for now)
        if ($alerts->isEmpty()) {
            $alerts = DB::table('security_alerts')
                ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
                ->orderByDesc('detected_at')
                ->limit(50)
                ->get();
        }

        // Operational events for this agent's trace_ids
        $traceIds = $alerts->pluck('trace_id')->filter()->unique()->take(20)->values();
        $pipelineEvents = $traceIds->isNotEmpty()
            ? DB::table('xdr_operational_events')
                ->whereIn('trace_id', $traceIds)
                ->orderByDesc('occurred_at')
                ->limit(200)
                ->get()
            : collect();

        // Alert rule breakdown
        $byRule = $alerts->groupBy('alert_type')
            ->map(fn ($g) => ['count' => $g->count(), 'severity' => $g->max('severity')]);

        // Commands queued for this agent
        $commands = DB::table('agent_commands')
            ->where('agent_id', $agentId)
            ->orderByDesc('queued_at')
            ->limit(20)
            ->get();

        // Policy
        $policy = $agent->policy_id
            ? DB::table('agent_policies')->where('policy_id', $agent->policy_id)->first()
            : null;

        $stats = [
            'alert_count'    => $alerts->count(),
            'critical_alerts'=> $alerts->where('severity', 'critical')->count(),
            'rules_fired'    => $byRule->count(),
            'pipeline_events'=> $pipelineEvents->count(),
        ];

        return view('endpoint.show', compact(
            'agent', 'alerts', 'pipelineEvents', 'byRule', 'commands', 'policy', 'stats'
        ));
    }

    // -----------------------------------------------------------------------
    // Agent health summary (API)
    // -----------------------------------------------------------------------

    public function health(): \Illuminate\Http\JsonResponse
    {
        $offlineAfter = self::OFFLINE_AFTER_SECONDS;
        $agents = DB::table('endpoint_agents')->get();
        $online  = $agents->filter(fn ($a) =>
            $a->last_seen_at && now()->diffInSeconds($a->last_seen_at) <= $offlineAfter
        )->count();

        return response()->json([
            'total'         => $agents->count(),
            'online'        => $online,
            'offline'       => $agents->count() - $online,
            'shadow_alerts' => DB::table('security_alerts')
                ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)->count(),
            'alert_types'   => DB::table('security_alerts')
                ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
                ->selectRaw("alert_type, count(*) as cnt")
                ->groupBy('alert_type')
                ->orderByDesc('cnt')
                ->get(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Trace view — endpoint alerts for a trace_id
    // -----------------------------------------------------------------------

    public function traceView(string $traceId): View
    {
        $alerts = DB::table('security_alerts')
            ->where('trace_id', $traceId)
            ->whereIn('alert_type', self::ENDPOINT_ALERT_TYPES)
            ->orderBy('detected_at')
            ->get();

        $opEvents = DB::table('xdr_operational_events')
            ->where('trace_id', $traceId)
            ->orderBy('occurred_at')
            ->get();

        $evidence = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->orderBy('processed_at')
            ->get();

        return view('endpoint.trace', compact('traceId', 'alerts', 'opEvents', 'evidence'));
    }
}
