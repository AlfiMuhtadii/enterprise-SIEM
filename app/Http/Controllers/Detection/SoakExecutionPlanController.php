<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\RealDomainSoakPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ENTERPRISE-060: Soak Execution Plan dashboard. Read-only. Advisory-only. */
class SoakExecutionPlanController extends Controller
{
    public function __construct(
        private readonly RealDomainSoakPlanService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $latest      = $this->service->getLatestPlan();
        $definitions = $this->service->getPhaseDefinitions();

        $plan   = $latest['plan']   ?? ['phases_total' => 4, 'gates_passed' => 0, 'total_gates' => 0, 'overall_readiness' => 'NOT_RUN', 'real_execution_gated' => true];
        $phases = collect($latest['phases'] ?? []);
        $gates  = collect($latest['gates']  ?? []);
        $notes  = collect($latest['notes']  ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'advisory_only'        => RealDomainSoakPlanService::ADVISORY_ONLY,
                'real_execution_gated' => RealDomainSoakPlanService::REAL_EXECUTION_GATED,
                'phases_total'         => RealDomainSoakPlanService::PHASES_TOTAL,
                'plan'                 => $plan,
                'phases'               => $phases->all(),
                'gates'                => $gates->all(),
                'notes'                => $notes->all(),
                'definitions'          => $definitions,
            ]);
        }

        return view('detection.soak_execution_plan', compact('plan', 'phases', 'gates', 'notes', 'definitions'));
    }
}
