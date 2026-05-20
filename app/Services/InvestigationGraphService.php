<?php

namespace App\Services;

use App\Models\CrossDomainCorrelation;
use App\Models\Entity;
use App\Models\InvestigationBookmark;
use App\Models\InvestigationEvidenceLink;
use App\Models\InvestigationGraphEdge;
use App\Models\InvestigationGraphNode;
use App\Models\InvestigationSession;
use App\Models\InvestigationTimelineEvent;
use App\Models\RetrospectiveHuntQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvestigationGraphService
{
    public function __construct(
        private readonly EntityGraphService    $entityGraph,
        private readonly ThreatHuntingService  $hunting,
    ) {}

    // ─── Session management ──────────────────────────────────────────────────

    public function createSession(
        string  $name,
        ?string $investigationId = null,
        ?string $focusEntityId = null,
        ?string $focusNodeType = null,
        int     $hopDepth = 2,
        ?int    $createdBy = null,
        array   $metadata = []
    ): InvestigationSession {
        return InvestigationSession::create([
            'session_id'      => 'igs-' . Str::random(32),
            'investigation_id'=> $investigationId,
            'name'            => $name,
            'status'          => 'active',
            'focus_entity_id' => $focusEntityId,
            'focus_node_type' => $focusNodeType,
            'hop_depth'       => $hopDepth,
            'include_advisory'=> true,
            'created_by'      => $createdBy,
            'metadata'        => $metadata ?: null,
        ]);
    }

    public function pauseSession(InvestigationSession $session): void
    {
        $session->update(['status' => 'paused']);
    }

    public function completeSession(InvestigationSession $session): void
    {
        $session->update(['status' => 'completed']);
    }

    public function getSessions(?string $investigationId = null): Collection
    {
        $q = InvestigationSession::orderByDesc('updated_at');
        if ($investigationId) {
            $q->where('investigation_id', $investigationId);
        }
        return $q->get();
    }

    // ─── Graph node management (append-safe) ────────────────────────────────

    public function addNode(
        string  $sessionId,
        string  $nodeType,
        string  $label,
        ?string $investigationId = null,
        ?string $entityId = null,
        ?string $sourceDomain = null,
        ?string $observableValue = null,
        float   $riskScore = 0.0,
        bool    $isPivotOrigin = false,
        array   $attributes = [],
        ?int    $createdBy = null
    ): InvestigationGraphNode {
        return InvestigationGraphNode::create([
            'node_id'          => 'ign-' . Str::random(32),
            'investigation_id' => $investigationId,
            'session_id'       => $sessionId,
            'node_type'        => $nodeType,
            'entity_id'        => $entityId,
            'label'            => $label,
            'source_domain'    => $sourceDomain,
            'observable_value' => $observableValue,
            'risk_score'       => $riskScore,
            'is_pivot_origin'  => $isPivotOrigin,
            'is_advisory'      => true,
            'attributes'       => $attributes ?: null,
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);
    }

    public function addEdge(
        string  $sessionId,
        string  $fromNodeId,
        string  $toNodeId,
        string  $relationshipType,
        ?string $investigationId = null,
        ?string $evidenceSource = null,
        ?string $evidenceId = null,
        float   $confidence = 0.8,
        array   $attributes = [],
        ?int    $createdBy = null
    ): InvestigationGraphEdge {
        return InvestigationGraphEdge::create([
            'edge_id'           => 'ige-' . Str::random(32),
            'investigation_id'  => $investigationId,
            'session_id'        => $sessionId,
            'from_node_id'      => $fromNodeId,
            'to_node_id'        => $toNodeId,
            'relationship_type' => $relationshipType,
            'evidence_source'   => $evidenceSource,
            'evidence_id'       => $evidenceId,
            'confidence'        => $confidence,
            'is_advisory'       => true,
            'attributes'        => $attributes ?: null,
            'created_by'        => $createdBy,
            'created_at'        => now(),
        ]);
    }

    public function getSessionGraph(string $sessionId): array
    {
        $nodes = InvestigationGraphNode::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        $edges = InvestigationGraphEdge::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    // ─── Multi-hop pivot (bounded traversal) ────────────────────────────────

    /**
     * Perform a bounded multi-hop pivot from a starting entity.
     * Builds a subgraph of related entities up to $maxDepth hops.
     * Advisory-only — no destructive side effects.
     */
    public function multiHopPivot(
        string $startEntityId,
        string $sessionId,
        int    $maxDepth = 2,
        ?int   $createdBy = null
    ): array {
        $visited = [];
        $nodes   = [];
        $edges   = [];

        $this->traverseEntity(
            $startEntityId, $sessionId, $maxDepth, 0,
            $visited, $nodes, $edges, $createdBy, isPivotOrigin: true
        );

        return ['nodes' => $nodes, 'edges' => $edges, 'hop_depth' => $maxDepth];
    }

    private function traverseEntity(
        string  $entityId,
        string  $sessionId,
        int     $maxDepth,
        int     $currentDepth,
        array   &$visited,
        array   &$nodes,
        array   &$edges,
        ?int    $createdBy,
        bool    $isPivotOrigin = false
    ): ?InvestigationGraphNode {
        if ($currentDepth > $maxDepth || in_array($entityId, $visited, true)) {
            return null;
        }
        $visited[] = $entityId;

        $entity = Entity::where('entity_key', $entityId)
            ->orWhere('id', is_numeric($entityId) ? (int)$entityId : -1)
            ->first();

        $label = $entity ? ($entity->display_name ?: $entity->entity_key) : $entityId;
        $nodeType = $entity ? ($entity->entity_type ?? 'artifact') : 'artifact';

        $node = $this->addNode(
            $sessionId, $nodeType, $label,
            entityId: $entityId,
            riskScore: (float)($entity->risk_score ?? 0.0),
            isPivotOrigin: $isPivotOrigin,
            createdBy: $createdBy
        );
        $nodes[] = $node;

        if ($currentDepth < $maxDepth && $entity) {
            $rels = DB::table('entity_relationships')
                ->where('source_entity_id', $entity->id)
                ->orWhere('target_entity_id', $entity->id)
                ->orderByDesc('observation_count')
                ->limit(20)
                ->get();

            foreach ($rels as $rel) {
                $nextId = ($rel->source_entity_id === $entity->id)
                    ? $rel->target_entity_id
                    : $rel->source_entity_id;

                $nextEntity = Entity::find($nextId);
                if (!$nextEntity) {
                    continue;
                }

                $nextNode = $this->traverseEntity(
                    $nextEntity->entity_key, $sessionId, $maxDepth, $currentDepth + 1,
                    $visited, $nodes, $edges, $createdBy
                );

                if ($nextNode) {
                    $fromId = ($rel->source_entity_id === $entity->id)
                        ? $node->node_id
                        : $nextNode->node_id;
                    $toId = ($rel->source_entity_id === $entity->id)
                        ? $nextNode->node_id
                        : $node->node_id;

                    $edge = $this->addEdge(
                        $sessionId, $fromId, $toId,
                        $rel->relationship_type,
                        confidence: (float)($rel->confidence ?? 0.8),
                        evidenceSource: $rel->source_table,
                        evidenceId: $rel->source_event_id,
                        createdBy: $createdBy
                    );
                    $edges[] = $edge;
                }
            }
        }

        return $node;
    }

    // ─── Attack timeline reconstruction ─────────────────────────────────────

    public function addTimelineEvent(
        string  $investigationId,
        string  $eventCategory,
        string  $description,
        string  $sessionId,
        ?string $mitreT = null,
        ?string $mitreTactic = null,
        ?string $actorEntityId = null,
        ?string $targetEntityId = null,
        ?string $eventSource = null,
        ?string $sourceId = null,
        float   $confidence = 0.8,
        ?string $occurredAt = null,
        array   $attributes = [],
        ?int    $createdBy = null
    ): InvestigationTimelineEvent {
        return InvestigationTimelineEvent::create([
            'event_id'         => 'ite-' . Str::random(32),
            'investigation_id' => $investigationId,
            'session_id'       => $sessionId,
            'event_category'   => $eventCategory,
            'event_source'     => $eventSource,
            'source_id'        => $sourceId,
            'mitre_technique'  => $mitreT,
            'mitre_tactic'     => $mitreTactic,
            'description'      => $description,
            'actor_entity_id'  => $actorEntityId,
            'target_entity_id' => $targetEntityId,
            'confidence'       => $confidence,
            'is_advisory'      => true,
            'attributes'       => $attributes ?: null,
            'occurred_at'      => $occurredAt ?? now()->toDateTimeString(),
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);
    }

    public function getAttackTimeline(string $investigationId): Collection
    {
        return InvestigationTimelineEvent::where('investigation_id', $investigationId)
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Reconstruct attack timeline from cross_domain_correlations for an investigation.
     * Replay-safe — only reads existing correlations, no mutations.
     */
    public function reconstructTimelineFromCorrelations(
        string  $investigationId,
        string  $sessionId,
        array   $entityIds = [],
        ?int    $createdBy = null
    ): Collection {
        $q = CrossDomainCorrelation::orderBy('correlated_at');

        if ($entityIds) {
            $q->where(function ($query) use ($entityIds) {
                foreach ($entityIds as $eid) {
                    $query->orWhereJsonContains('entity_ids', $eid);
                }
            });
        }

        $correlations = $q->limit(100)->get();
        $events       = collect();

        foreach ($correlations as $corr) {
            $event = $this->addTimelineEvent(
                $investigationId,
                $corr->correlation_type ?? 'execution',
                $corr->description ?? 'Cross-domain correlation observed',
                $sessionId,
                mitreT: $corr->mitre_technique ?? null,
                mitreTactic: null,
                eventSource: 'cross_domain_correlations',
                sourceId: $corr->correlation_id,
                confidence: (float)($corr->confidence ?? 0.8),
                occurredAt: $corr->correlated_at,
                createdBy: $createdBy
            );
            $events->push($event);
        }

        return $events;
    }

    // ─── Retrospective hunt queries ──────────────────────────────────────────

    public function createRetrospectiveQuery(
        string  $name,
        string  $domain,
        array   $filters = [],
        ?string $investigationId = null,
        ?string $sessionId = null,
        ?string $description = null,
        ?string $timeRangeStart = null,
        ?string $timeRangeEnd = null,
        ?int    $createdBy = null
    ): RetrospectiveHuntQuery {
        return RetrospectiveHuntQuery::create([
            'query_id'         => 'rhq-' . Str::random(32),
            'investigation_id' => $investigationId,
            'session_id'       => $sessionId,
            'name'             => $name,
            'description'      => $description,
            'domain'           => $domain,
            'filters'          => $filters ?: null,
            'time_range_start' => $timeRangeStart,
            'time_range_end'   => $timeRangeEnd,
            'status'           => 'draft',
            'is_advisory'      => true,
            'replay_safe'      => true,
            'created_by'       => $createdBy,
        ]);
    }

    public function executeRetrospectiveQuery(RetrospectiveHuntQuery $query): array
    {
        $results = $this->hunting->hunt(
            $query->domain,
            $query->filters ?? [],
            limit: 500
        );

        $query->update([
            'status'           => 'executed',
            'result_count'     => $results->count(),
            'last_executed_at' => now(),
        ]);

        return [
            'query_id'     => $query->query_id,
            'domain'       => $query->domain,
            'result_count' => $results->count(),
            'results'      => $results->take(100)->values()->all(),
            'is_advisory'  => true,
            'replay_safe'  => true,
        ];
    }

    public function archiveQuery(RetrospectiveHuntQuery $query): void
    {
        $query->update(['status' => 'archived']);
    }

    // ─── Evidence linking (append-only) ─────────────────────────────────────

    public function linkEvidence(
        string  $investigationId,
        string  $evidenceType,
        string  $evidenceId,
        ?string $sessionId = null,
        ?string $nodeId = null,
        ?string $edgeId = null,
        ?string $evidenceTable = null,
        ?string $rationale = null,
        ?int    $createdBy = null
    ): InvestigationEvidenceLink {
        return InvestigationEvidenceLink::create([
            'link_id'          => 'iel-' . Str::random(32),
            'investigation_id' => $investigationId,
            'session_id'       => $sessionId,
            'node_id'          => $nodeId,
            'edge_id'          => $edgeId,
            'evidence_type'    => $evidenceType,
            'evidence_id'      => $evidenceId,
            'evidence_table'   => $evidenceTable,
            'rationale'        => $rationale,
            'is_advisory'      => true,
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);
    }

    public function getEvidenceLinks(string $investigationId): Collection
    {
        return InvestigationEvidenceLink::where('investigation_id', $investigationId)
            ->orderBy('created_at')
            ->get();
    }

    // ─── Bookmarks ───────────────────────────────────────────────────────────

    public function addBookmark(
        string  $sessionId,
        string  $label,
        ?string $investigationId = null,
        ?string $pivotType = null,
        ?string $pivotValue = null,
        ?string $pivotDomain = null,
        ?string $notes = null,
        ?int    $createdBy = null
    ): InvestigationBookmark {
        return InvestigationBookmark::create([
            'bookmark_id'      => 'ibk-' . Str::random(32),
            'session_id'       => $sessionId,
            'investigation_id' => $investigationId,
            'label'            => $label,
            'notes'            => $notes,
            'pivot_type'       => $pivotType,
            'pivot_value'      => $pivotValue,
            'pivot_domain'     => $pivotDomain,
            'is_pinned'        => false,
            'created_by'       => $createdBy,
        ]);
    }

    public function pinBookmark(InvestigationBookmark $bookmark): void
    {
        $bookmark->update(['is_pinned' => true]);
    }

    public function getBookmarks(string $sessionId): Collection
    {
        return InvestigationBookmark::where('session_id', $sessionId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();
    }

    // ─── Dashboard stats ─────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_sessions'          => InvestigationSession::count(),
            'active_sessions'         => InvestigationSession::where('status', 'active')->count(),
            'total_graph_nodes'       => InvestigationGraphNode::count(),
            'total_graph_edges'       => InvestigationGraphEdge::count(),
            'total_timeline_events'   => InvestigationTimelineEvent::count(),
            'total_evidence_links'    => InvestigationEvidenceLink::count(),
            'total_retrospective_queries' => RetrospectiveHuntQuery::count(),
            'executed_queries'        => RetrospectiveHuntQuery::where('status', 'executed')->count(),
            'advisory_only'           => true,
            'autonomous_actions'      => false,
        ];
    }
}
