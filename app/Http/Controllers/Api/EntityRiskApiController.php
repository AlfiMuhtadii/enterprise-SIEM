<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityRiskScoringService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntityRiskApiController extends Controller
{
    public function __construct(
        private readonly EntityRiskScoringService $scorer,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type  = (string) $request->get('type', '');
        $level = (string) $request->get('level', '');
        $limit = min((int) $request->get('limit', 50), 200);

        $query = DB::table('entities')
            ->whereNotNull('risk_score')
            ->orderByDesc('risk_score')
            ->limit($limit);

        if ($type) {
            $query->where('entity_type', $type);
        }
        if ($level) {
            $query->where('risk_level', $level);
        }

        $entities = $query->get();

        return response()->json([
            'entities' => $entities,
            'total'    => $entities->count(),
        ]);
    }

    public function top(): JsonResponse
    {
        return response()->json([
            'users'        => $this->scorer->getTopRiskyEntities('user', 10),
            'hosts'        => $this->scorer->getTopRiskyEntities('host', 10),
            'ips'          => $this->scorer->getTopRiskyEntities('ip', 10),
            'domains'      => $this->scorer->getTopRiskyEntities('domain', 10),
            'distribution' => $this->scorer->getRiskDistribution(),
        ]);
    }

    public function entityRisk(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $entity = DB::table('entities')->where('id', $id)->first();
        if (!$entity || ($tenantId !== null && $entity->tenant_id !== null && $entity->tenant_id !== $tenantId)) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $result = $this->scorer->calculateRisk($id, $tenantId);

        return response()->json([
            'entity_id'   => $id,
            'entity_type' => $entity->entity_type,
            'entity_key'  => $entity->entity_key,
            'risk'        => $result,
        ]);
    }

    public function riskHistory(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $entity = DB::table('entities')->where('id', $id)->first();
        if (!$entity || ($tenantId !== null && $entity->tenant_id !== null && $entity->tenant_id !== $tenantId)) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $history = $this->scorer->getRiskHistory($id, 30);

        return response()->json([
            'entity_id' => $id,
            'count'     => $history->count(),
            'history'   => $history,
        ]);
    }
}
