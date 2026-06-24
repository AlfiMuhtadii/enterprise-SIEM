<?php

namespace App\Http\Controllers;

use App\Models\PilotReadinessMatrixRun;
use App\Services\EnterprisePilotReadinessMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PilotReadinessMatrixController extends Controller
{
    public function __construct(
        private readonly EnterprisePilotReadinessMatrixService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $runs = PilotReadinessMatrixRun::orderByDesc('created_at')->paginate(20);

        if ($request->expectsJson()) {
            return response()->json(['runs' => $runs]);
        }

        return view('pilot.readiness_matrix.index', compact('runs'));
    }

    public function show(Request $request, string $runId): View|JsonResponse
    {
        $run = PilotReadinessMatrixRun::where('matrix_run_id', $runId)->firstOrFail();
        $report = $this->service->generateMatrixReport($run);

        if ($request->expectsJson()) {
            return response()->json($report);
        }

        return view('pilot.readiness_matrix.show', compact('run', 'report'));
    }

    public function report(Request $request, string $runId): JsonResponse
    {
        $run = PilotReadinessMatrixRun::where('matrix_run_id', $runId)->firstOrFail();
        $report = $this->service->generateMatrixReport($run);

        return response()->json($report);
    }
}
