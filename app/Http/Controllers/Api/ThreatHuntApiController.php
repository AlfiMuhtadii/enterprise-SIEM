<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantContextAuthority;
use App\Services\ThreatHuntingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Threat Hunt API — advisory-only, non-destructive.
 * All query domains and operators are strictly allowlisted.
 */
class ThreatHuntApiController extends Controller
{
    public function __construct(
        private ThreatHuntingService $huntingService,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    /** POST /api/threat-hunts/query — execute a structured hunt */
    public function executeQuery(Request $request): JsonResponse
    {
        $request->validate([
            'query_domain' => 'required|string|max:60',
            'query_filters' => 'array',
            'max_results' => 'integer|min:1|max:500',
            'title' => 'string|max:255',
        ]);

        try {
            $data = $request->all();
            $data['tenant_id'] = $this->tenantId($request);
            $hunt = $this->huntingService->executeHunt($data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'hunt_id' => $hunt->hunt_id,
            'status' => $hunt->status,
            'result_count' => $hunt->result_count,
            'trace_id' => $hunt->trace_id,
        ], 201);
    }

    /** GET /api/threat-hunts — list hunt history */
    public function listHunts(Request $request): JsonResponse
    {
        $hunts = $this->huntingService->getHuntHistory(50, $this->tenantId($request));

        return response()->json([
            'hunts' => $hunts->map(fn ($h) => [
                'hunt_id' => $h->hunt_id,
                'title' => $h->title,
                'status' => $h->status,
                'result_count' => $h->result_count,
                'executed_at' => $h->executed_at?->toIso8601String(),
                'replay_scope' => $h->replay_scope,
            ])->all(),
        ]);
    }

    /** GET /api/threat-hunts/{id} — get hunt detail */
    public function getHunt(Request $request, string $huntId): JsonResponse
    {
        $hunt = $this->huntingService->getHuntWithResults($huntId, $this->tenantId($request));
        if (! $hunt) {
            return response()->json(['error' => 'hunt_not_found'], 404);
        }

        return response()->json([
            'hunt_id' => $hunt->hunt_id,
            'title' => $hunt->title,
            'description' => $hunt->description,
            'status' => $hunt->status,
            'result_count' => $hunt->result_count,
            'executed_at' => $hunt->executed_at?->toIso8601String(),
            'replay_scope' => $hunt->replay_scope,
            'trace_id' => $hunt->trace_id,
            'query' => $hunt->queries->first()?->only([
                'query_domain', 'query_filters', 'time_range_start', 'time_range_end', 'max_results',
            ]),
        ]);
    }

    /** GET /api/threat-hunts/{id}/results — paginated results */
    public function getResults(Request $request, string $huntId): JsonResponse
    {
        $hunt = $this->huntingService->getHuntWithResults($huntId, $this->tenantId($request));
        if (! $hunt) {
            return response()->json(['error' => 'hunt_not_found'], 404);
        }

        return response()->json([
            'hunt_id' => $hunt->hunt_id,
            'results' => $hunt->results->map(fn ($r) => [
                'result_type' => $r->result_type,
                'result_data' => $r->result_data,
            ])->all(),
        ]);
    }

    /** GET /api/threat-hunts/pivot/{entityType}?id= — entity pivot */
    public function pivot(Request $request, string $entityType): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $id = $request->query('id', '');

        $result = match ($entityType) {
            'host' => $this->huntingService->pivotHost((string) $id, $tenantId),
            'process' => $this->huntingService->pivotProcess((string) $id, tenantId: $tenantId),
            'persistence' => $this->huntingService->pivotPersistence((string) $id, tenantId: $tenantId),
            'trace' => $this->huntingService->pivotTrace((string) $id, $tenantId),
            'entity' => $this->huntingService->pivotEntity((int) $id, tenantId: $tenantId),
            default => ['error' => "Unsupported pivot type: {$entityType}"],
        };

        return response()->json($result);
    }

    private function tenantId(Request $request): ?string
    {
        return $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: true);
    }
}
