<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\ConfidenceSourceRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ENTERPRISE-058: Confidence Source Refresh view. Read-only. Advisory-only. */
class ConfidenceSourceRefreshController extends Controller
{
    public function __construct(
        private readonly ConfidenceSourceRefreshService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $distribution = $this->service->getDistribution();
        $latestRun    = $this->service->getLatestRun();

        if ($request->wantsJson()) {
            return response()->json([
                'advisory_only' => true,
                'distribution'  => $distribution,
                'latest_run'    => $latestRun,
            ]);
        }

        return view('detection.confidence_source_refresh', compact('distribution', 'latestRun'));
    }
}
