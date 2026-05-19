<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BaselineAnomalyScore;
use App\Models\EntityBehaviorBaseline;
use App\Models\PeerGroupProfile;
use App\Services\UEBABaselineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UEBA Phase 1 JSON API — advisory-only behavioral analytics.
 * All responses include advisory_only=true.
 * No autonomous enforcement endpoints.
 */
class UEBAApiController extends Controller
{
    public function __construct(private readonly UEBABaselineService $uebaService) {}

    public function baselineProfile(Request $request): JsonResponse
    {
        $request->validate([
            'entity_key'  => 'required|string|max:255',
            'entity_type' => 'required|in:user,host,ip,domain,process',
        ]);

        $profile = $this->uebaService->buildBaselineProfile(
            $request->input('entity_key'),
            $request->input('entity_type')
        );

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'profile'      => $profile,
        ]);
    }

    public function anomalyScores(Request $request): JsonResponse
    {
        $entityKey  = $request->input('entity_key');
        $entityType = $request->input('entity_type');
        $days       = min((int) $request->input('days', 7), 30);

        $query = BaselineAnomalyScore::where('is_advisory', true)
            ->where('scored_at', '>=', now()->subDays($days))
            ->orderByDesc('scored_at')
            ->limit(200);

        if ($entityKey) {
            $query->where('entity_key', $entityKey);
        }
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'scores'       => $query->get(),
        ]);
    }

    public function peerGroupProfile(string $peerGroupKey): JsonResponse
    {
        $group = PeerGroupProfile::where('peer_group_key', $peerGroupKey)->first();

        if (!$group) {
            return response()->json(['ok' => false, 'error' => 'peer_group_not_found'], 404);
        }

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'peer_group'   => $group,
        ]);
    }

    public function topAnomalous(Request $request): JsonResponse
    {
        $entityType = $request->input('entity_type', '');
        $limit      = min((int) $request->input('limit', 20), 50);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'entities'     => $this->uebaService->getTopAnomalousEntities($entityType, $limit),
        ]);
    }

    public function driftSummary(): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'drift'        => $this->uebaService->getBaselineDriftSummary(50),
        ]);
    }

    public function anomalyVolumeTrend(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 7), 30);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'trend'        => $this->uebaService->getAnomalyVolumeTrend($days),
        ]);
    }

    public function detectAnomalies(Request $request): JsonResponse
    {
        $request->validate([
            'entity_key'  => 'required|string|max:255',
            'entity_type' => 'required|in:user,host,ip,domain,process',
        ]);

        $scores = $this->uebaService->detectAnomalies(
            $request->input('entity_key'),
            $request->input('entity_type')
        );

        return response()->json([
            'ok'                  => true,
            'advisory_only'       => true,
            'autonomous_action'   => false,
            'anomalies_detected'  => $scores->count(),
            'scores'              => $scores->values(),
            'disclaimer'          => 'Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.',
        ]);
    }

    public function computeBaseline(Request $request): JsonResponse
    {
        $request->validate([
            'entity_key'  => 'required|string|max:255',
            'entity_type' => 'required|in:user,host,ip,domain,process',
            'dimension'   => 'required|in:' . implode(',', EntityBehaviorBaseline::DIMENSIONS),
        ]);

        $baseline = $this->uebaService->computeBaseline(
            $request->input('entity_key'),
            $request->input('entity_type'),
            $request->input('dimension')
        );

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'baseline'     => $baseline,
        ]);
    }
}
