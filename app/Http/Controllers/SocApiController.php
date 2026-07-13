<?php

namespace App\Http\Controllers;

use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocApiController extends Controller
{
    public function __construct(private readonly TenantContextAuthority $tenantAuthority) {}

    public function stats(Request $request): JsonResponse
    {
        $minutes  = max(15, min(10080, (int) $request->query('minutes', 1440)));
        $since    = now()->subMinutes($minutes);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $scopeI   = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;
        $scopeA   = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;

        return response()->json([
            'incidents_by_status'   => $scopeI(DB::table('security_incidents'))->select('status', DB::raw('count(*) as total'))->where('last_seen_at', '>=', $since)->groupBy('status')->get(),
            'incidents_by_severity' => $scopeI(DB::table('security_incidents'))->select('severity', DB::raw('count(*) as total'))->where('last_seen_at', '>=', $since)->groupBy('severity')->get(),
            'alert_distribution'    => $scopeA(DB::table('security_alerts'))->select('alert_type', DB::raw('count(*) as total'))->where('detected_at', '>=', $since)->groupBy('alert_type')->orderByDesc('total')->limit(20)->get(),
            'quality_history'       => DB::table('detection_quality_history')->orderByDesc('measured_at')->limit(20)->get(),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $limit    = max(1, min(200, (int) $request->query('limit', 50)));
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $query    = DB::table('security_incidents')->orderByDesc('last_seen_at')->limit($limit);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        return response()->json($query->get());
    }

    public function incident(Request $request, string $incidentId): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $incident = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        if (!$incident) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($tenantId !== null && (string) ($incident->tenant_id ?? '') !== $tenantId) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json([
            'incident'   => $incident,
            'alerts'     => DB::table('security_alerts')->where('incident_id', $incidentId)->orderByDesc('detected_at')->get(),
            'notes'      => DB::table('security_incident_notes')->where('incident_id', $incidentId)->orderByDesc('created_at')->get(),
            'activities' => DB::table('security_incident_activities')->where('incident_id', $incidentId)->orderByDesc('created_at')->get(),
            'audit'      => DB::table('security_audit_trails')->where('target_type', 'incident')->where('target_id', $incidentId)->orderByDesc('occurred_at')->get(),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $limit    = max(1, min(500, (int) $request->query('limit', 100)));
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $query    = DB::table('security_alerts')->whereRaw('COALESCE(is_suppressed, false)=false')->orderByDesc('detected_at')->limit($limit);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        return response()->json($query->get());
    }

    public function audit(Request $request): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query('limit', 100)));
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);

        return response()->json(
            DB::table('security_audit_trails')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
        );
    }

    public function benchmarks(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        return response()->json(DB::table('detection_quality_history')->orderByDesc('measured_at')->limit($limit)->get());
    }

    public function agents(Request $request): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query('limit', 100)));
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);
        $agents = DB::table('endpoint_agents')
            ->select('agent_id', 'host_id', 'host_fingerprint', 'agent_version', 'os_family', 'status', 'policy_id', 'policy_version_seen', 'upgrade_status', 'target_version', 'last_seen_at', 'event_count_total', 'error_count_total', 'last_batch_event_count', 'retry_queue_depth', 'last_error', 'metadata')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(function ($agent) use ($offlineAfter) {
                $agent->computed_status = \App\Services\EndpointAgentService::computeStatus($agent->last_seen_at, $offlineAfter);
                return $agent;
            });

        return response()->json($agents);
    }

    public function workflow(Request $request, string $incidentId): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'status' => ['nullable', 'in:open,triaged,investigating,resolved,false_positive'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'assigned_analyst' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        abort_if(!$before, 404);

        $updates = ['updated_at' => now()];
        if (isset($data['status'])) {
            $updates['status'] = $data['status'];
            if ($data['status'] === 'resolved' || $data['status'] === 'false_positive') {
                $updates['resolved_at'] = now();
            }
        }
        if (isset($data['severity'])) {
            $updates['severity'] = $data['severity'];
        }
        if (isset($data['assigned_analyst'])) {
            $updates['assigned_analyst'] = $data['assigned_analyst'];
        }
        if (isset($data['resolution_summary'])) {
            $updates['resolution_summary'] = $data['resolution_summary'];
        }
        if ($data['action'] === 'escalate') {
            $updates['escalation_level'] = DB::raw('escalation_level + 1');
        }

        DB::table('security_incidents')->where('incident_id', $incidentId)->update($updates);

        if (!empty($data['note'])) {
            DB::table('security_incident_notes')->insert([
                'incident_id' => $incidentId,
                'author' => $request->user()->email,
                'note_type' => $data['action'],
                'body' => $data['note'],
                'metadata' => json_encode(['source' => 'soc-api']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $after = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        DB::table('security_incident_activities')->insert([
            'incident_id' => $incidentId,
            'actor' => $request->user()->email,
            'activity_type' => $data['action'],
            'before_state' => json_encode($before),
            'after_state' => json_encode($after),
            'metadata' => json_encode(['source' => 'soc-api']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'incident.'.$data['action'], 'incident', $incidentId, $before, $after);

        return response()->json(['ok' => true, 'incident' => $after]);
    }
}
