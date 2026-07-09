<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BaselineAnomalyScore;
use App\Models\EntityBehaviorBaseline;
use App\Models\PeerGroupProfile;
use App\Services\TenantContextAuthority;
use App\Services\UEBABaselineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UEBA Phase 1 JSON API — advisory-only behavioral analytics.
 * All responses include advisory_only=true.
 * No autonomous enforcement endpoints.
 *
 * ENT-TENANCY-UEBA: every read/write resolves tenant context and scopes by
 * it, so one tenant's behavioral baselines/peer groups/anomaly scores are
 * never visible to or mixed with another tenant's.
 */
class UEBAApiController extends Controller
{
    public function __construct(
        private readonly UEBABaselineService $uebaService,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function baselineProfile(Request $request): JsonResponse
    {
        $request->validate([
            'entity_key'  => 'required|string|max:255',
            'entity_type' => 'required|in:user,host,ip,domain,process',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $profile = $this->uebaService->buildBaselineProfile(
            $request->input('entity_key'),
            $request->input('entity_type'),
            $tenantId
        );

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'profile'      => $profile,
        ]);
    }

    public function anomalyScores(Request $request): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $entityKey  = $request->input('entity_key');
        $entityType = $request->input('entity_type');
        $days       = min((int) $request->input('days', 7), 30);

        $query = BaselineAnomalyScore::where('is_advisory', true)
            ->where('scored_at', '>=', now()->subDays($days))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
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

    public function peerGroupProfile(Request $request, string $peerGroupKey): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $group = PeerGroupProfile::where('peer_group_key', $peerGroupKey)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();

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
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $entityType = $request->input('entity_type', '');
        $limit      = min((int) $request->input('limit', 20), 50);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'entities'     => $this->uebaService->getTopAnomalousEntities($entityType, $limit, $tenantId),
        ]);
    }

    public function driftSummary(Request $request): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'drift'        => $this->uebaService->getBaselineDriftSummary(50, $tenantId),
        ]);
    }

    public function anomalyVolumeTrend(Request $request): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $days = min((int) $request->input('days', 7), 30);

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'trend'        => $this->uebaService->getAnomalyVolumeTrend($days, $tenantId),
        ]);
    }

    public function detectAnomalies(Request $request): JsonResponse
    {
        $request->validate([
            'entity_key'  => 'required|string|max:255',
            'entity_type' => 'required|in:user,host,ip,domain,process',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $scores = $this->uebaService->detectAnomalies(
            $request->input('entity_key'),
            $request->input('entity_type'),
            $tenantId
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

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $baseline = $this->uebaService->computeBaseline(
            $request->input('entity_key'),
            $request->input('entity_type'),
            $request->input('dimension'),
            $tenantId
        );

        return response()->json([
            'ok'           => true,
            'advisory_only'=> true,
            'baseline'     => $baseline,
        ]);
    }
}
