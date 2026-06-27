<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\Phase1SoakExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SoakPhase1Controller extends Controller
{
    public function __construct(private readonly Phase1SoakExecutionService $service) {}

    public function index(Request $request): JsonResponse|View
    {
        $latestRun   = $this->service->getLatestRun();
        $definitions = $this->service->getGateDefinitions();

        if ($request->wantsJson()) {
            return response()->json([
                'advisory_only'    => Phase1SoakExecutionService::ADVISORY_ONLY,
                'no_promotion'     => Phase1SoakExecutionService::NO_PROMOTION,
                'scope'            => Phase1SoakExecutionService::SCOPE,
                'duration_min'     => Phase1SoakExecutionService::DURATION_MIN,
                'duration_max'     => Phase1SoakExecutionService::DURATION_MAX,
                'gates_total'      => Phase1SoakExecutionService::GATES_TOTAL,
                'latest_run'       => $latestRun,
                'gate_definitions' => $definitions,
            ]);
        }

        return view('detection.phase1_soak', compact('latestRun', 'definitions'));
    }
}
