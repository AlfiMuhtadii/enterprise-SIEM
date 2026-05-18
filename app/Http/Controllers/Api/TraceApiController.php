<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TraceRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TraceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q  = trim((string) $request->get('q', ''));
        $by = $request->get('by', 'trace_id');

        if ($q === '') {
            $rows = DB::table('security_alerts')
                ->whereNotNull('trace_id')
                ->selectRaw("trace_id, count(*) as alerts_count, min(detected_at) as first_seen, max(detected_at) as last_seen, max(severity) as top_severity")
                ->groupBy('trace_id')
                ->orderByDesc('last_seen')
                ->limit(50)
                ->get();

            return response()->json(['traces' => $rows, 'total' => $rows->count()]);
        }

        $traceIds = match ($by) {
            'alert_id'    => DB::table('security_alerts')->where('alert_id', $q)->pluck('trace_id')->filter()->unique(),
            'incident_id' => DB::table('security_incidents')->where('incident_id', $q)->pluck('trace_id')->filter()->unique(),
            'actor_key'   => DB::table('security_alerts')->where('actor_key', $q)->pluck('trace_id')->filter()->unique(),
            'ip'          => DB::table('security_alerts')->where('ip', $q)->pluck('trace_id')->filter()->unique(),
            default       => collect([$q]),
        };

        if ($traceIds->isEmpty()) {
            return response()->json(['traces' => [], 'total' => 0]);
        }

        $alertStats    = DB::table('security_alerts')->whereIn('trace_id', $traceIds)
            ->selectRaw("trace_id, count(*) as alerts_count, min(detected_at) as first_seen, max(detected_at) as last_seen, max(severity) as top_severity")
            ->groupBy('trace_id')->get()->keyBy('trace_id');

        $incidentStats = DB::table('security_incidents')->whereIn('trace_id', $traceIds)
            ->selectRaw("trace_id, count(*) as incidents_count")
            ->groupBy('trace_id')->get()->keyBy('trace_id');

        $traces = $traceIds->map(fn (string $tid) => [
            'trace_id'        => $tid,
            'first_seen'      => $alertStats->get($tid)?->first_seen,
            'last_seen'       => $alertStats->get($tid)?->last_seen,
            'alerts_count'    => (int) ($alertStats->get($tid)?->alerts_count ?? 0),
            'incidents_count' => (int) ($incidentStats->get($tid)?->incidents_count ?? 0),
            'top_severity'    => $alertStats->get($tid)?->top_severity ?? 'none',
        ])->values();

        return response()->json(['traces' => $traces, 'total' => $traces->count()]);
    }

    public function show(string $traceId): JsonResponse
    {
        $alerts    = DB::table('security_alerts')->where('trace_id', $traceId)->get();
        $incidents = DB::table('security_incidents')->where('trace_id', $traceId)->get();
        $events    = DB::table('xdr_operational_events')->where('trace_id', $traceId)->count();

        if ($alerts->isEmpty() && $incidents->isEmpty() && $events === 0) {
            return response()->json(['error' => 'trace_not_found'], 404);
        }

        return response()->json([
            'trace_id'        => $traceId,
            'alerts_count'    => $alerts->count(),
            'incidents_count' => $incidents->count(),
            'events_count'    => $events,
            'first_seen'      => $alerts->min('detected_at'),
            'last_seen'       => $alerts->max('detected_at'),
            'top_severity'    => $this->topSeverity($alerts),
            'alerts'          => TraceRedactor::collection($alerts),
            'incidents'       => TraceRedactor::collection($incidents),
        ]);
    }

    public function timeline(string $traceId): JsonResponse
    {
        $opEvents = DB::table('xdr_operational_events')
            ->where('trace_id', $traceId)
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($e) => [
                'occurred_at'  => $e->occurred_at,
                'service'      => $e->source_service,
                'topic'        => $e->source_topic,
                'event_type'   => $e->event_type,
                'aggregate_id' => $e->aggregate_id,
                'status'       => 'ok',
                'source'       => 'operational_events',
                'payload'      => TraceRedactor::decodeAndRedact($e->payload),
                'metadata'     => TraceRedactor::decodeAndRedact($e->metadata),
            ]);

        $alertEvents = DB::table('security_alerts')
            ->where('trace_id', $traceId)
            ->get()
            ->map(fn ($a) => [
                'occurred_at'  => $a->detected_at,
                'service'      => 'alert-writer-service',
                'topic'        => 'security_alerts',
                'event_type'   => $a->alert_type,
                'aggregate_id' => $a->alert_id,
                'status'       => 'ok',
                'source'       => 'security_alerts',
                'severity'     => $a->severity,
                'evidence'     => TraceRedactor::decodeAndRedact($a->evidence),
            ]);

        $incidentEvents = DB::table('security_incidents')
            ->where('trace_id', $traceId)
            ->get()
            ->map(fn ($i) => [
                'occurred_at'  => $i->first_seen_at,
                'service'      => 'incident-builder-service',
                'topic'        => 'security_incidents',
                'event_type'   => 'incident.created',
                'aggregate_id' => $i->incident_id,
                'status'       => 'ok',
                'source'       => 'security_incidents',
            ]);

        $timeline = $opEvents
            ->merge($alertEvents)
            ->merge($incidentEvents)
            ->sortBy('occurred_at')
            ->values();

        return response()->json([
            'trace_id' => $traceId,
            'count'    => $timeline->count(),
            'timeline' => $timeline,
        ]);
    }

    public function evidence(string $traceId): JsonResponse
    {
        $evidence = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->orderBy('processed_at')
            ->get();

        return response()->json([
            'trace_id' => $traceId,
            'count'    => $evidence->count(),
            'evidence' => TraceRedactor::collection($evidence),
        ]);
    }

    public function alerts(string $traceId): JsonResponse
    {
        $alerts = DB::table('security_alerts')
            ->where('trace_id', $traceId)
            ->orderBy('detected_at')
            ->get();

        return response()->json([
            'trace_id' => $traceId,
            'count'    => $alerts->count(),
            'alerts'   => TraceRedactor::collection($alerts),
        ]);
    }

    public function incidents(string $traceId): JsonResponse
    {
        $incidents = DB::table('security_incidents')
            ->where('trace_id', $traceId)
            ->orderBy('first_seen_at')
            ->get();

        return response()->json([
            'trace_id'  => $traceId,
            'count'     => $incidents->count(),
            'incidents' => TraceRedactor::collection($incidents),
        ]);
    }

    private function topSeverity(\Illuminate\Support\Collection $alerts): string
    {
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        return $alerts
            ->sortByDesc(fn ($a) => $rank[$a->severity ?? 'low'] ?? 0)
            ->first()
            ?->severity ?? 'none';
    }
}
