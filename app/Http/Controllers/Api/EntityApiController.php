<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityGraphService;
use App\Services\TenantContextAuthority;
use App\Support\TraceRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntityApiController extends Controller
{
    public function __construct(
        private readonly EntityGraphService $graph,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q    = trim((string) $request->get('q', ''));
        $type = (string) $request->get('type', '');

        $entities = $q !== ''
            ? $this->graph->search($q, $type)
            : DB::table('entities')->orderByDesc('last_seen_at')->limit(50)->get();

        return response()->json([
            'entities' => $entities,
            'total'    => $entities->count(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $entity = $this->resolveEntity($request, $id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        return response()->json(['entity' => $entity]);
    }

    public function timeline(Request $request, int $id): JsonResponse
    {
        $entity = $this->resolveEntity($request, $id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $timeline = $this->graph->getTimeline($id);

        return response()->json([
            'entity_id' => $id,
            'count'     => $timeline->count(),
            'timeline'  => $timeline,
        ]);
    }

    public function relationships(Request $request, int $id): JsonResponse
    {
        $entity = $this->resolveEntity($request, $id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $rels = $this->graph->getRelationships($id);

        return response()->json([
            'entity_id'     => $id,
            'count'         => $rels->count(),
            'relationships' => $rels,
        ]);
    }

    public function alerts(Request $request, int $id): JsonResponse
    {
        $entity = $this->resolveEntity($request, $id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $alerts = TraceRedactor::collection($this->graph->getAlerts($id));

        return response()->json([
            'entity_id' => $id,
            'count'     => $alerts->count(),
            'alerts'    => $alerts,
        ]);
    }

    public function incidents(Request $request, int $id): JsonResponse
    {
        $entity = $this->resolveEntity($request, $id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        $incidents = TraceRedactor::collection($this->graph->getIncidents($id));

        return response()->json([
            'entity_id' => $id,
            'count'     => $incidents->count(),
            'incidents' => $incidents,
        ]);
    }

    /**
     * ENT-TENANCY-ENTITY-GRAPH: resolves the entity only if it belongs to
     * the requesting tenant (or either side is null/legacy-unscoped),
     * matching the same ownership-check convention used by
     * EntityRiskApiController — an entity from another tenant is treated
     * as not-found rather than exposed.
     */
    private function resolveEntity(Request $request, int $id): ?object
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $entity = $this->graph->getById($id);
        if (!$entity) {
            return null;
        }
        if ($tenantId !== null && $entity->tenant_id !== null && $entity->tenant_id !== $tenantId) {
            return null;
        }

        return $entity;
    }
}
