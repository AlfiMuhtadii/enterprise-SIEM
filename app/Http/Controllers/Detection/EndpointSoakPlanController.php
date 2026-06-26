<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\EndpointSoakPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ENTERPRISE-048: Endpoint Shadow Domain Soak Plan view.
 * Read-only. Advisory-only. No promotions triggered.
 */
class EndpointSoakPlanController extends Controller
{
    public function __construct(
        private readonly EndpointSoakPlanService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $latest = $this->service->getLatestPlan();

        $summary   = $latest['summary'] ?? ['total_rules' => 0, 'tier_1_count' => 0, 'tier_2_count' => 0, 'tier_3_count' => 0, 'plan_approved' => false, 'is_advisory' => true];
        $rules     = collect($latest['rules'] ?? []);
        $gates     = collect($latest['gates'] ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'summary'       => $summary,
                'rules'         => $rules->all(),
                'gates'         => $gates->all(),
                'plan_approved' => false,
                'advisory_only' => true,
                'thresholds' => [
                    'tier_1' => EndpointSoakPlanService::TIER_1_THRESHOLD,
                    'tier_2' => EndpointSoakPlanService::TIER_2_THRESHOLD,
                ],
            ]);
        }

        return view('detection.endpoint_soak_plan', compact('summary', 'rules', 'gates'));
    }
}
