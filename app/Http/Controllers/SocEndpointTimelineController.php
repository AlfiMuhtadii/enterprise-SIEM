<?php

namespace App\Http\Controllers;

use App\Services\ClickHouseTelemetryReader;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocEndpointTimelineController extends Controller
{
    public function show(Request $request, string $hostId): View
    {
        $minutes = max(15, min(10080, (int) $request->query('minutes', 1440)));
        $type = trim((string) $request->query('type', ''));
        $since = now()->subMinutes($minutes);

        // ARCH-DB-SPLIT (read path): when telemetry writes are routed to
        // ClickHouse, read from the same store instead of Postgres, which
        // has no new rows to show under that mode — falls back to Postgres
        // on any ClickHouse failure so this page never just breaks.
        $telemetry = null;
        if (config('xdr.infrastructure.clickhouse.telemetry_write_target') === 'clickhouse') {
            $telemetry = (new ClickHouseTelemetryReader())->hostTimeline($hostId, $since, $type, 300);
        }
        if ($telemetry === null) {
            $telemetry = DB::table('telemetry_events')
                ->where('host_id', $hostId)
                ->where('ts', '>=', $since)
                ->when($type !== '', fn ($q) => $q->where('event_type', $type))
                ->orderByDesc('ts')
                ->limit(300)
                ->get();
        }

        $alerts = DB::table('security_alerts')
            ->where(function ($q) use ($hostId) {
                $q->where('actor_key', $hostId)->orWhereRaw("evidence::text ilike ?", ['%'.$hostId.'%']);
            })
            ->where('detected_at', '>=', $since)
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        $incidents = DB::table('security_incidents')
            ->whereRaw("affected_entities::text ilike ?", ['%'.$hostId.'%'])
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        $responses = DB::table('soc_response_workflows')
            ->where('agent_id', optional(DB::table('endpoint_agents')->where('host_id', $hostId)->first())->agent_id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $sessionId = 'session-'.Str::uuid();
        DB::table('endpoint_investigation_sessions')->insert([
            'session_id' => $sessionId,
            'host_id' => $hostId,
            'created_by' => $request->user()->email,
            'started_at' => now(),
            'filters' => json_encode(['minutes' => $minutes, 'type' => $type]),
            'summary' => json_encode(['telemetry' => $telemetry->count(), 'alerts' => $alerts->count(), 'incidents' => $incidents->count()]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'endpoint.timeline.view', 'endpoint', $hostId, null, ['session_id' => $sessionId]);

        return view('soc.endpoints.timeline', compact('hostId', 'minutes', 'type', 'telemetry', 'alerts', 'incidents', 'responses', 'sessionId'));
    }
}
