<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\ShadowReadyPromotionDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ENTERPRISE-047: Shadow Promotion Decision view.
 *
 * Read-only SOC view showing the latest evaluated decisions for the 12 shadow_ready rules.
 * Advisory-only. No promotions are triggered from this controller.
 */
class ShadowPromotionDecisionController extends Controller
{
    public function __construct(
        private readonly ShadowReadyPromotionDecisionService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $domain  = $request->query('domain', '');
        $results = $this->service->getLatestRunResults($domain);
        $summary = $results->isEmpty()
            ? ['total' => 0, 'promote_eligible' => 0, 'keep_shadow' => 0, 'defer' => 0, 'promotion_approved' => false, 'advisory_only' => true]
            : $this->service->getSummary($results);

        if ($request->wantsJson()) {
            return response()->json([
                'summary'            => $summary,
                'decisions'          => $results->all(),
                'promotion_approved' => false,
                'advisory_only'      => true,
                'thresholds' => [
                    'promote_eligible' => ShadowReadyPromotionDecisionService::PROMOTE_ELIGIBLE_THRESHOLD,
                    'keep_shadow'      => ShadowReadyPromotionDecisionService::KEEP_SHADOW_THRESHOLD,
                ],
            ]);
        }

        return view('detection.shadow_promotion_decisions', compact('summary', 'results', 'domain'));
    }
}
