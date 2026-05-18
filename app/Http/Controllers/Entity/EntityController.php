<?php

namespace App\Http\Controllers\Entity;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\EntityGraphService;
use App\Support\TraceRedactor;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntityController extends Controller
{
    public function __construct(private readonly EntityGraphService $graph) {}

    public function index(Request $request): View
    {
        $q        = trim((string) $request->get('q', ''));
        $type     = (string) $request->get('type', '');
        $entities = collect();

        if ($q !== '') {
            $entities = $this->graph->search($q, $type);
        }

        return view('entity.index', [
            'q'        => $q,
            'type'     => $type,
            'entities' => $entities,
            'types'    => Entity::TYPES,
        ]);
    }

    public function show(int $id): View
    {
        $entity = $this->graph->getById($id);
        abort_if(!$entity, 404);

        $relationships = $this->graph->getRelationships($id);
        $alerts        = TraceRedactor::collection($this->graph->getAlerts($id));
        $incidents     = TraceRedactor::collection($this->graph->getIncidents($id));
        $observations  = $this->graph->getTimeline($id)->take(20);

        return view('entity.show', compact(
            'entity', 'relationships', 'alerts', 'incidents', 'observations'
        ));
    }

    public function timeline(int $id): View
    {
        $entity = $this->graph->getById($id);
        abort_if(!$entity, 404);

        $timeline = $this->graph->getTimeline($id);

        return view('entity.timeline', compact('entity', 'timeline'));
    }

    public function graph(int $id): View
    {
        $entity = $this->graph->getById($id);
        abort_if(!$entity, 404);

        $adjacency = $this->graph->buildAdjacency($id, depth: 1);

        return view('entity.graph', compact('entity', 'adjacency'));
    }
}
