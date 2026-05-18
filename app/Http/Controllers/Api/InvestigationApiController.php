<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Services\InvestigationOrchestratorService;
use App\Support\TraceRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvestigationApiController extends Controller
{
    public function __construct(private readonly InvestigationOrchestratorService $orchestrator) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('state'))    $filters['state']    = $request->get('state');
        if ($request->filled('severity')) $filters['severity'] = $request->get('severity');
        if ($request->get('assigned') === 'me') $filters['assigned_to'] = auth()->id();

        $investigations = $this->orchestrator->list($filters, 100);
        $counts         = $this->orchestrator->getStateCounts();

        return response()->json([
            'investigations' => $investigations,
            'total'          => $investigations->count(),
            'counts'         => $counts,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $investigation = DB::table('investigations as i')
            ->leftJoin('users as cr', 'cr.id', '=', 'i.created_by')
            ->leftJoin('users as as', 'as.id', '=', 'i.assigned_to')
            ->select('i.*', 'cr.name as creator_name', 'as.name as assignee_name')
            ->where('i.id', $id)
            ->first();

        if (!$investigation) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $alertIds    = is_string($investigation->alert_ids) ? json_decode($investigation->alert_ids, true) : ($investigation->alert_ids ?? []);
        $incidentIds = is_string($investigation->incident_ids) ? json_decode($investigation->incident_ids, true) : ($investigation->incident_ids ?? []);

        $linkedAlerts    = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get())
            : collect();
        $linkedIncidents = !empty($incidentIds)
            ? TraceRedactor::collection(DB::table('security_incidents')->whereIn('incident_id', $incidentIds)->get())
            : collect();

        return response()->json([
            'investigation'   => $investigation,
            'linked_alerts'   => $linkedAlerts,
            'linked_incidents'=> $linkedIncidents,
        ]);
    }

    public function timeline(int $id): JsonResponse
    {
        $inv = DB::table('investigations')->where('id', $id)->first();
        if (!$inv) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $events = $this->orchestrator->getTimeline($id);

        return response()->json([
            'investigation_id' => $id,
            'count'            => $events->count(),
            'timeline'         => $events,
        ]);
    }

    public function notes(int $id): JsonResponse
    {
        $inv = DB::table('investigations')->where('id', $id)->first();
        if (!$inv) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $notes = DB::table('investigation_notes as n')
            ->leftJoin('users', 'users.id', '=', 'n.author_user_id')
            ->select('n.*', 'users.name as author_name')
            ->where('n.investigation_id', $id)
            ->orderByDesc('n.is_pinned')
            ->orderByDesc('n.created_at')
            ->get();

        return response()->json([
            'investigation_id' => $id,
            'count'            => $notes->count(),
            'notes'            => $notes,
        ]);
    }

    public function storeNote(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('investigations')->where('id', $id)->first();
        if (!$inv) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $data = $request->validate([
            'note_type' => 'required|in:' . implode(',', Investigation::NOTE_TYPES),
            'body'      => 'required|string|max:5000',
            'trace_id'  => 'nullable|string|max:120',
            'is_pinned' => 'nullable|boolean',
        ]);

        $note = $this->orchestrator->addNote(
            $id, auth()->id(), $data['note_type'], $data['body'],
            $data['trace_id'] ?? null, (bool) ($data['is_pinned'] ?? false)
        );

        return response()->json(['note' => $note], 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('investigations')->where('id', $id)->first();
        if (!$inv) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $data = $request->validate([
            'assign_to' => 'required|integer|exists:users,id',
        ]);

        $this->orchestrator->assignAnalyst($id, (int) $data['assign_to'], auth()->id());

        return response()->json(['status' => 'assigned', 'assigned_to' => $data['assign_to']]);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('investigations')->where('id', $id)->first();
        if (!$inv) {
            return response()->json(['error' => 'investigation_not_found'], 404);
        }

        $data = $request->validate([
            'state' => 'required|in:' . implode(',', Investigation::VALID_STATES),
            'note'  => 'nullable|string|max:2000',
        ]);

        try {
            $this->orchestrator->transitionState($id, $data['state'], auth()->id(), $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'    => 'transitioned',
            'new_state' => $data['state'],
        ]);
    }
}
