<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\SlaTrackingService;
use App\Services\SocCollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocWorkflowApiController extends Controller
{
    public function __construct(
        private readonly SocCollaborationService $collab,
        private readonly EscalationService       $escalation,
        private readonly SlaTrackingService      $sla,
    ) {}

    public function getOperationsSummary(): JsonResponse
    {
        return response()->json([
            'escalation_queue'      => $this->escalation->getPendingEscalations()->count(),
            'sla_breach_summary'    => $this->sla->getBreachSummary(),
            'timed_out_escalations' => $this->escalation->detectTimedOutEscalations()->count(),
            'advisory_note'         => 'Analyst-driven collaborative workflows only. No autonomous SOC operations.',
        ]);
    }

    public function getEscalationQueue(): JsonResponse
    {
        return response()->json([
            'queue'       => $this->escalation->getEscalationQueue(),
            'timed_out'   => $this->escalation->detectTimedOutEscalations(),
            'advisory_note' => 'No automatic escalation execution. All escalations require operator action.',
        ]);
    }

    public function getSlaBreaches(): JsonResponse
    {
        return response()->json([
            'summary'   => $this->sla->getBreachSummary(),
            'recent'    => $this->sla->getRecentBreaches(20),
            'overdue'   => $this->sla->getOverdueInvestigations(),
        ]);
    }

    public function getAnalystWorkload(): JsonResponse
    {
        return response()->json([
            'workload'     => $this->sla->getAnalystWorkloadMetrics(),
            'advisory_note'=> 'No auto-routing based on workload.',
        ]);
    }

    public function getWatchlists(Request $request): JsonResponse
    {
        return response()->json([
            'watchlists' => $this->collab->getAllWatchlists($request->query('watch_type')),
        ]);
    }

    public function getCollaborationTimeline(Request $request): JsonResponse
    {
        $id = $request->query('investigation_id', '');
        return response()->json([
            'timeline'       => $this->collab->getCollaborationTimeline($id),
            'workflow_state' => $this->escalation->getCurrentWorkflowState($id),
            'history'        => $this->escalation->getWorkflowStateHistory($id),
        ]);
    }

    public function addCollaborator(Request $request): JsonResponse
    {
        $request->validate([
            'investigation_id' => 'required|string',
            'analyst_id'       => 'required|integer',
            'role'             => 'required|string',
        ]);

        $collab = $this->collab->addCollaborator(
            $request->investigation_id,
            $request->analyst_id,
            $request->role,
            $request->user(),
            $request->note
        );

        return response()->json(['collab_id' => $collab->collab_id], 201);
    }

    public function acknowledgeEscalation(Request $request, string $escalationId): JsonResponse
    {
        $event = $this->escalation->acknowledgeEscalation(
            $escalationId, $request->user(), $request->input('note')
        );
        return response()->json(['acknowledged' => true, 'event_type' => $event->event_type]);
    }
}
