<?php

namespace App\Http\Controllers\Investigation;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Services\InvestigationOrchestratorService;
use App\Support\TraceRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvestigationController extends Controller
{
    public function __construct(private readonly InvestigationOrchestratorService $orchestrator) {}

    public function index(Request $request): View
    {
        $stateFilter    = (string) $request->get('state', '');
        $severityFilter = (string) $request->get('severity', '');
        $assignFilter   = (string) $request->get('assigned', '');

        $filters = [];
        if ($stateFilter)          $filters['state']    = $stateFilter;
        if ($severityFilter)       $filters['severity'] = $severityFilter;
        if ($assignFilter === 'me') $filters['assigned_to'] = auth()->id();
        if ($assignFilter === 'unassigned') $filters['unassigned'] = true;

        $investigations = $this->orchestrator->list($filters);
        $counts         = $this->orchestrator->getStateCounts();

        return view('investigation.index', compact(
            'investigations', 'counts', 'stateFilter', 'severityFilter', 'assignFilter'
        ));
    }

    public function show(int $id): View
    {
        $investigation = DB::table('investigations as i')
            ->leftJoin('users as cr', 'cr.id', '=', 'i.created_by')
            ->leftJoin('users as as', 'as.id', '=', 'i.assigned_to')
            ->select('i.*', 'cr.name as creator_name', 'as.name as assignee_name')
            ->where('i.id', $id)
            ->first();

        abort_if(!$investigation, 404);

        $timeline   = $this->orchestrator->getTimeline($id);
        $notes      = DB::table('investigation_notes as n')
            ->leftJoin('users', 'users.id', '=', 'n.author_user_id')
            ->select('n.*', 'users.name as author_name')
            ->where('n.investigation_id', $id)
            ->orderByDesc('n.is_pinned')
            ->orderByDesc('n.created_at')
            ->get();
        $artifacts  = DB::table('investigation_artifacts')
            ->where('investigation_id', $id)
            ->orderByDesc('created_at')
            ->get();
        $assignments = DB::table('investigation_assignments as a')
            ->leftJoin('users as u1', 'u1.id', '=', 'a.assigned_to_user_id')
            ->leftJoin('users as u2', 'u2.id', '=', 'a.assigned_by_user_id')
            ->select('a.*', 'u1.name as assignee_name', 'u2.name as assigned_by_name')
            ->where('a.investigation_id', $id)
            ->orderByDesc('a.assigned_at')
            ->get();

        $alertIds    = is_string($investigation->alert_ids)    ? json_decode($investigation->alert_ids, true)    : ($investigation->alert_ids    ?? []);
        $incidentIds = is_string($investigation->incident_ids) ? json_decode($investigation->incident_ids, true) : ($investigation->incident_ids ?? []);
        $entityIds   = is_string($investigation->entity_ids)   ? json_decode($investigation->entity_ids, true)   : ($investigation->entity_ids   ?? []);

        $linkedAlerts    = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get())
            : collect();
        $linkedIncidents = !empty($incidentIds)
            ? TraceRedactor::collection(DB::table('security_incidents')->whereIn('incident_id', $incidentIds)->get())
            : collect();
        $linkedEntities  = !empty($entityIds)
            ? DB::table('entities')->whereIn('id', $entityIds)->get()
            : collect();

        $validTransitions = Investigation::STATE_TRANSITIONS[$investigation->state] ?? [];
        $users = DB::table('users')->orderBy('name')->get(['id', 'name', 'email', 'role']);

        return view('investigation.show', compact(
            'investigation', 'timeline', 'notes', 'artifacts', 'assignments',
            'linkedAlerts', 'linkedIncidents', 'linkedEntities',
            'validTransitions', 'users'
        ));
    }

    public function queue(Request $request): View
    {
        $investigations = $this->orchestrator->list([
            'assigned_to' => auth()->id(),
        ]);

        return view('investigation.queue', compact('investigations'));
    }

    public function escalations(): View
    {
        $investigations = $this->orchestrator->list(['state' => 'escalated']);

        return view('investigation.escalations', compact('investigations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'severity'    => 'required|in:low,medium,high,critical',
            'priority'    => 'nullable|integer|min:1|max:5',
            'trace_id'    => 'nullable|string|max:120',
        ]);

        $inv = $this->orchestrator->createInvestigation($data, auth()->id());

        return redirect()->route('investigation.show', $inv->id)
            ->with('success', "Investigation {$inv->investigation_id} created.");
    }

    public function transition(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'state' => 'required|in:' . implode(',', Investigation::VALID_STATES),
            'note'  => 'nullable|string|max:2000',
        ]);

        try {
            $this->orchestrator->transitionState($id, $data['state'], auth()->id(), $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('investigation.show', $id)
            ->with('success', 'State updated.');
    }

    public function assign(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'assign_to' => 'required|integer|exists:users,id',
        ]);

        $this->orchestrator->assignAnalyst($id, (int) $data['assign_to'], auth()->id());

        return redirect()->route('investigation.show', $id)
            ->with('success', 'Analyst assigned.');
    }

    public function storeNote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'note_type' => 'required|in:' . implode(',', Investigation::NOTE_TYPES),
            'body'      => 'required|string|max:5000',
            'trace_id'  => 'nullable|string|max:120',
            'is_pinned' => 'nullable|boolean',
        ]);

        $this->orchestrator->addNote(
            $id, auth()->id(), $data['note_type'], $data['body'],
            $data['trace_id'] ?? null, (bool) ($data['is_pinned'] ?? false)
        );

        return redirect()->route('investigation.show', $id)
            ->with('success', 'Note added.');
    }
}
