<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResponsePlan;
use App\Services\ResponsePlanningService;
use App\Support\TraceRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResponsePlanApiController extends Controller
{
    public function __construct(private readonly ResponsePlanningService $planning) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('state'))    $filters['state']    = $request->get('state');
        if ($request->filled('risk_level')) $filters['risk_level'] = $request->get('risk_level');

        $plans  = $this->planning->list($filters, 100);
        $counts = $this->planning->getStateCounts();

        return response()->json([
            'plans'  => $plans,
            'total'  => $plans->count(),
            'counts' => $counts,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $plan = DB::table('response_plans as rp')
            ->leftJoin('users as cr', 'cr.id', '=', 'rp.created_by')
            ->select('rp.*', 'cr.name as creator_name')
            ->where('rp.id', $id)
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }

        $actions = DB::table('response_plan_actions')->where('response_plan_id', $id)->get();

        $alertIds = is_string($plan->related_alert_ids) ? json_decode($plan->related_alert_ids, true) : ($plan->related_alert_ids ?? []);
        $linkedAlerts = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get())
            : collect();

        return response()->json([
            'plan'          => $plan,
            'actions'       => $actions,
            'linked_alerts' => $linkedAlerts,
        ]);
    }

    public function timeline(int $id): JsonResponse
    {
        if (!DB::table('response_plans')->where('id', $id)->exists()) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }

        $timeline = $this->planning->getTimeline($id);

        return response()->json([
            'plan_id'  => $id,
            'count'    => $timeline->count(),
            'timeline' => $timeline,
        ]);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $entityId        = (int) $request->get('entity_id', 0);
        $investigationId = (int) $request->get('investigation_id', 0);

        if ($entityId > 0) {
            $recs = $this->planning->generateRecommendationsForEntity($entityId);
            return response()->json(['entity_id' => $entityId, 'recommendations' => $recs, 'count' => count($recs)]);
        }

        if ($investigationId > 0) {
            $recs = $this->planning->generateRecommendationsForInvestigation($investigationId);
            return response()->json(['investigation_id' => $investigationId, 'recommendations' => $recs, 'count' => count($recs)]);
        }

        return response()->json(['error' => 'entity_id or investigation_id required'], 422);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if (!DB::table('response_plans')->where('id', $id)->exists()) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }

        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        try {
            $this->planning->approve($id, auth()->id(), $data['notes'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'approved', 'plan_id' => $id]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if (!DB::table('response_plans')->where('id', $id)->exists()) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        try {
            $this->planning->reject($id, auth()->id(), $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'rejected', 'plan_id' => $id]);
    }

    public function storeNote(Request $request, int $id): JsonResponse
    {
        if (!DB::table('response_plans')->where('id', $id)->exists()) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }

        $data = $request->validate([
            'note_type' => 'required|in:' . implode(',', ResponsePlan::NOTE_TYPES),
            'body'      => 'required|string|max:5000',
        ]);

        $note = $this->planning->addNote($id, auth()->id(), $data['note_type'], $data['body']);

        return response()->json(['note' => $note], 201);
    }
}
