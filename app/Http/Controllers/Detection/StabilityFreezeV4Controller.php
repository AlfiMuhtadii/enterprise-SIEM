<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\StabilityEvidenceFreezeV4Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ENTERPRISE-059: Stability Evidence Freeze v4. Read-only. Advisory-only. */
class StabilityFreezeV4Controller extends Controller
{
    public function __construct(
        private readonly StabilityEvidenceFreezeV4Service $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $latest  = $this->service->getLatestFreeze();
        $summary = $latest['summary'] ?? [
            'total_gates' => 0, 'gates_passed' => 0, 'pass_score' => 0.0,
            'freeze_approved' => false, 'is_advisory' => true, 'stability' => 'NOT_RUN',
            'total_phases' => 0, 'allowed_claim_count' => 0, 'forbidden_claim_count' => 0, 'gap_count' => 0,
        ];
        $gates  = collect($latest['gates']  ?? []);
        $phases = collect($latest['phases'] ?? []);
        $claims = collect($latest['claims'] ?? []);
        $gaps   = collect($latest['gaps']   ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'summary'          => $summary,
                'gates'            => $gates->all(),
                'phases'           => $phases->all(),
                'claims'           => $claims->all(),
                'gaps'             => $gaps->all(),
                'freeze_approved'  => false,
                'advisory_only'    => true,
                'stable_threshold' => StabilityEvidenceFreezeV4Service::STABLE_SCORE_THRESHOLD,
                'phase_range'      => StabilityEvidenceFreezeV4Service::PHASE_RANGE,
            ]);
        }

        return view('detection.stability_freeze_v4', compact('summary', 'gates', 'phases', 'claims', 'gaps'));
    }
}
