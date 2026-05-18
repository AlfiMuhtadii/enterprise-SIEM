<?php

namespace App\Http\Controllers\Response;

use App\Http\Controllers\Controller;
use App\Models\ResponsePlan;
use App\Services\ResponsePlanningService;
use App\Support\TraceRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ResponsePlanController extends Controller
{
    public function __construct(private readonly ResponsePlanningService $planning) {}

    public function index(Request $request): View
    {
        $stateFilter = (string) $request->get('state', '');

        $filters = [];
        if ($stateFilter) $filters['state'] = $stateFilter;

        $plans  = $this->planning->list($filters);
        $counts = $this->planning->getStateCounts();

        $pendingCount = (int) ($counts->get('pending_approval')?->count ?? 0);

        return view('response.index', compact('plans', 'counts', 'stateFilter', 'pendingCount'));
    }

    public function show(int $id): View
    {
        $plan = DB::table('response_plans as rp')
            ->leftJoin('users as cr', 'cr.id', '=', 'rp.created_by')
            ->leftJoin('users as ap', 'ap.id', '=', 'rp.assigned_approver')
            ->select('rp.*', 'cr.name as creator_name', 'ap.name as approver_name')
            ->where('rp.id', $id)
            ->first();

        abort_if(!$plan, 404);

        $actions  = DB::table('response_plan_actions')->where('response_plan_id', $id)->orderBy('created_at')->get();
        $timeline = $this->planning->getTimeline($id);
        $notes    = DB::table('response_plan_notes as n')
            ->leftJoin('users', 'users.id', '=', 'n.author_user_id')
            ->select('n.*', 'users.name as author_name')
            ->where('n.response_plan_id', $id)
            ->orderByDesc('n.created_at')
            ->get();

        $alertIds    = is_string($plan->related_alert_ids) ? json_decode($plan->related_alert_ids, true) : ($plan->related_alert_ids ?? []);
        $entityIds   = is_string($plan->entity_ids) ? json_decode($plan->entity_ids, true) : ($plan->entity_ids ?? []);
        $linkedAlerts   = !empty($alertIds) ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get()) : collect();
        $linkedEntities = !empty($entityIds) ? DB::table('entities')->whereIn('id', $entityIds)->get() : collect();

        $validTransitions = ResponsePlan::STATE_TRANSITIONS[$plan->state] ?? [];
        $users = DB::table('users')->orderBy('name')->get(['id', 'name', 'role']);

        $investigation = $plan->investigation_id
            ? DB::table('investigations')->where('id', $plan->investigation_id)->first()
            : null;

        return view('response.show', compact(
            'plan', 'actions', 'timeline', 'notes',
            'linkedAlerts', 'linkedEntities', 'validTransitions', 'users', 'investigation'
        ));
    }

    public function recommendations(Request $request): View
    {
        $entityId        = (int) $request->get('entity_id', 0);
        $investigationId = (int) $request->get('investigation_id', 0);

        $entity        = null;
        $investigation = null;
        $recs          = [];

        if ($entityId > 0) {
            $entity = DB::table('entities')->where('id', $entityId)->first();
            if ($entity) {
                $recs = $this->planning->generateRecommendationsForEntity($entityId);
            }
        } elseif ($investigationId > 0) {
            $investigation = DB::table('investigations')->where('id', $investigationId)->first();
            if ($investigation) {
                $recs = $this->planning->generateRecommendationsForInvestigation($investigationId);
            }
        }

        return view('response.recommendations', compact('recs', 'entity', 'investigation', 'entityId', 'investigationId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string|max:5000',
            'risk_level'       => 'nullable|in:low,medium,high,critical',
            'investigation_id' => 'nullable|integer',
            'trace_id'         => 'nullable|string|max:120',
        ]);

        $plan = $this->planning->createPlan($data, auth()->id());

        return redirect()->route('response.show', $plan->id)
            ->with('success', "Response plan {$plan->plan_id} created.");
    }

    public function submit(int $id): RedirectResponse
    {
        try {
            $this->planning->submitForApproval($id, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        return redirect()->route('response.show', $id)->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);
        try {
            $this->planning->approve($id, auth()->id(), $data['notes'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        return redirect()->route('response.show', $id)->with('success', 'Plan approved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        try {
            $this->planning->reject($id, auth()->id(), $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        return redirect()->route('response.show', $id)->with('success', 'Plan rejected.');
    }

    public function complete(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);
        try {
            $this->planning->markCompleted($id, auth()->id(), $data['notes'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        return redirect()->route('response.show', $id)->with('success', 'Plan marked as completed (documented).');
    }

    public function cancel(int $id): RedirectResponse
    {
        try {
            $this->planning->cancel($id, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        return redirect()->route('response.show', $id)->with('success', 'Plan cancelled.');
    }

    public function storeNote(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'note_type' => 'required|in:' . implode(',', ResponsePlan::NOTE_TYPES),
            'body'      => 'required|string|max:5000',
        ]);
        $this->planning->addNote($id, auth()->id(), $data['note_type'], $data['body']);
        return redirect()->route('response.show', $id)->with('success', 'Note added.');
    }
}
