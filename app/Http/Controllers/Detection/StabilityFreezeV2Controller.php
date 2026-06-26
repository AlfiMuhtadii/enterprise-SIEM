<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\StabilityEvidenceFreezeV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ENTERPRISE-049: Stability Evidence Freeze v2 view.
 * Read-only. Advisory-only. No promotions triggered.
 */
class StabilityFreezeV2Controller extends Controller
{
    public function __construct(
        private readonly StabilityEvidenceFreezeV2Service $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $latest  = $this->service->getLatestFreeze();
        $summary = $latest['summary'] ?? ['total_gates' => 0, 'gates_passed' => 0, 'pass_score' => 0.0, 'freeze_approved' => false, 'is_advisory' => true, 'stability' => 'NOT_RUN'];
        $gates   = collect($latest['gates'] ?? []);
        $phases  = collect($latest['phases'] ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'summary'        => $summary,
                'gates'          => $gates->all(),
                'phases'         => $phases->all(),
                'freeze_approved'=> false,
                'advisory_only'  => true,
                'stable_threshold' => StabilityEvidenceFreezeV2Service::STABLE_SCORE_THRESHOLD,
            ]);
        }

        return view('detection.stability_freeze_v2', compact('summary', 'gates', 'phases'));
    }
}
