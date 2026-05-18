<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\EntityObservation;
use App\Models\EntityRelationship;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EntityGraphService
{
    /**
     * Upsert an entity by (type, key). Append-safe — never overwrites history.
     * Increments observation_count on each subsequent call.
     * Updates first_seen_at only when seenAt is earlier; last_seen_at only when later.
     */
    public function upsertEntity(
        string  $type,
        string  $key,
        string  $displayName = '',
        ?string $seenAt = null,
        ?float  $riskScore = null,
        array   $metadata = []
    ): int {
        $seenAt = $seenAt ?? now()->toDateTimeString();

        $entity = Entity::firstOrCreate(
            ['entity_type' => $type, 'entity_key' => $key],
            [
                'display_name'      => $displayName ?: $key,
                'first_seen_at'     => $seenAt,
                'last_seen_at'      => $seenAt,
                'observation_count' => 1,
                'risk_score'        => $riskScore,
                'metadata'          => $metadata ?: null,
            ]
        );

        if (!$entity->wasRecentlyCreated) {
            // Use query builder directly to bypass Eloquent cast on DB::raw expressions
            DB::table('entities')->where('id', $entity->id)->increment('observation_count');

            $seenCarbon  = Carbon::parse($seenAt);
            $fieldUpdates = [];

            if (!$entity->first_seen_at || $seenCarbon->lt($entity->first_seen_at)) {
                $fieldUpdates['first_seen_at'] = $seenAt;
            }
            if (!$entity->last_seen_at || $seenCarbon->gt($entity->last_seen_at)) {
                $fieldUpdates['last_seen_at'] = $seenAt;
            }
            if ($riskScore !== null && $riskScore > ($entity->risk_score ?? 0.0)) {
                $fieldUpdates['risk_score'] = $riskScore;
            }
            if ($displayName && !$entity->display_name) {
                $fieldUpdates['display_name'] = $displayName;
            }

            if ($fieldUpdates) {
                DB::table('entities')->where('id', $entity->id)->update($fieldUpdates);
            }
        }

        return $entity->id;
    }

    /**
     * Upsert a relationship between two entities. Append-safe.
     * Increments observation_count instead of creating duplicate rows.
     */
    public function upsertRelationship(
        int     $sourceId,
        int     $targetId,
        string  $type,
        ?string $seenAt = null,
        ?string $origin = null,
        ?string $sourceTable = null,
        ?string $sourceEventId = null,
        ?string $traceId = null,
        float   $confidence = 1.0
    ): void {
        $seenAt = $seenAt ?? now()->toDateTimeString();

        $rel = EntityRelationship::where('source_entity_id', $sourceId)
            ->where('target_entity_id', $targetId)
            ->where('relationship_type', $type)
            ->first();

        if ($rel) {
            DB::table('entity_relationships')->where('id', $rel->id)->increment('observation_count');

            $seenCarbon  = Carbon::parse($seenAt);
            $fieldUpdates = [];

            if (!$rel->first_seen_at || $seenCarbon->lt($rel->first_seen_at)) {
                $fieldUpdates['first_seen_at'] = $seenAt;
            }
            if (!$rel->last_seen_at || $seenCarbon->gt($rel->last_seen_at)) {
                $fieldUpdates['last_seen_at'] = $seenAt;
            }

            if ($fieldUpdates) {
                DB::table('entity_relationships')->where('id', $rel->id)->update($fieldUpdates);
            }
            return;
        }

        EntityRelationship::create([
            'source_entity_id'    => $sourceId,
            'target_entity_id'    => $targetId,
            'relationship_type'   => $type,
            'first_seen_at'       => $seenAt,
            'last_seen_at'        => $seenAt,
            'observation_count'   => 1,
            'confidence'          => $confidence,
            'relationship_origin' => $origin,
            'source_table'        => $sourceTable,
            'source_event_id'     => $sourceEventId,
            'trace_id'            => $traceId,
        ]);
    }

    /**
     * Append an observation. Always inserts — never updates existing rows.
     * This is the append-only history record.
     */
    public function appendObservation(
        int     $entityId,
        string  $observationType,
        string  $observedAt,
        ?string $sourceTable = null,
        ?string $sourceEventId = null,
        ?string $traceId = null,
        array   $context = []
    ): void {
        EntityObservation::create([
            'entity_id'        => $entityId,
            'observation_type' => $observationType,
            'source_table'     => $sourceTable,
            'source_event_id'  => $sourceEventId,
            'trace_id'         => $traceId,
            'observed_at'      => $observedAt,
            'context'          => $context ?: null,
        ]);
    }

    /**
     * Project entities and relationships from security_alerts.
     * Replay-safe: upserts only, preserves history, never overwrites.
     */
    public function projectFromAlerts(int $limit = 500): int
    {
        $rows = DB::table('security_alerts')
            ->select('alert_id', 'actor_key', 'ip', 'alert_type', 'severity', 'trace_id', 'detected_at')
            ->orderBy('detected_at')
            ->limit($limit)
            ->get();

        return DB::transaction(function () use ($rows) {
            $count = 0;

            foreach ($rows as $row) {
                $seenAt  = $row->detected_at;
                $traceId = $row->trace_id ?? null;
                $risk    = match ($row->severity ?? 'medium') {
                    'critical' => 9.0,
                    'high'     => 7.0,
                    'medium'   => 5.0,
                    default    => 3.0,
                };

                $alertEntityId = $this->upsertEntity(
                    'alert', $row->alert_id, $row->alert_type ?? $row->alert_id, $seenAt, $risk
                );
                $this->appendObservation(
                    $alertEntityId, 'alert_detected', $seenAt,
                    'security_alerts', $row->alert_id, $traceId
                );

                if ($traceId) {
                    $traceEntityId = $this->upsertEntity('trace', $traceId, $traceId, $seenAt);
                    $this->upsertRelationship(
                        $alertEntityId, $traceEntityId, 'trace_contains_entity',
                        $seenAt, 'security_alerts', 'security_alerts', $row->alert_id, $traceId
                    );
                }

                if ($row->actor_key) {
                    $userId = $this->upsertEntity(
                        'user', $row->actor_key, $row->actor_key, $seenAt, $risk
                    );
                    $this->appendObservation(
                        $userId, 'alert_actor', $seenAt,
                        'security_alerts', $row->alert_id, $traceId
                    );
                    $this->upsertRelationship(
                        $alertEntityId, $userId, 'alert_involves_entity',
                        $seenAt, 'security_alerts', 'security_alerts', $row->alert_id, $traceId
                    );
                }

                if ($row->ip) {
                    $ipId = $this->upsertEntity('ip', $row->ip, $row->ip, $seenAt, $risk);
                    $this->appendObservation(
                        $ipId, 'alert_source_ip', $seenAt,
                        'security_alerts', $row->alert_id, $traceId
                    );
                    $this->upsertRelationship(
                        $alertEntityId, $ipId, 'alert_involves_entity',
                        $seenAt, 'security_alerts', 'security_alerts', $row->alert_id, $traceId
                    );
                }

                $count++;
            }

            return $count;
        });
    }

    /**
     * Project entities from security_incidents.
     * Replay-safe: upserts only, preserves history.
     */
    public function projectFromIncidents(int $limit = 200): int
    {
        $rows = DB::table('security_incidents')
            ->select('incident_id', 'trace_id', 'first_seen_at', 'title')
            ->orderBy('first_seen_at')
            ->limit($limit)
            ->get();

        return DB::transaction(function () use ($rows) {
            $count = 0;

            foreach ($rows as $row) {
                $seenAt  = $row->first_seen_at;
                $traceId = $row->trace_id ?? null;

                $incidentEntityId = $this->upsertEntity(
                    'incident', $row->incident_id, $row->title ?? $row->incident_id, $seenAt
                );
                $this->appendObservation(
                    $incidentEntityId, 'incident_created', $seenAt,
                    'security_incidents', $row->incident_id, $traceId
                );

                if ($traceId) {
                    $traceEntityId = $this->upsertEntity('trace', $traceId, $traceId, $seenAt);
                    $this->upsertRelationship(
                        $incidentEntityId, $traceEntityId, 'trace_contains_entity',
                        $seenAt, 'security_incidents', 'security_incidents', $row->incident_id, $traceId
                    );
                }

                $count++;
            }

            return $count;
        });
    }

    // -------------------------------------------------------------------------
    // Query methods — read-only projection access
    // -------------------------------------------------------------------------

    public function search(string $q, string $type = ''): Collection
    {
        $query = DB::table('entities')
            ->where(function ($w) use ($q) {
                $w->where('entity_key', 'like', '%' . $q . '%')
                  ->orWhere('display_name', 'like', '%' . $q . '%');
            })
            ->orderByDesc('last_seen_at')
            ->limit(50);

        if ($type && in_array($type, Entity::TYPES, true)) {
            $query->where('entity_type', $type);
        }

        return $query->get();
    }

    public function getById(int $id): ?object
    {
        return DB::table('entities')->where('id', $id)->first();
    }

    public function getTimeline(int $entityId): Collection
    {
        return DB::table('entity_observations')
            ->where('entity_id', $entityId)
            ->orderBy('observed_at')
            ->get();
    }

    public function getRelationships(int $entityId): Collection
    {
        $outbound = DB::table('entity_relationships as r')
            ->join('entities as t', 't.id', '=', 'r.target_entity_id')
            ->where('r.source_entity_id', $entityId)
            ->select(
                'r.id', 'r.relationship_type', 'r.observation_count', 'r.confidence',
                'r.first_seen_at', 'r.last_seen_at', 'r.trace_id',
                'r.source_entity_id', 'r.target_entity_id',
                't.entity_type as peer_type', 't.entity_key as peer_key',
                't.display_name as peer_display', 't.id as peer_id',
                DB::raw("'outbound' as direction")
            )
            ->get();

        $inbound = DB::table('entity_relationships as r')
            ->join('entities as s', 's.id', '=', 'r.source_entity_id')
            ->where('r.target_entity_id', $entityId)
            ->select(
                'r.id', 'r.relationship_type', 'r.observation_count', 'r.confidence',
                'r.first_seen_at', 'r.last_seen_at', 'r.trace_id',
                'r.source_entity_id', 'r.target_entity_id',
                's.entity_type as peer_type', 's.entity_key as peer_key',
                's.display_name as peer_display', 's.id as peer_id',
                DB::raw("'inbound' as direction")
            )
            ->get();

        return $outbound->merge($inbound)->sortByDesc('last_seen_at')->values();
    }

    public function getAlerts(int $entityId): Collection
    {
        $entity = DB::table('entities')->where('id', $entityId)->first();
        if (!$entity) {
            return collect();
        }

        return match ($entity->entity_type) {
            'user'  => DB::table('security_alerts')
                ->where('actor_key', $entity->entity_key)
                ->orderByDesc('detected_at')->limit(50)->get(),
            'ip'    => DB::table('security_alerts')
                ->where('ip', $entity->entity_key)
                ->orderByDesc('detected_at')->limit(50)->get(),
            'alert' => DB::table('security_alerts')
                ->where('alert_id', $entity->entity_key)->get(),
            'trace' => DB::table('security_alerts')
                ->where('trace_id', $entity->entity_key)
                ->orderByDesc('detected_at')->limit(50)->get(),
            default => collect(),
        };
    }

    public function getIncidents(int $entityId): Collection
    {
        $entity = DB::table('entities')->where('id', $entityId)->first();
        if (!$entity) {
            return collect();
        }

        return match ($entity->entity_type) {
            'incident' => DB::table('security_incidents')
                ->where('incident_id', $entity->entity_key)->get(),
            'trace'    => DB::table('security_incidents')
                ->where('trace_id', $entity->entity_key)
                ->orderByDesc('first_seen_at')->limit(50)->get(),
            default    => collect(),
        };
    }

    /**
     * Build adjacency list for lightweight graph pivot view.
     * Bounded by depth and per-node relationship limit to prevent runaway queries.
     */
    public function buildAdjacency(int $entityId, int $depth = 1): array
    {
        $visited = [];
        $nodes   = [];
        $edges   = [];

        $this->traverse($entityId, $depth, $visited, $nodes, $edges);

        return [
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];
    }

    private function traverse(
        int    $entityId,
        int    $depth,
        array  &$visited,
        array  &$nodes,
        array  &$edges
    ): void {
        if (isset($visited[$entityId])) {
            return;
        }
        $visited[$entityId] = true;

        $entity = DB::table('entities')->where('id', $entityId)->first();
        if (!$entity) {
            return;
        }

        $nodes[$entityId] = [
            'id'           => $entityId,
            'entity_type'  => $entity->entity_type,
            'entity_key'   => $entity->entity_key,
            'display_name' => $entity->display_name ?? $entity->entity_key,
            'risk_score'   => $entity->risk_score,
            'obs_count'    => $entity->observation_count,
        ];

        if ($depth === 0) {
            return;
        }

        $rels = DB::table('entity_relationships')
            ->where('source_entity_id', $entityId)
            ->orWhere('target_entity_id', $entityId)
            ->limit(30)
            ->get();

        foreach ($rels as $rel) {
            $edgeKey = $rel->source_entity_id . '-' . $rel->target_entity_id . '-' . $rel->relationship_type;
            if (!isset($edges[$edgeKey])) {
                $edges[$edgeKey] = [
                    'source'            => (int) $rel->source_entity_id,
                    'target'            => (int) $rel->target_entity_id,
                    'relationship_type' => $rel->relationship_type,
                    'observation_count' => (int) $rel->observation_count,
                    'confidence'        => (float) $rel->confidence,
                    'trace_id'          => $rel->trace_id,
                ];
            }
            $peerId = ((int) $rel->source_entity_id === $entityId)
                ? (int) $rel->target_entity_id
                : (int) $rel->source_entity_id;

            $this->traverse($peerId, $depth - 1, $visited, $nodes, $edges);
        }
    }
}
