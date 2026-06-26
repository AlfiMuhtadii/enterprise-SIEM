<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\DetectionPromotionReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ENTERPRISE-045: Detection Domain Promotion Readiness
 *
 * Read-only SOC view. Advisory-only. No rules are promoted, no ACTIVE_ALLOWLIST changes.
 */
class DetectionPromotionReadinessController extends Controller
{
    public function __construct(
        private readonly DetectionPromotionReadinessService $readiness,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $summary = $this->readiness->getSummary();
        $allRules = $this->readiness->classifyAll();

        $byReadiness = [
            'active'            => $allRules->where('readiness', DetectionPromotionReadinessService::READINESS_ACTIVE)->values(),
            'shadow_ready'      => $allRules->where('readiness', DetectionPromotionReadinessService::READINESS_SHADOW_READY)->values(),
            'shadow_needs_soak' => $allRules->where('readiness', DetectionPromotionReadinessService::READINESS_SHADOW_NEEDS_SOAK)->values(),
            'deferred'          => $allRules->where('readiness', DetectionPromotionReadinessService::READINESS_DEFERRED)->values(),
            'out_of_scope'      => $allRules->where('readiness', DetectionPromotionReadinessService::READINESS_OUT_OF_SCOPE)->values(),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'summary'     => $summary,
                'rules'       => $allRules->all(),
                'advisory'    => true,
                'no_promotion'=> true,
            ]);
        }

        return view('detection.promotion_readiness', compact('summary', 'byReadiness'));
    }
}
