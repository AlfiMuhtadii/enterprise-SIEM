<?php

namespace App\Http\Controllers\Trace;

use App\Http\Controllers\Controller;
use App\Support\TraceRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TraceInvestigationController extends Controller
{
    public function index(Request $request): View
    {
        $q      = trim((string) $request->get('q', ''));
        $by     = $request->get('by', 'trace_id');
        $traces = collect();

        if ($q !== '') {
            $traces = $this->search($q, $by);
        }

        return view('traces.index', compact('q', 'by', 'traces'));
    }

    public function show(string $traceId): View
    {
        $timeline  = $this->buildTimeline($traceId);
        $alerts    = DB::table('security_alerts')
            ->where('trace_id', $traceId)
            ->orderBy('detected_at')
            ->get();
        $incidents = DB::table('security_incidents')
            ->where('trace_id', $traceId)
            ->orderBy('first_seen_at')
            ->get();
        $evidence  = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->orderBy('processed_at')
            ->get();
        $scenarioRun = DB::table('scenario_runs')
            ->where('trace_id', $traceId)
            ->first();

        $summary = [
            'alerts_count'    => $alerts->count(),
            'incidents_count' => $incidents->count(),
            'evidence_count'  => $evidence->count(),
            'events_count'    => $timeline->count(),
            'first_seen'      => $timeline->min('occurred_at') ?? $alerts->min('detected_at'),
            'last_seen'       => $timeline->max('occurred_at') ?? $alerts->max('detected_at'),
            'severity'        => $this->topSeverity($alerts),
        ];

        // Redact payload fields before rendering — DB is never mutated
        $alerts   = TraceRedactor::collection($alerts);
        $incidents = TraceRedactor::collection($incidents);
        $evidence  = TraceRedactor::collection($evidence);

        return view('traces.show', compact(
            'traceId', 'timeline', 'alerts', 'incidents', 'evidence', 'scenarioRun', 'summary'
        ));
    }

    private function search(string $q, string $by): \Illuminate\Support\Collection
    {
        $traceIds = match ($by) {
            'alert_id'    => DB::table('security_alerts')->where('alert_id', $q)->pluck('trace_id')->filter()->unique(),
            'incident_id' => DB::table('security_incidents')->where('incident_id', $q)->pluck('trace_id')->filter()->unique(),
            'actor_key'   => DB::table('security_alerts')->where('actor_key', $q)->pluck('trace_id')->filter()->unique(),
            'ip'          => DB::table('security_alerts')->where('ip', $q)->pluck('trace_id')->filter()->unique(),
            default       => collect([$q]),
        };

        if ($traceIds->isEmpty()) {
            return collect();
        }

        $alertStats = DB::table('security_alerts')
            ->whereIn('trace_id', $traceIds)
            ->selectRaw("trace_id, count(*) as alerts_count, min(detected_at) as first_seen, max(detected_at) as last_seen, max(severity) as top_severity")
            ->groupBy('trace_id')
            ->get()
            ->keyBy('trace_id');

        $incidentStats = DB::table('security_incidents')
            ->whereIn('trace_id', $traceIds)
            ->selectRaw("trace_id, count(*) as incidents_count")
            ->groupBy('trace_id')
            ->get()
            ->keyBy('trace_id');

        $opEventStats = DB::table('xdr_operational_events')
            ->whereIn('trace_id', $traceIds)
            ->selectRaw("trace_id, count(*) as events_count, min(occurred_at) as first_seen, max(occurred_at) as last_seen")
            ->groupBy('trace_id')
            ->get()
            ->keyBy('trace_id');

        return $traceIds->map(function (string $tid) use ($alertStats, $incidentStats, $opEventStats) {
            $as = $alertStats->get($tid);
            $is = $incidentStats->get($tid);
            $os = $opEventStats->get($tid);

            $alertCount    = (int) ($as?->alerts_count ?? 0);
            $incidentCount = (int) ($is?->incidents_count ?? 0);
            $eventCount    = (int) ($os?->events_count ?? 0);

            if ($alertCount === 0 && $incidentCount === 0 && $eventCount === 0) {
                return null;
            }

            $first = collect(array_filter([$as?->first_seen ?? null, $os?->first_seen ?? null]))->min();
            $last  = collect(array_filter([$as?->last_seen ?? null, $os?->last_seen ?? null]))->max();

            return (object) [
                'trace_id'        => $tid,
                'first_seen'      => $first,
                'last_seen'       => $last,
                'alerts_count'    => $alertCount,
                'incidents_count' => $incidentCount,
                'events_count'    => $eventCount,
                'top_severity'    => $as?->top_severity ?? 'none',
            ];
        })->filter()->values();
    }

    private function buildTimeline(string $traceId): \Illuminate\Support\Collection
    {
        $opEvents = DB::table('xdr_operational_events')
            ->where('trace_id', $traceId)
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($e) => (object) [
                'occurred_at'  => $e->occurred_at,
                'service'      => $e->source_service,
                'topic'        => $e->source_topic,
                'event_type'   => $e->event_type,
                'aggregate_id' => $e->aggregate_id,
                'status'       => 'ok',
                'latency_ms'   => null,
                'summary'      => $e->aggregate_type . ':' . ($e->aggregate_id ?? 'n/a'),
                'source'       => 'operational_events',
            ]);

        $alertEvents = DB::table('security_alerts')
            ->where('trace_id', $traceId)
            ->get()
            ->map(fn ($a) => (object) [
                'occurred_at'  => $a->detected_at,
                'service'      => 'alert-writer-service',
                'topic'        => 'security_alerts',
                'event_type'   => $a->alert_type,
                'aggregate_id' => $a->alert_id,
                'status'       => 'ok',
                'latency_ms'   => null,
                'summary'      => $a->severity . ' — ' . ($a->actor_key ?? $a->ip ?? 'unknown'),
                'source'       => 'security_alerts',
            ]);

        $incidentEvents = DB::table('security_incidents')
            ->where('trace_id', $traceId)
            ->get()
            ->map(fn ($i) => (object) [
                'occurred_at'  => $i->first_seen_at,
                'service'      => 'incident-builder-service',
                'topic'        => 'security_incidents',
                'event_type'   => 'incident.created',
                'aggregate_id' => $i->incident_id,
                'status'       => 'ok',
                'latency_ms'   => null,
                'summary'      => $i->title ?? $i->incident_id,
                'source'       => 'security_incidents',
            ]);

        $evidenceEvents = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->get()
            ->map(fn ($ev) => (object) [
                'occurred_at'  => $ev->processed_at,
                'service'      => 'scenario-runner',
                'topic'        => 'scenario_evidence',
                'event_type'   => $ev->stage,
                'aggregate_id' => $ev->event_id,
                'status'       => $ev->status,
                'latency_ms'   => $ev->latency_ms,
                'summary'      => $ev->stage_type . ' — ' . ($ev->rule_id ?? 'n/a'),
                'source'       => 'scenario_evidence',
            ]);

        return $opEvents
            ->merge($alertEvents)
            ->merge($incidentEvents)
            ->merge($evidenceEvents)
            ->sortBy('occurred_at')
            ->values();
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
