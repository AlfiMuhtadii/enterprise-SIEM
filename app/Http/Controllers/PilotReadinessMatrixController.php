<?php

namespace App\Http\Controllers;

use App\Models\PilotReadinessMatrixRun;
use App\Services\EnterprisePilotReadinessMatrixService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PilotReadinessMatrixController extends Controller
{
    public function __construct(
        private readonly EnterprisePilotReadinessMatrixService $service,
        private readonly TenantContextAuthority                $tenantAuthority,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, Auth::user());

        $runs = PilotReadinessMatrixRun::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(20);

        if ($request->expectsJson()) {
            return response()->json(['runs' => $runs]);
        }

        return view('pilot.readiness_matrix.index', compact('runs'));
    }

    public function show(Request $request, string $runId): View|JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, Auth::user());

        $run = PilotReadinessMatrixRun::where('matrix_run_id', $runId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $report = $this->service->generateMatrixReport($run);

        if ($request->expectsJson()) {
            return response()->json($report);
        }

        return view('pilot.readiness_matrix.show', compact('run', 'report'));
    }

    public function report(Request $request, string $runId): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, Auth::user());

        $run = PilotReadinessMatrixRun::where('matrix_run_id', $runId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $report = $this->service->generateMatrixReport($run);

        return response()->json($report);
    }
}
