<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\DetectionReplayFixtureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ENTERPRISE-056: Detection Replay Fixtures view. Read-only. Advisory-only. */
class DetectionFixturesController extends Controller
{
    public function __construct(
        private readonly DetectionReplayFixtureService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $batches     = $this->service->getBatches();
        $latest      = $this->service->getLatestBatchResults();
        $fixturePaths = $this->service->getFixturePaths();

        if ($request->wantsJson()) {
            return response()->json([
                'advisory_only'    => true,
                'promotion_blocked'=> true,
                'batches'          => $batches->all(),
                'latest_results'   => $latest,
                'fixture_count'    => count($fixturePaths),
            ]);
        }

        return view('detection.fixture_batches', compact('batches', 'latest', 'fixturePaths'));
    }
}
