<?php

namespace App\Http\Controllers\Soc;

use App\Http\Controllers\Controller;
use App\Models\EscalationEvent;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\EscalationService;
use App\Services\SlaTrackingService;
use App\Services\SocCollaborationService;
use Illuminate\Http\Request;

class SocWorkflowController extends Controller
{
    public function __construct(
        private readonly SocCollaborationService $collab,
        private readonly EscalationService       $escalation,
        private readonly SlaTrackingService      $sla,
    ) {}

    public function operationsDashboard()
    {
        $escalationQueue = $this->escalation->getPendingEscalations();
        $breachSummary   = $this->sla->getBreachSummary();
        $workload        = $this->sla->getAnalystWorkloadMetrics();
        $timedOut        = $this->escalation->detectTimedOutEscalations();
        return view('soc-workflow.operations-dashboard', compact('escalationQueue', 'breachSummary', 'workload', 'timedOut'));
    }

    public function analystQueue(Request $request)
    {
        $analystId = $request->query('analyst_id', auth()->id());
        $queue     = $this->collab->getAnalystWorkQueue((int) $analystId);
        $handoffs  = $this->collab->getRecentHandoffs(5);
        $analysts  = User::whereIn('role', ['admin', 'analyst'])->orderBy('name')->get(['id', 'name', 'email']);
        return view('soc-workflow.analyst-queue', compact('queue', 'handoffs', 'analysts', 'analystId'));
    }

    public function escalationCenter(Request $request)
    {
        $analystId = $request->query('analyst_id');
        $queue     = $this->escalation->getEscalationQueue($analystId ? (int) $analystId : null);
        $recent    = $this->escalation->getRecentEscalationEvents(30);
        $timedOut  = $this->escalation->detectTimedOutEscalations();
        $analysts  = User::whereIn('role', ['admin', 'analyst'])->orderBy('name')->get(['id', 'name', 'email']);
        return view('soc-workflow.escalation-center', compact('queue', 'recent', 'timedOut', 'analysts', 'analystId'));
    }

    public function watchlistCenter(Request $request)
    {
        $watchType  = $request->query('watch_type');
        $watchlists = $this->collab->getAllWatchlists($watchType ?: null);
        $watchTypes = Watchlist::WATCH_TYPES;
        return view('soc-workflow.watchlist-center', compact('watchlists', 'watchTypes', 'watchType'));
    }

    public function slaMonitoring()
    {
        $policies      = $this->sla->getPolicies();
        $breachSummary = $this->sla->getBreachSummary();
        $recentBreaches= $this->sla->getRecentBreaches(30);
        $overdue       = $this->sla->getOverdueInvestigations();
        return view('soc-workflow.sla-monitoring', compact('policies', 'breachSummary', 'recentBreaches', 'overdue'));
    }

    public function shiftHandoff(Request $request)
    {
        $handoffs = $this->collab->getRecentHandoffs(20);
        $analysts = User::whereIn('role', ['admin', 'analyst'])->orderBy('name')->get(['id', 'name', 'email']);
        return view('soc-workflow.shift-handoff', compact('handoffs', 'analysts'));
    }

    public function collaborationTimeline(Request $request)
    {
        $investigationId = $request->query('investigation_id', '');
        $timeline        = $investigationId
            ? $this->collab->getCollaborationTimeline($investigationId)
            : collect();
        $workflowHistory = $investigationId
            ? $this->escalation->getWorkflowStateHistory($investigationId)
            : collect();
        $currentState    = $investigationId
            ? $this->escalation->getCurrentWorkflowState($investigationId)
            : null;
        return view('soc-workflow.collaboration-timeline', compact('investigationId', 'timeline', 'workflowHistory', 'currentState'));
    }

    public function analystWorkload()
    {
        $workload = $this->sla->getAnalystWorkloadMetrics();
        $analysts = User::whereIn('role', ['admin', 'analyst'])->get(['id', 'name', 'email']);
        return view('soc-workflow.analyst-workload', compact('workload', 'analysts'));
    }

    public function sharedInvestigations(Request $request)
    {
        $analystId    = (int) $request->query('analyst_id', auth()->id());
        $shared       = $this->collab->getSharedInvestigations($analystId);
        $analysts     = User::whereIn('role', ['admin', 'analyst'])->orderBy('name')->get(['id', 'name', 'email']);
        return view('soc-workflow.shared-investigations', compact('shared', 'analysts', 'analystId'));
    }

    // -----------------------------------------------------------------------
    // Form actions
    // -----------------------------------------------------------------------

    public function createHandoff(Request $request)
    {
        $request->validate([
            'shift_summary' => 'required|string|max:5000',
            'to_analyst_id' => 'nullable|integer',
        ]);

        $toAnalyst = $request->to_analyst_id ? User::find($request->to_analyst_id) : null;
        $this->collab->createHandoff(
            $request->user(),
            $toAnalyst,
            $request->shift_summary,
            $request->notes
        );

        return redirect()->route('soc.workflow.handoff')
            ->with('success', 'Shift handoff recorded.');
    }

    public function createWatchlist(Request $request)
    {
        $request->validate([
            'watch_type' => 'required|string',
            'watch_key'  => 'required|string|max:512',
        ]);

        $this->collab->createWatchlist(
            $request->user(),
            $request->watch_type,
            $request->watch_key,
            $request->watch_reason,
            $request->expires_at
        );

        return redirect()->route('soc.workflow.watchlist')
            ->with('success', 'Watchlist entry created.');
    }

    public function createEscalation(Request $request)
    {
        $request->validate([
            'investigation_id' => 'required|string',
            'reason'           => 'required|string|max:2000',
            'severity'         => 'required|string',
        ]);

        $this->escalation->createEscalation(
            $request->investigation_id,
            $request->user(),
            $request->to_analyst_id ? (int) $request->to_analyst_id : null,
            $request->reason,
            $request->severity
        );

        return redirect()->route('soc.workflow.escalation')
            ->with('success', 'Escalation created.');
    }
}
