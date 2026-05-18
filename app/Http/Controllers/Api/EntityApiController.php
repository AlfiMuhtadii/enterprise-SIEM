<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityGraphService;
use App\Support\TraceRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EntityApiController extends Controller
{
    public function __construct(private readonly EntityGraphService $graph) {}

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

    public function show(int $id): JsonResponse
    {
        $entity = $this->graph->getById($id);
        if (!$entity) {
            return response()->json(['error' => 'entity_not_found'], 404);
        }

        return response()->json(['entity' => $entity]);
    }

    public function timeline(int $id): JsonResponse
    {
        $entity = $this->graph->getById($id);
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

    public function relationships(int $id): JsonResponse
    {
        $entity = $this->graph->getById($id);
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

    public function alerts(int $id): JsonResponse
    {
        $entity = $this->graph->getById($id);
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

    public function incidents(int $id): JsonResponse
    {
        $entity = $this->graph->getById($id);
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
}
