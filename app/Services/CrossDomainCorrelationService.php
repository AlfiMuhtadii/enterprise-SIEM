<?php

namespace App\Services;

use App\Models\AttackStageTimeline;
use App\Models\CorrelationEvidenceLink;
use App\Models\CrossDomainCorrelation;
use App\Models\EndpointAgent;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointPersistenceItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cross-Domain Correlation Engine Phase 1.
 *
 * Advisory-only. All correlations are append-only records.
 * All response is analyst-driven only. No execution actions.
 * No mutation of source telemetry, alerts, or findings.
 *
 * Correlates: endpoint behavior ↔ identity / cloud / SaaS activity.
 */
class CrossDomainCorrelationService
{
    public const CORRELATION_WINDOW_MINUTES = 60;
    public const MAX_CORRELATIONS_PER_RUN   = 100;

    // Identity alert types → attack stage
    private const IDENTITY_STAGE_MAP = [
        'IDENTITY_MFA_FAILURE_BURST'             => AttackStageTimeline::STAGE_INITIAL_ACCESS,
        'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES'  => AttackStageTimeline::STAGE_INITIAL_ACCESS,
        'IDENTITY_RISKY_IP_LOGIN'                => AttackStageTimeline::STAGE_INITIAL_ACCESS,
        'IDENTITY_UNUSUAL_LOGIN_SOURCE'          => AttackStageTimeline::STAGE_INITIAL_ACCESS,
        'IDENTITY_IMPOSSIBLE_TRAVEL'             => AttackStageTimeline::STAGE_LATERAL_MOVEMENT,
        'IDENTITY_PRIVILEGE_ESCALATION'          => AttackStageTimeline::STAGE_CREDENTIAL_ACCESS,
    ];

    // Cloud alert types → attack stage
    private const CLOUD_STAGE_MAP = [
        'CLOUD_NEW_ACCESS_KEY'                   => AttackStageTimeline::STAGE_CREDENTIAL_ACCESS,
        'CLOUD_UNUSUAL_API_ACTIVITY'             => AttackStageTimeline::STAGE_EXECUTION,
        'CLOUD_SUSPICIOUS_OBJECT_ACCESS'         => AttackStageTimeline::STAGE_EXFILTRATION,
        'CLOUD_MASS_DOWNLOAD'                    => AttackStageTimeline::STAGE_EXFILTRATION,
        'CLOUD_SECURITY_SETTING_MODIFIED'        => AttackStageTimeline::STAGE_IMPACT,
    ];

    // SaaS alert types → attack stage
    private const SAAS_STAGE_MAP = [
        'SAAS_UNUSUAL_ADMIN_ACTIVITY'            => AttackStageTimeline::STAGE_CREDENTIAL_ACCESS,
    ];

    // Endpoint finding types → attack stage
    private const ENDPOINT_STAGE_MAP = [
        'suspicious_execution_chain'             => AttackStageTimeline::STAGE_EXECUTION,
        'lolbin_usage'                           => AttackStageTimeline::STAGE_EXECUTION,
        'rare_parent_child'                      => AttackStageTimeline::STAGE_EXECUTION,
        'beacon_pattern'                         => AttackStageTimeline::STAGE_C2,
        'persistence_correlation'                => AttackStageTimeline::STAGE_PERSISTENCE,
        'suspicious_parent_child_chain'          => AttackStageTimeline::STAGE_EXECUTION,
        'suspicious_shell_chain'                 => AttackStageTimeline::STAGE_EXECUTION,
        'suspicious_long_lived_process'          => AttackStageTimeline::STAGE_C2,
        'suspicious_persistence_entry'           => AttackStageTimeline::STAGE_PERSISTENCE,
        'suspicious_persistence_correlation'     => AttackStageTimeline::STAGE_PERSISTENCE,
        'suspicious_lolbin_usage'                => AttackStageTimeline::STAGE_EXECUTION,
        'suspicious_beacon_pattern'              => AttackStageTimeline::STAGE_C2,
    ];

    // -----------------------------------------------------------------------
    // Domain correlation methods — advisory-only
    // -----------------------------------------------------------------------

    /**
     * Correlate identity alerts with endpoint behavioral findings.
     * Groups by actor_key; finds endpoint activity in the same time window.
     * Returns array of created CrossDomainCorrelation records.
     */
    public function correlateIdentityToEndpoint(
        ?string $actorKey = null,
        int $windowMinutes = self::CORRELATION_WINDOW_MINUTES
    ): array {
        $since = now()->subMinutes($windowMinutes);

        $query = DB::table('security_alerts')
            ->whereIn('alert_type', array_keys(self::IDENTITY_STAGE_MAP))
            ->where('detected_at', '>=', $since)
            ->orderBy('detected_at');

        if ($actorKey) {
            $query->where('actor_key', $actorKey);
        }

        $identityAlerts = $query->limit(500)->get();
        if ($identityAlerts->isEmpty()) {
            return [];
        }

        $created = [];
        foreach ($identityAlerts->groupBy('actor_key') as $actor => $actorAlerts) {
            if (!$actor) {
                continue;
            }

            $windowStart = $actorAlerts->min('detected_at');
            $windowEnd   = Carbon::parse($windowStart)->addMinutes($windowMinutes)->toDateTimeString();

            $findings = EndpointBehavioralFinding::where('detected_at', '>=', $windowStart)
                ->where('detected_at', '<=', $windowEnd)
                ->limit(50)
                ->get();

            if ($findings->isEmpty()) {
                continue;
            }

            $corr = $this->persistCorrelation(
                type:        CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT,
                actorKey:    $actor,
                alerts:      $actorAlerts,
                findings:    $findings,
                windowStart: $windowStart,
                windowEnd:   $windowEnd,
            );

            $this->projectEntityRelationships($corr, $actorAlerts, $findings);
            $created[] = $corr;

            if (count($created) >= self::MAX_CORRELATIONS_PER_RUN) {
                break;
            }
        }

        return $created;
    }

    /**
     * Correlate SaaS alerts with endpoint beacon patterns.
     */
    public function correlateSaaSToEndpoint(
        int $windowMinutes = self::CORRELATION_WINDOW_MINUTES
    ): array {
        $since = now()->subMinutes($windowMinutes);

        $saasAlerts = DB::table('security_alerts')
            ->whereIn('alert_type', array_keys(self::SAAS_STAGE_MAP))
            ->where('detected_at', '>=', $since)
            ->orderBy('detected_at')
            ->limit(200)
            ->get();

        if ($saasAlerts->isEmpty()) {
            return [];
        }

        $windowStart = $saasAlerts->min('detected_at');
        $windowEnd   = Carbon::parse($windowStart)->addMinutes($windowMinutes)->toDateTimeString();

        $beacons = EndpointBeaconPattern::where('detected_at', '>=', $windowStart)
            ->where('detected_at', '<=', $windowEnd)
            ->limit(50)
            ->get();

        if ($beacons->isEmpty()) {
            return [];
        }

        $corr = $this->persistCorrelation(
            type:        CrossDomainCorrelation::TYPE_SAAS_ENDPOINT,
            actorKey:    null,
            alerts:      $saasAlerts,
            findings:    collect(),
            windowStart: $windowStart,
            windowEnd:   $windowEnd,
            extra:       ['beacon_count' => $beacons->count()],
        );

        // Link beacon patterns as evidence
        $now = now();
        $links = [];
        foreach ($beacons->take(20) as $bp) {
            $links[] = [
                'correlation_id'  => $corr->id,
                'evidence_type'   => CorrelationEvidenceLink::TYPE_ENDPOINT_FINDING,
                'evidence_id'     => $bp->id,
                'evidence_table'  => 'endpoint_beacon_patterns',
                'evidence_snapshot'=> json_encode($bp->only(['pattern_id', 'process_name', 'remote_ip', 'connection_count', 'detected_at'])),
                'domain'          => CorrelationEvidenceLink::DOMAIN_ENDPOINT,
                'created_at'      => $now,
            ];
        }
        if ($links) {
            DB::table('correlation_evidence_links')->insert($links);
        }

        return [$corr];
    }

    /**
     * Correlate cloud alerts with endpoint persistence items.
     */
    public function correlateCloudToEndpoint(
        int $windowMinutes = self::CORRELATION_WINDOW_MINUTES
    ): array {
        $since = now()->subMinutes($windowMinutes);

        $cloudAlerts = DB::table('security_alerts')
            ->whereIn('alert_type', array_keys(self::CLOUD_STAGE_MAP))
            ->where('detected_at', '>=', $since)
            ->orderBy('detected_at')
            ->limit(200)
            ->get();

        if ($cloudAlerts->isEmpty()) {
            return [];
        }

        $windowStart = $cloudAlerts->min('detected_at');
        $windowEnd   = Carbon::parse($windowStart)->addMinutes($windowMinutes)->toDateTimeString();

        $persistence = EndpointPersistenceItem::where('last_seen_at', '>=', $windowStart)
            ->where('last_seen_at', '<=', $windowEnd)
            ->where('is_new', true)
            ->limit(50)
            ->get();

        if ($persistence->isEmpty()) {
            return [];
        }

        $corr = $this->persistCorrelation(
            type:        CrossDomainCorrelation::TYPE_CLOUD_ENDPOINT,
            actorKey:    null,
            alerts:      $cloudAlerts,
            findings:    collect(),
            windowStart: $windowStart,
            windowEnd:   $windowEnd,
            extra:       ['new_persistence_count' => $persistence->count()],
        );

        // Link persistence items as evidence
        $now = now();
        $links = [];
        foreach ($persistence->take(20) as $pi) {
            $links[] = [
                'correlation_id'   => $corr->id,
                'evidence_type'    => CorrelationEvidenceLink::TYPE_ENDPOINT_FINDING,
                'evidence_id'      => $pi->id,
                'evidence_table'   => 'endpoint_persistence_items',
                'evidence_snapshot'=> json_encode($pi->only(['item_type', 'item_key', 'item_name', 'is_new', 'last_seen_at'])),
                'domain'           => CorrelationEvidenceLink::DOMAIN_ENDPOINT,
                'created_at'       => $now,
            ];
        }
        if ($links) {
            DB::table('correlation_evidence_links')->insert($links);
        }

        return [$corr];
    }

    /**
     * Detect multi-host shared destination patterns.
     * Groups beacon patterns by (remote_ip, remote_port) across agents.
     */
    public function detectMultiHostSharedDestination(
        int $windowMinutes = self::CORRELATION_WINDOW_MINUTES,
        int $minHosts = 2
    ): array {
        $since = now()->subMinutes($windowMinutes);

        $beacons = EndpointBeaconPattern::where('detected_at', '>=', $since)
            ->select('id', 'agent_id', 'remote_ip', 'remote_port', 'process_name', 'connection_count', 'detected_at', 'pattern_id')
            ->limit(500)
            ->get();

        if ($beacons->isEmpty()) {
            return [];
        }

        // Group by (remote_ip, remote_port)
        $grouped = $beacons->groupBy(fn ($b) => $b->remote_ip . ':' . ($b->remote_port ?? 0));

        $created = [];
        foreach ($grouped as $destination => $group) {
            $uniqueAgents = $group->pluck('agent_id')->unique();
            if ($uniqueAgents->count() < $minHosts) {
                continue;
            }

            $agents = EndpointAgent::whereIn('id', $uniqueAgents)
                ->get(['id', 'agent_id', 'hostname']);

            $windowStart = $group->min('detected_at');
            $windowEnd   = $group->max('detected_at') ?? $windowStart;

            $corr = CrossDomainCorrelation::create([
                'correlation_id'       => CrossDomainCorrelation::generateCorrelationId(),
                'correlation_type'     => CrossDomainCorrelation::TYPE_MULTI_HOST,
                'confidence_score'     => min(0.60 + ($uniqueAgents->count() * 0.05), 0.95),
                'attack_stages'        => [AttackStageTimeline::STAGE_C2],
                'primary_entity_type'  => 'ip',
                'primary_entity_key'   => explode(':', $destination)[0],
                'actor_key'            => null,
                'involved_hosts'       => $agents->pluck('hostname')->all(),
                'involved_users'       => [],
                'involved_ips'         => [$explode = explode(':', $destination), $explode[0]],
                'time_window_start'    => $windowStart,
                'time_window_end'      => $windowEnd,
                'alert_ids'            => [],
                'trace_ids'            => [],
                'correlation_metadata' => [
                    'destination'    => $destination,
                    'host_count'     => $uniqueAgents->count(),
                    'beacon_count'   => $group->count(),
                    'advisory_only'  => true,
                ],
                'trace_id'             => 'trace-' . Str::uuid(),
            ]);

            $this->buildTimelines($corr, [AttackStageTimeline::STAGE_C2], $group->first(), $windowStart, $windowEnd);

            // Link beacon patterns
            $now = now();
            $links = [];
            foreach ($group->take(20) as $bp) {
                $links[] = [
                    'correlation_id'   => $corr->id,
                    'evidence_type'    => CorrelationEvidenceLink::TYPE_ENDPOINT_FINDING,
                    'evidence_id'      => $bp->id,
                    'evidence_table'   => 'endpoint_beacon_patterns',
                    'evidence_snapshot'=> json_encode($bp->only(['pattern_id', 'agent_id', 'remote_ip', 'remote_port', 'connection_count'])),
                    'domain'           => CorrelationEvidenceLink::DOMAIN_ENDPOINT,
                    'created_at'       => $now,
                ];
            }
            if ($links) {
                DB::table('correlation_evidence_links')->insert($links);
            }

            $created[] = $corr;

            if (count($created) >= self::MAX_CORRELATIONS_PER_RUN) {
                break;
            }
        }

        return $created;
    }

    /**
     * Stitch together all records sharing a trace_id across domains.
     * Read-only. No mutations.
     */
    public function stitchCrossDomainTrace(string $traceId): array
    {
        if (!$traceId) {
            return ['error' => 'trace_id_required'];
        }

        $sanitized = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $traceId), 0, 120);

        return [
            'trace_id'      => $sanitized,
            'alerts'        => DB::table('security_alerts')
                ->where('trace_id', $sanitized)
                ->select('alert_id', 'alert_type', 'severity', 'actor_key', 'detected_at')
                ->orderBy('detected_at')->limit(50)->get()->all(),
            'incidents'     => DB::table('security_incidents')
                ->where('trace_id', $sanitized)
                ->select('incident_id', 'status', 'severity', 'created_at')
                ->orderBy('created_at')->limit(10)->get()->all(),
            'findings'      => EndpointBehavioralFinding::where('trace_id', $sanitized)
                ->select('finding_id', 'finding_type', 'severity', 'detected_at')
                ->orderBy('detected_at')->limit(50)->get()->all(),
            'correlations'  => CrossDomainCorrelation::where('trace_id', $sanitized)
                ->select('correlation_id', 'correlation_type', 'confidence_score', 'attack_stages', 'created_at')
                ->orderBy('created_at')->limit(20)->get()->all(),
            'hunt_results'  => DB::table('threat_hunt_results')
                ->where('trace_id', $sanitized)
                ->select('id', 'result_type', 'created_at')
                ->orderBy('created_at')->limit(20)->get()->all(),
            'advisory_note' => 'Cross-domain trace stitching is advisory-only and non-destructive.',
        ];
    }

    // -----------------------------------------------------------------------
    // Query helpers — read-only
    // -----------------------------------------------------------------------

    public function getRecentCorrelations(int $limit = 50): Collection
    {
        return CrossDomainCorrelation::with(['timelines'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getCorrelation(string $correlationId): ?CrossDomainCorrelation
    {
        return CrossDomainCorrelation::where('correlation_id', $correlationId)
            ->with(['timelines', 'evidenceLinks'])
            ->first();
    }

    public function getCorrelationsByType(string $type, int $limit = 30): Collection
    {
        return CrossDomainCorrelation::where('correlation_type', $type)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getAttackStageDistribution(): array
    {
        $rows = DB::table('attack_stage_timelines')
            ->select('stage', DB::raw('count(*) as count'))
            ->groupBy('stage')
            ->orderBy('stage')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [$r->stage => (int) $r->count])->all();
    }

    public function getCorrelationsForActor(string $actorKey, int $limit = 20): Collection
    {
        return CrossDomainCorrelation::where('actor_key', $actorKey)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Private: correlation builder
    // -----------------------------------------------------------------------

    private function persistCorrelation(
        string     $type,
        ?string    $actorKey,
        Collection $alerts,
        Collection $findings,
        string     $windowStart,
        string     $windowEnd,
        array      $extra = []
    ): CrossDomainCorrelation {
        $stages        = $this->determineAttackStages($alerts, $findings);
        $confidence    = $this->calculateConfidence($alerts, $findings, $type);
        $involvedHosts = $findings->pluck('agent_id')->filter()->unique()->values()->all();
        $involvedIPs   = $alerts->pluck('source_ip')->filter()->unique()->values()->all();
        $alertIds      = $alerts->pluck('alert_id')->filter()->values()->all();
        $traceIds      = $alerts->pluck('trace_id')->merge($findings->pluck('trace_id'))->filter()->unique()->values()->all();

        $corr = CrossDomainCorrelation::create([
            'correlation_id'       => CrossDomainCorrelation::generateCorrelationId(),
            'correlation_type'     => $type,
            'confidence_score'     => $confidence,
            'attack_stages'        => $stages,
            'primary_entity_type'  => $actorKey ? 'user' : null,
            'primary_entity_key'   => $actorKey,
            'actor_key'            => $actorKey,
            'involved_hosts'       => $involvedHosts,
            'involved_users'       => $actorKey ? [$actorKey] : [],
            'involved_ips'         => $involvedIPs,
            'time_window_start'    => $windowStart,
            'time_window_end'      => $windowEnd,
            'alert_ids'            => $alertIds,
            'trace_ids'            => $traceIds,
            'correlation_metadata' => array_merge([
                'alert_count'   => $alerts->count(),
                'finding_count' => $findings->count(),
                'advisory_only' => true,
            ], $extra),
            'trace_id'             => 'trace-' . Str::uuid(),
        ]);

        $this->buildTimelines($corr, $stages, $alerts->first(), $windowStart, $windowEnd);
        $this->linkAlertEvidence($corr, $alerts);
        $this->linkFindingEvidence($corr, $findings);

        return $corr;
    }

    /**
     * Determine ordered attack stages from alert types and finding types.
     * Deterministic: same input → same output.
     */
    public function determineAttackStages(Collection $alerts, Collection $findings): array
    {
        $stages = [];

        foreach ($alerts as $alert) {
            $stage = self::IDENTITY_STAGE_MAP[$alert->alert_type ?? '']
                  ?? self::CLOUD_STAGE_MAP[$alert->alert_type ?? '']
                  ?? self::SAAS_STAGE_MAP[$alert->alert_type ?? '']
                  ?? null;
            if ($stage) {
                $stages[$stage] = AttackStageTimeline::STAGE_ORDER[$stage] ?? 99;
            }
        }

        foreach ($findings as $finding) {
            $stage = self::ENDPOINT_STAGE_MAP[$finding->finding_type ?? ''] ?? null;
            if ($stage) {
                $stages[$stage] = AttackStageTimeline::STAGE_ORDER[$stage] ?? 99;
            }
        }

        asort($stages);
        return array_keys($stages);
    }

    private function calculateConfidence(Collection $alerts, Collection $findings, string $type): float
    {
        $base = match ($type) {
            CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT => 0.65,
            CrossDomainCorrelation::TYPE_SAAS_ENDPOINT     => 0.60,
            CrossDomainCorrelation::TYPE_CLOUD_ENDPOINT    => 0.62,
            CrossDomainCorrelation::TYPE_MULTI_HOST        => 0.70,
            default                                         => 0.55,
        };

        // Boost for multiple corroborating signals
        $boost = min($alerts->count() * 0.03 + $findings->count() * 0.02, 0.25);
        return min($base + $boost, 0.95);
    }

    private function buildTimelines(
        CrossDomainCorrelation $corr,
        array $stages,
        mixed $firstEvidence,
        string $windowStart,
        string $windowEnd
    ): void {
        foreach ($stages as $stage) {
            AttackStageTimeline::create([
                'timeline_id'        => AttackStageTimeline::generateTimelineId(),
                'correlation_id'     => $corr->id,
                'stage'              => $stage,
                'stage_order'        => AttackStageTimeline::STAGE_ORDER[$stage] ?? 0,
                'stage_confidence'   => $corr->confidence_score,
                'evidence_summary'   => "Detected via {$corr->correlation_type} cross-domain analysis.",
                'evidence_references'=> ['correlation_id' => $corr->correlation_id],
                'first_seen_at'      => $windowStart,
                'last_seen_at'       => $windowEnd,
                'trace_id'           => $corr->trace_id,
            ]);
        }
    }

    private function linkAlertEvidence(CrossDomainCorrelation $corr, Collection $alerts): void
    {
        $now = now();
        $links = [];
        foreach ($alerts->take(20) as $alert) {
            $domain = str_starts_with($alert->alert_type ?? '', 'IDENTITY_') ? 'identity'
                    : (str_starts_with($alert->alert_type ?? '', 'CLOUD_') ? 'cloud'
                    : (str_starts_with($alert->alert_type ?? '', 'SAAS_') ? 'saas' : 'identity'));

            $links[] = [
                'correlation_id'    => $corr->id,
                'evidence_type'     => CorrelationEvidenceLink::TYPE_SECURITY_ALERT,
                'evidence_id'       => $alert->id ?? null,
                'evidence_table'    => 'security_alerts',
                'evidence_snapshot' => json_encode([
                    'alert_id'   => $alert->alert_id,
                    'alert_type' => $alert->alert_type,
                    'severity'   => $alert->severity,
                    'actor_key'  => $alert->actor_key,
                    'detected_at'=> $alert->detected_at,
                ]),
                'domain'            => $domain,
                'created_at'        => $now,
            ];
        }
        if ($links) {
            DB::table('correlation_evidence_links')->insert($links);
        }
    }

    private function linkFindingEvidence(CrossDomainCorrelation $corr, Collection $findings): void
    {
        $now = now();
        $links = [];
        foreach ($findings->take(20) as $finding) {
            $links[] = [
                'correlation_id'    => $corr->id,
                'evidence_type'     => CorrelationEvidenceLink::TYPE_ENDPOINT_FINDING,
                'evidence_id'       => $finding->id,
                'evidence_table'    => 'endpoint_behavioral_findings',
                'evidence_snapshot' => json_encode([
                    'finding_id'   => $finding->finding_id,
                    'finding_type' => $finding->finding_type,
                    'severity'     => $finding->severity,
                    'detected_at'  => $finding->detected_at,
                ]),
                'domain'            => CorrelationEvidenceLink::DOMAIN_ENDPOINT,
                'created_at'        => $now,
            ];
        }
        if ($links) {
            DB::table('correlation_evidence_links')->insert($links);
        }
    }

    /**
     * Project new entity graph relationships for a correlation.
     * Uses EntityGraphService — additive, never destructive.
     */
    private function projectEntityRelationships(
        CrossDomainCorrelation $corr,
        Collection $alerts,
        Collection $findings
    ): void {
        $graphService = app(EntityGraphService::class);
        $traceId      = $corr->trace_id;
        $seenAt       = now()->toDateTimeString();

        // Upsert user entity for actor
        if ($corr->actor_key) {
            $userId = $graphService->upsertEntity('user', $corr->actor_key, $corr->actor_key, $seenAt);

            // Link user to hosts observed in findings
            $agentIds = $findings->pluck('agent_id')->filter()->unique();
            foreach ($agentIds as $agentId) {
                $agent = \App\Models\EndpointAgent::find($agentId);
                if ($agent && $agent->hostname) {
                    $hostId = $graphService->upsertEntity('host', $agent->hostname, $agent->hostname, $seenAt);
                    $graphService->upsertRelationship(
                        $userId, $hostId,
                        'user_logged_into_host',
                        $seenAt, 'cross_domain_correlation', 'cross_domain_correlations',
                        $corr->correlation_id, $traceId, 0.70
                    );
                }
            }

            // Link user to IPs from alerts
            foreach ($alerts as $alert) {
                $ip = $alert->ip ?? null;
                if ($ip) {
                    $ipId = $graphService->upsertEntity('ip', $ip, $ip, $seenAt);
                    $graphService->upsertRelationship(
                        $userId, $ipId,
                        'user_triggered_process',
                        $seenAt, 'cross_domain_correlation', 'security_alerts',
                        $alert->alert_id ?? null, $traceId, 0.65
                    );
                }
            }
        }

        // Trace crossed domain relationship: link distinct entity domains via trace_id
        $traceIds = array_unique(array_merge($corr->trace_ids ?? [], [$traceId]));
        foreach ($traceIds as $tid) {
            if ($tid) {
                $graphService->appendObservation(
                    entityId: $corr->actor_key
                        ? ($graphService->upsertEntity('user', $corr->actor_key, $corr->actor_key, $seenAt))
                        : 0,
                    observationType: 'cross_domain_correlation',
                    observedAt: $seenAt,
                    sourceTable: 'cross_domain_correlations',
                    sourceEventId: $corr->correlation_id,
                    traceId: $tid,
                    context: ['correlation_type' => $corr->correlation_type, 'advisory_only' => true]
                );
            }
        }
    }
}
