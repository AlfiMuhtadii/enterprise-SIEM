<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointExecutionChain;
use App\Models\EndpointNetworkCorrelation;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessEntry;
use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\SecurityAlert;
use App\Models\ThreatHunt;
use App\Models\ThreatHuntQuery;
use App\Models\ThreatHuntResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Threat Hunting & Investigation Query Engine Phase 1.
 *
 * Advisory-only, non-destructive. All hunts are append-only records.
 * No raw SQL execution, no arbitrary regex, no shell execution.
 * Query fields and operators are strictly allowlisted per domain.
 */
class ThreatHuntingService
{
    // -----------------------------------------------------------------------
    // Safety bounds
    // -----------------------------------------------------------------------

    public const MAX_RESULTS            = 500;
    public const MAX_QUERY_WINDOW_DAYS  = 30;
    public const MAX_GRAPH_DEPTH        = 5;
    public const DEFAULT_MAX_RESULTS    = 100;

    // -----------------------------------------------------------------------
    // Supported query domains and their allowlisted fields
    // -----------------------------------------------------------------------

    public const SUPPORTED_DOMAINS = [
        'processes', 'persistence_items', 'execution_chains',
        'beacon_patterns', 'behavioral_findings', 'hosts',
        'network_correlations', 'alerts',
        'cross_domain_correlations',
        'endpoint_stream_events',
    ];

    private const DOMAIN_FIELDS = [
        'processes' => [
            'process_name'        => ['=', 'contains'],
            'parent_process_name' => ['=', 'contains'],
            'command_line'        => ['contains'],
            'user'                => ['='],
            'is_shell'            => ['='],
            'is_long_lived'       => ['='],
            'is_suspicious'       => ['='],
            'duration_seconds'    => ['>=', '<=', '>'],
            'trace_id'            => ['='],
        ],
        'persistence_items' => [
            'item_type'  => ['='],
            'item_key'   => ['=', 'contains'],
            'item_name'  => ['contains'],
            'item_path'  => ['contains'],
            'is_new'     => ['='],
        ],
        'execution_chains' => [
            'chain_length'         => ['>=', '<='],
            'chain_score'          => ['>='],
            'involves_shell'       => ['='],
            'involves_outbound'    => ['='],
            'involves_persistence' => ['='],
            'trace_id'             => ['='],
        ],
        'beacon_patterns' => [
            'process_name'              => ['=', 'contains'],
            'remote_ip'                 => ['='],
            'remote_port'               => ['='],
            'connection_count'          => ['>='],
            'destination_reuse_score'   => ['>='],
        ],
        'behavioral_findings' => [
            'finding_type' => ['='],
            'severity'     => ['='],
            'confidence'   => ['>='],
            'title'        => ['contains'],
            'trace_id'     => ['='],
        ],
        'hosts' => [
            'hostname'     => ['=', 'contains'],
            'agent_id'     => ['='],
            'platform'     => ['='],
            'health_state' => ['='],
        ],
        'network_correlations' => [
            'process_name'           => ['=', 'contains'],
            'remote_ip'              => ['='],
            'remote_port'            => ['='],
            'correlation_confidence' => ['>='],
        ],
        'alerts' => [
            'alert_type'  => ['=', 'contains'],
            'actor_key'   => ['='],
            'source_ip'   => ['='],
            'severity'    => ['='],
        ],
        'cross_domain_correlations' => [
            'correlation_type'     => ['='],
            'actor_key'            => ['='],
            'primary_entity_key'   => ['=', 'contains'],
            'primary_entity_type'  => ['='],
            'confidence_score'     => ['>='],
            'trace_id'             => ['='],
        ],
        'endpoint_stream_events' => [
            'event_type'     => ['='],
            'agent_id'       => ['='],
            'host_id'        => ['=', 'contains'],
            'process_name'   => ['=', 'contains'],
            'parent_name'    => ['=', 'contains'],
            'connection_dest'=> ['=', 'contains'],
            'connection_port'=> ['='],
            'persistence_key'=> ['=', 'contains'],
            'persistence_type'=> ['='],
            'sequence_id'    => ['>=', '<='],
            'trace_id'       => ['='],
        ],
    ];

    private const DOMAIN_MODEL_MAP = [
        'processes'           => EndpointProcessEntry::class,
        'persistence_items'   => EndpointPersistenceItem::class,
        'execution_chains'    => EndpointExecutionChain::class,
        'beacon_patterns'     => EndpointBeaconPattern::class,
        'behavioral_findings' => EndpointBehavioralFinding::class,
        'hosts'               => EndpointAgent::class,
        'network_correlations'=> EndpointNetworkCorrelation::class,
        'alerts'                   => null, // handled separately via security_alerts
        'cross_domain_correlations'=> \App\Models\CrossDomainCorrelation::class,
        'endpoint_stream_events'   => \App\Models\EndpointStreamEvent::class,
    ];

    private const DOMAIN_TIME_COLUMN = [
        'processes'           => 'created_at',
        'persistence_items'   => 'last_seen_at',
        'execution_chains'    => 'detected_at',
        'beacon_patterns'     => 'detected_at',
        'behavioral_findings' => 'detected_at',
        'hosts'               => 'updated_at',
        'network_correlations'=> 'created_at',
        'alerts'                    => 'detected_at',
        'cross_domain_correlations' => 'created_at',
        'endpoint_stream_events'    => 'occurred_at',
    ];

    // -----------------------------------------------------------------------
    // Hunt execution
    // -----------------------------------------------------------------------

    /**
     * Execute a structured hunt, persist it, and return the hunt record.
     * Advisory-only — no side effects on source telemetry.
     */
    public function executeHunt(array $data, ?User $user = null): ThreatHunt
    {
        $domain    = $data['query_domain'] ?? 'processes';
        $filters   = $data['query_filters'] ?? [];
        $timeStart = $data['time_range_start'] ?? null;
        $timeEnd   = $data['time_range_end'] ?? null;
        $maxResults= min((int) ($data['max_results'] ?? self::DEFAULT_MAX_RESULTS), self::MAX_RESULTS);
        $title     = $data['title'] ?? 'Untitled Hunt';
        $traceId   = $data['trace_id'] ?? (string) Str::uuid();
        $scope     = $data['replay_scope'] ?? ThreatHunt::SCOPE_LIVE;

        // Validate and clamp time range
        [$timeStart, $timeEnd] = $this->validateTimeRange($timeStart, $timeEnd);

        // Validate domain + filters (throws on invalid input)
        $this->validateQueryFilters($domain, $filters);

        $hunt = ThreatHunt::create([
            'hunt_id'     => ThreatHunt::generateHuntId(),
            'title'       => substr($title, 0, 255),
            'description' => $data['description'] ?? null,
            'created_by'  => $user?->id,
            'executed_at' => now(),
            'replay_scope'=> $scope,
            'status'      => ThreatHunt::STATUS_COMPLETED,
            'result_count'=> 0,
            'trace_id'    => $traceId,
        ]);

        ThreatHuntQuery::create([
            'hunt_id'          => $hunt->id,
            'query_domain'     => $domain,
            'query_filters'    => $filters,
            'time_range_start' => $timeStart,
            'time_range_end'   => $timeEnd,
            'max_results'      => $maxResults,
        ]);

        // Execute query
        $results = $this->executeQuery($domain, $filters, $timeStart, $timeEnd, $maxResults);

        // Store results as append-only snapshots
        $resultType = $this->domainToResultType($domain);
        $now = now();
        $insertRows = [];
        foreach ($results as $record) {
            $recordArr = is_array($record) ? $record : $record->toArray();
            $insertRows[] = [
                'hunt_id'          => $hunt->id,
                'result_type'      => $resultType,
                'result_source_id' => $recordArr['id'] ?? null,
                'result_data'      => json_encode($this->sanitizeResultData($recordArr)),
                'trace_id'         => $traceId,
                'created_at'       => $now,
            ];
        }

        if ($insertRows) {
            DB::table('threat_hunt_results')->insert($insertRows);
        }

        $status = count($insertRows) > 0 ? ThreatHunt::STATUS_COMPLETED : ThreatHunt::STATUS_EMPTY;
        $hunt->update(['result_count' => count($insertRows), 'status' => $status]);

        return $hunt->fresh();
    }

    /**
     * Execute a query against the specified domain.
     * All filtering is done via Eloquent query builder — no raw SQL.
     */
    public function executeQuery(
        string $domain,
        array $filters,
        ?string $timeStart,
        ?string $timeEnd,
        int $maxResults = self::DEFAULT_MAX_RESULTS
    ): Collection {
        $this->validateQueryFilters($domain, $filters);
        $maxResults = min($maxResults, self::MAX_RESULTS);

        if ($domain === 'alerts') {
            return $this->queryAlerts($filters, $timeStart, $timeEnd, $maxResults);
        }

        $modelClass = self::DOMAIN_MODEL_MAP[$domain];
        $timeColumn = self::DOMAIN_TIME_COLUMN[$domain] ?? 'created_at';

        $query = $modelClass::query();

        // Apply allowlisted filters
        foreach ($filters as $filter) {
            $field    = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '=';
            $value    = $filter['value'] ?? '';

            if (!isset(self::DOMAIN_FIELDS[$domain][$field])) {
                continue;
            }
            if (!in_array($operator, self::DOMAIN_FIELDS[$domain][$field], true)) {
                continue;
            }

            if ($operator === 'contains') {
                $query->where($field, 'like', '%' . addcslashes((string) $value, '%_\\') . '%');
            } else {
                $query->where($field, $operator, $value);
            }
        }

        // Apply time range
        if ($timeStart) {
            $query->where($timeColumn, '>=', $timeStart);
        }
        if ($timeEnd) {
            $query->where($timeColumn, '<=', $timeEnd);
        }

        return $query->orderByDesc($timeColumn)->limit($maxResults)->get();
    }

    // -----------------------------------------------------------------------
    // Behavioral pivots
    // -----------------------------------------------------------------------

    /**
     * Pivot on a host: return processes, persistence items, findings, beacon patterns.
     */
    public function pivotHost(string $agentId): array
    {
        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        if (!$agent) {
            return ['error' => 'agent_not_found'];
        }

        $latestSnapshot = \App\Models\EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')->first();

        return [
            'agent'            => $agent->only(['agent_id', 'hostname', 'platform', 'health_state']),
            'process_count'    => $latestSnapshot?->process_count ?? 0,
            'shell_count'      => $latestSnapshot?->shell_count ?? 0,
            'persistence_items'=> EndpointPersistenceItem::where('agent_id', $agent->id)->count(),
            'findings'         => EndpointBehavioralFinding::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(10)
                ->get(['finding_id', 'finding_type', 'severity', 'confidence', 'detected_at'])->toArray(),
            'beacon_patterns'  => EndpointBeaconPattern::where('agent_id', $agent->id)
                ->orderByDesc('detected_at')->limit(5)
                ->get(['pattern_id', 'process_name', 'remote_ip', 'connection_count'])->toArray(),
        ];
    }

    /**
     * Pivot on a process name: return all occurrences, ancestry, outbound activity.
     */
    public function pivotProcess(string $processName, ?int $agentId = null): array
    {
        $query = EndpointProcessEntry::where('process_name', $processName);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        $entries = $query->orderByDesc('created_at')->limit(20)->get();
        $outbound = EndpointNetworkCorrelation::where('process_name', $processName)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->orderByDesc('created_at')->limit(20)->get();

        return [
            'process_name'  => $processName,
            'occurrences'   => $entries->map(fn ($e) => [
                'pid'           => $e->pid,
                'parent_name'   => $e->parent_process_name,
                'user'          => $e->user,
                'is_shell'      => $e->is_shell,
                'is_suspicious' => $e->is_suspicious,
                'duration_s'    => $e->duration_seconds,
            ])->all(),
            'outbound_connections' => $outbound->map(fn ($c) => [
                'remote_ip'   => $c->remote_ip,
                'remote_port' => $c->remote_port,
                'proto'       => $c->proto,
                'confidence'  => $c->correlation_confidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a persistence item: return related processes and findings.
     */
    public function pivotPersistence(string $itemKey, ?int $agentId = null): array
    {
        $query = EndpointPersistenceItem::where('item_key', $itemKey);
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }
        $item = $query->first();
        if (!$item) {
            return ['error' => 'persistence_item_not_found'];
        }

        $relatedFindings = EndpointBehavioralFinding::where('agent_id', $item->agent_id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION)
            ->orderByDesc('detected_at')->limit(10)->get();

        return [
            'item_key'    => $item->item_key,
            'item_type'   => $item->item_type,
            'item_name'   => $item->item_name,
            'is_new'      => $item->is_new,
            'first_seen'  => $item->first_seen_at?->toIso8601String(),
            'last_seen'   => $item->last_seen_at?->toIso8601String(),
            'related_findings' => $relatedFindings->map(fn ($f) => [
                'finding_id' => $f->finding_id,
                'confidence' => $f->confidence,
                'evidence'   => $f->evidence,
            ])->all(),
        ];
    }

    /**
     * Pivot on a trace_id: return all telemetry correlated by trace.
     */
    public function pivotTrace(string $traceId): array
    {
        $sanitizedTrace = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $traceId), 0, 120);
        if (!$sanitizedTrace) {
            return ['error' => 'invalid_trace_id'];
        }

        return [
            'trace_id' => $sanitizedTrace,
            'snapshots'=> \App\Models\EndpointProcessSnapshot::where('trace_id', $sanitizedTrace)
                ->get(['snapshot_id', 'agent_id', 'collected_at', 'process_count'])->toArray(),
            'findings' => EndpointBehavioralFinding::where('trace_id', $sanitizedTrace)
                ->get(['finding_id', 'finding_type', 'severity'])->toArray(),
            'chains'   => EndpointExecutionChain::where('trace_id', $sanitizedTrace)
                ->get(['chain_id', 'chain_score', 'chain_length'])->toArray(),
        ];
    }

    /**
     * Pivot on an entity: return its relationships via bounded graph traversal.
     */
    public function pivotEntity(int $entityId, int $depth = 2): array
    {
        $depth = min($depth, self::MAX_GRAPH_DEPTH);
        $entity = Entity::find($entityId);
        if (!$entity) {
            return ['error' => 'entity_not_found'];
        }

        return [
            'entity'  => $entity->only(['id', 'entity_type', 'entity_key', 'display_name', 'risk_score']),
            'graph'   => $this->graphTraversal($entityId, $depth),
        ];
    }

    // -----------------------------------------------------------------------
    // Graph traversal — depth-limited BFS, deterministic
    // -----------------------------------------------------------------------

    /**
     * Depth-limited BFS traversal of the entity relationship graph.
     * Deterministic (ordered by relationship ID), replay-safe (read-only).
     */
    public function graphTraversal(int $rootId, int $depth = 3): array
    {
        $depth   = min($depth, self::MAX_GRAPH_DEPTH);
        $visited = [$rootId => true];
        $nodes   = [];
        $edges   = [];
        $queue   = [[$rootId, 0]];

        while (!empty($queue)) {
            [$nodeId, $level] = array_shift($queue);

            if ($level >= $depth) {
                continue;
            }

            $entity = Entity::find($nodeId);
            if ($entity) {
                $nodes[$nodeId] = $entity->only(['id', 'entity_type', 'entity_key', 'display_name']);
            }

            // Get relationships (capped at 20 per node to prevent explosion)
            $rels = EntityRelationship::where('source_entity_id', $nodeId)
                ->orWhere('target_entity_id', $nodeId)
                ->orderBy('id')
                ->limit(20)
                ->get();

            foreach ($rels as $rel) {
                $edgeKey = min($rel->source_entity_id, $rel->target_entity_id)
                    . ':' . max($rel->source_entity_id, $rel->target_entity_id)
                    . ':' . $rel->relationship_type;

                if (!isset($edges[$edgeKey])) {
                    $edges[$edgeKey] = [
                        'source'   => $rel->source_entity_id,
                        'target'   => $rel->target_entity_id,
                        'type'     => $rel->relationship_type,
                        'count'    => $rel->observation_count,
                    ];
                }

                $otherId = $rel->source_entity_id === $nodeId
                    ? $rel->target_entity_id
                    : $rel->source_entity_id;

                if (!isset($visited[$otherId])) {
                    $visited[$otherId] = true;
                    $queue[] = [$otherId, $level + 1];
                }
            }
        }

        return [
            'root_id'  => $rootId,
            'nodes'    => array_values($nodes),
            'edges'    => array_values($edges),
            'depth'    => $depth,
            'node_count'=> count($nodes),
        ];
    }

    // -----------------------------------------------------------------------
    // Replay — re-execute a historical hunt with same parameters
    // -----------------------------------------------------------------------

    /**
     * Replay a historical hunt with the same query parameters.
     * Creates a new hunt record (append-only — never mutates the original).
     */
    public function replayHunt(ThreatHunt $originalHunt, ?User $user = null): ThreatHunt
    {
        $originalQuery = $originalHunt->queries()->first();
        if (!$originalQuery) {
            throw new \InvalidArgumentException("Hunt {$originalHunt->hunt_id} has no query to replay.");
        }

        return $this->executeHunt([
            'title'            => "Replay of {$originalHunt->hunt_id}: {$originalHunt->title}",
            'description'      => "Retrospective replay. Original hunt: {$originalHunt->hunt_id}",
            'query_domain'     => $originalQuery->query_domain,
            'query_filters'    => $originalQuery->query_filters ?? [],
            'time_range_start' => $originalQuery->time_range_start?->toIso8601String(),
            'time_range_end'   => $originalQuery->time_range_end?->toIso8601String(),
            'max_results'      => $originalQuery->max_results,
            'replay_scope'     => ThreatHunt::SCOPE_REPLAY,
            'trace_id'         => (string) Str::uuid(),
        ], $user);
    }

    // -----------------------------------------------------------------------
    // Query validation (safety enforcement)
    // -----------------------------------------------------------------------

    /**
     * Validate that all filters use allowlisted fields and operators.
     * Throws \InvalidArgumentException on any violation.
     * NEVER allows raw SQL expressions, field injection, or unsupported domains.
     */
    public function validateQueryFilters(string $domain, array $filters): void
    {
        if (!in_array($domain, self::SUPPORTED_DOMAINS, true)) {
            throw new \InvalidArgumentException("Unsupported query domain: '{$domain}'.");
        }

        $allowed = self::DOMAIN_FIELDS[$domain] ?? [];

        foreach ($filters as $filter) {
            $field    = $filter['field'] ?? '';
            $operator = $filter['operator'] ?? '';

            if (!isset($allowed[$field])) {
                throw new \InvalidArgumentException(
                    "Field '{$field}' is not allowlisted for domain '{$domain}'."
                );
            }
            if (!in_array($operator, $allowed[$field], true)) {
                throw new \InvalidArgumentException(
                    "Operator '{$operator}' is not allowed for field '{$field}' in domain '{$domain}'."
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Hunt history queries
    // -----------------------------------------------------------------------

    public function getHuntHistory(int $limit = 50): Collection
    {
        return ThreatHunt::with('creator')
            ->orderByDesc('executed_at')
            ->limit($limit)
            ->get();
    }

    public function getHuntWithResults(string $huntId): ?ThreatHunt
    {
        return ThreatHunt::where('hunt_id', $huntId)
            ->with(['queries', 'results'])
            ->first();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateTimeRange(?string $start, ?string $end): array
    {
        $startCarbon = $start ? \Carbon\Carbon::parse($start) : null;
        $endCarbon   = $end   ? \Carbon\Carbon::parse($end)   : null;

        if ($startCarbon && $endCarbon) {
            $windowDays = $startCarbon->diffInDays($endCarbon, false);
            if ($windowDays > self::MAX_QUERY_WINDOW_DAYS) {
                $startCarbon = $endCarbon->copy()->subDays(self::MAX_QUERY_WINDOW_DAYS);
            }
        }

        return [
            $startCarbon?->toDateTimeString(),
            $endCarbon?->toDateTimeString(),
        ];
    }

    private function domainToResultType(string $domain): string
    {
        return match ($domain) {
            'processes'           => ThreatHuntResult::TYPE_PROCESS_ENTRY,
            'persistence_items'   => ThreatHuntResult::TYPE_PERSISTENCE_ITEM,
            'behavioral_findings' => ThreatHuntResult::TYPE_BEHAVIORAL_FINDING,
            'execution_chains'    => ThreatHuntResult::TYPE_EXECUTION_CHAIN,
            'beacon_patterns'     => ThreatHuntResult::TYPE_BEACON_PATTERN,
            'network_correlations'=> ThreatHuntResult::TYPE_NETWORK_CORRELATION,
            'hosts'               => ThreatHuntResult::TYPE_HOST,
            'cross_domain_correlations' => 'cross_domain_correlation',
            default                     => 'generic',
        };
    }

    private function sanitizeResultData(array $data): array
    {
        // Remove very large fields to keep snapshot storage bounded
        unset($data['chain_steps'], $data['evidence']);
        return array_filter($data, fn ($v) => !is_array($v) || count($v) < 50);
    }

    private function queryAlerts(array $filters, ?string $timeStart, ?string $timeEnd, int $limit): Collection
    {
        $query = \Illuminate\Support\Facades\DB::table('security_alerts');
        $allowed = self::DOMAIN_FIELDS['alerts'] ?? [];

        foreach ($filters as $filter) {
            $field    = $filter['field']    ?? '';
            $operator = $filter['operator'] ?? '=';
            $value    = $filter['value']    ?? '';

            if (!isset($allowed[$field])) continue;
            if (!in_array($operator, $allowed[$field], true)) continue;

            if ($operator === 'contains') {
                $query->where($field, 'like', '%' . addcslashes((string)$value, '%_\\') . '%');
            } else {
                $query->where($field, $operator, $value);
            }
        }

        if ($timeStart) $query->where('detected_at', '>=', $timeStart);
        if ($timeEnd)   $query->where('detected_at', '<=', $timeEnd);

        return collect($query->orderByDesc('detected_at')->limit($limit)->get());
    }

    // -----------------------------------------------------------------------
    // Cross-domain pivot methods — Phase 1 (2026-05-18)
    // All read-only. Advisory-only. No mutations.
    // -----------------------------------------------------------------------

    public function pivotIdentityToHost(string $actorKey): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9@.\-_]/', '', $actorKey), 0, 255);
        if (!$sanitized) {
            return ['error' => 'invalid_actor_key'];
        }

        $correlations = \App\Models\CrossDomainCorrelation::where('actor_key', $sanitized)
            ->orderByDesc('created_at')->limit(20)
            ->get(['correlation_id', 'correlation_type', 'confidence_score',
                   'attack_stages', 'involved_hosts', 'time_window_start', 'created_at']);

        $alerts = DB::table('security_alerts')
            ->where('actor_key', $sanitized)
            ->orderByDesc('detected_at')->limit(10)
            ->get(['alert_id', 'alert_type', 'severity', 'detected_at'])->all();

        return [
            'actor_key'       => $sanitized,
            'correlations'    => $correlations->all(),
            'identity_alerts' => $alerts,
            'advisory_note'   => 'Advisory-only. Cross-domain pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotMultiHostDestination(string $ip): array
    {
        $sanitized = substr(preg_replace('/[^a-zA-Z0-9:.\-]/', '', $ip), 0, 45);
        if (!$sanitized) {
            return ['error' => 'invalid_ip'];
        }

        $beacons = EndpointBeaconPattern::where('remote_ip', $sanitized)
            ->orderByDesc('detected_at')->limit(20)
            ->get(['pattern_id', 'agent_id', 'process_name', 'connection_count', 'detected_at'])->all();

        $correlations = \App\Models\CrossDomainCorrelation::where('primary_entity_key', $sanitized)
            ->where('correlation_type', \App\Models\CrossDomainCorrelation::TYPE_MULTI_HOST)
            ->orderByDesc('created_at')->limit(10)
            ->get(['correlation_id', 'confidence_score', 'involved_hosts', 'created_at'])->all();

        return [
            'ip'           => $sanitized,
            'beacons'      => $beacons,
            'correlations' => $correlations,
            'advisory_note'=> 'Advisory-only. Multi-host destination pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotAttackStage(string $stage): array
    {
        if (!in_array($stage, \App\Models\AttackStageTimeline::STAGES, true)) {
            return ['error' => 'invalid_stage'];
        }

        $timelines = DB::table('attack_stage_timelines')
            ->where('stage', $stage)
            ->orderByDesc('created_at')->limit(30)
            ->get(['timeline_id', 'correlation_id', 'stage_confidence', 'first_seen_at', 'last_seen_at'])->all();

        $corrIds = array_unique(array_column($timelines, 'correlation_id'));
        $correlations = $corrIds
            ? \App\Models\CrossDomainCorrelation::whereIn('id', $corrIds)
                ->get(['correlation_id', 'correlation_type', 'actor_key', 'confidence_score', 'created_at'])->all()
            : [];

        return [
            'stage'        => $stage,
            'timelines'    => $timelines,
            'correlations' => $correlations,
            'advisory_note'=> 'Advisory-only. Attack stage pivot is retrospective and non-destructive.',
        ];
    }

    public function pivotCrossDomainTrace(string $traceId): array
    {
        return app(\App\Services\CrossDomainCorrelationService::class)->stitchCrossDomainTrace($traceId);
    }

    // -----------------------------------------------------------------------
    // Streaming pivot methods — live hunt pivots, advisory-only
    // No autonomous hunt-triggered response. No auto-containment.
    // -----------------------------------------------------------------------

    /**
     * Pivot stream events by process name — short-window correlation.
     */
    public function pivotStreamByProcess(string $processName, int $windowMinutes = 60): array
    {
        $since  = now()->subMinutes($windowMinutes);
        $events = \App\Models\EndpointStreamEvent::where('process_name', $processName)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('sequence_id')
            ->limit(200)
            ->get();

        $agents = $events->pluck('agent_id')->unique()->values()->toArray();
        $types  = $events->groupBy('event_type')->map->count();

        return [
            'process_name'   => $processName,
            'window_minutes' => $windowMinutes,
            'event_count'    => $events->count(),
            'agents_affected'=> $agents,
            'event_type_breakdown' => $types,
            'advisory_only'  => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Pivot stream events by trace ID — real-time trace stitching.
     */
    public function pivotStreamTrace(string $traceId): array
    {
        $events = \App\Models\EndpointStreamEvent::where('trace_id', $traceId)
            ->orderBy('sequence_id')
            ->limit(500)
            ->get();

        $sequenceRange = $events->isEmpty()
            ? null
            : ['min' => $events->first()->sequence_id, 'max' => $events->last()->sequence_id];

        return [
            'trace_id'       => $traceId,
            'event_count'    => $events->count(),
            'sequence_range' => $sequenceRange,
            'event_types'    => $events->pluck('event_type')->unique()->values()->toArray(),
            'agents'         => $events->pluck('agent_id')->unique()->values()->toArray(),
            'events'         => $events->take(50)->toArray(),
            'advisory_only'  => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Rapid beacon investigation — identify repeated execution patterns in streaming events.
     */
    public function pivotStreamBeaconInvestigation(string $agentId, int $windowMinutes = 30): array
    {
        $streamSvc = app(\App\Services\EndpointStreamingService::class);
        $findings  = $streamSvc->detectStreamBeaconPattern($agentId, $windowMinutes * 60);
        $gaps      = $streamSvc->detectSequenceGaps($agentId);

        return [
            'agent_id'       => $agentId,
            'window_minutes' => $windowMinutes,
            'beacon_findings'=> $findings,
            'sequence_gaps'  => $gaps,
            'advisory_only'  => true,
            'no_autonomous_response' => true,
        ];
    }

    /**
     * Stream execution chain inspection — short-window execution chain via streaming events.
     */
    public function pivotStreamExecutionChain(string $agentId, int $windowSeconds = 300): array
    {
        $since  = now()->subSeconds($windowSeconds);
        $events = \App\Models\EndpointStreamEvent::where('agent_id', $agentId)
            ->whereIn('event_type', [
                \App\Models\EndpointStreamEvent::TYPE_PROCESS_STARTED,
                \App\Models\EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED,
            ])
            ->where('occurred_at', '>=', $since)
            ->orderBy('sequence_id')
            ->get();

        // Build parent→child chain map
        $chainMap = [];
        foreach ($events as $ev) {
            $ppid = $ev->parent_pid;
            $pid  = $ev->process_pid;
            if ($ppid && $pid) {
                $chainMap[$ppid][] = [
                    'pid'       => $pid,
                    'name'      => $ev->process_name,
                    'type'      => $ev->event_type,
                    'occurred'  => $ev->occurred_at?->toIso8601String(),
                    'sequence'  => $ev->sequence_id,
                ];
            }
        }

        return [
            'agent_id'          => $agentId,
            'window_seconds'    => $windowSeconds,
            'total_events'      => $events->count(),
            'execution_chain'   => $chainMap,
            'advisory_only'     => true,
            'no_autonomous_response' => true,
        ];
    }
}
