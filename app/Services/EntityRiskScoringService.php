<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\EntityRiskSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, explainable, advisory-only entity risk scoring.
 *
 * Risk scores are investigation prioritization aids — they MUST NOT trigger
 * automated enforcement, containment, or incident creation.
 *
 * Shadow alert contributions are explicitly flagged advisory_only = true
 * and must not influence any active pipeline decisions.
 */
class EntityRiskScoringService
{
    // Factor weights — static, deterministic, documented.
    public const WEIGHTS = [
        'critical_alerts'            => 3.0,
        'high_alerts'                => 2.0,
        'medium_alerts'              => 0.8,
        'mfa_failure_burst'          => 2.5,
        'incident_involvement'       => 3.0,
        'trace_frequency'            => 0.4,
        'relationship_count'         => 0.3,
        'persistence_indicator'      => 2.5,
        'c2_indicator'               => 3.5,
        'shadow_alert_advisory'      => 0.5,
        // Cross-domain amplification factors — advisory_only = true
        'cross_domain_correlation'   => 2.0,
        'multi_stage_escalation'     => 2.5,
        'repeated_destination_reuse' => 1.5,
        'identity_endpoint_combined' => 3.0,
        // Active response scoring — Phase 2
        'response_blast_radius'      => 1.5,  // amplification: entity is a target with high blast radius
        'response_rollback_impact'   => 1.0,  // rollback was needed — indicates significant threat
        'response_execution_mitigation' => -2.0, // mitigation applied — lowers risk (negative contribution)
        // UEBA behavioral baseline factors — Phase 1 (advisory_only = true)
        'baseline_anomaly_factor'       => 2.0,  // entity has recent high-confidence baseline anomalies
        'peer_deviation_factor'         => 1.5,  // entity deviates significantly from peer group
        'abnormal_data_volume_factor'   => 2.0,  // abnormal bytes out / data exfil signal
        'unusual_activity_time_factor'  => 1.5,  // unusual active hours pattern
        // Endpoint operational hardening factors — Phase 1 (advisory_only = true)
        'telemetry_gap_factor'          => 2.0,  // host telemetry has a significant gap (potential blind spot)
        'tamper_visibility_factor'      => 2.5,  // tamper indicator detected on endpoint
        'stale_agent_factor'            => 1.5,  // agent is stale or offline
        'policy_drift_factor'           => 1.0,  // agent config does not match assigned fleet policy
    ];

    // Score thresholds for level assignment
    public const LEVEL_THRESHOLDS = [
        'critical' => 7.5,
        'high'     => 5.0,
        'medium'   => 2.5,
        'low'      => 0.0,
    ];

    public const MAX_SCORE = 10.0;

    /**
     * Calculate risk for an entity. Deterministic — same data produces same score.
     * Does NOT write to DB. Call calculateAndPersist() to also store the result.
     *
     * Returns empty array if entity not found.
     */
    public function calculateRisk(int $entityId): array
    {
        $entity = DB::table('entities')->where('id', $entityId)->first();
        if (!$entity) {
            return [];
        }

        $factors     = [];
        $alertIds    = [];
        $incidentIds = [];
        $traceIds    = [];

        // Common factors — applicable to all entity types
        $this->collectAlertFactors($entity, $factors, $alertIds, $traceIds);
        $this->collectIncidentFactors($entity, $factors, $incidentIds, $traceIds);
        $this->collectTraceFrequency($entity, $factors, $traceIds);
        $this->collectRelationshipFactor($entity, $factors);
        $this->collectCrossDomainFactors($entity, $factors);    // advisory-only amplification
        $this->collectActiveResponseFactors($entity, $factors); // Phase 2 response scoring

        // Type-specific factors
        switch ($entity->entity_type) {
            case 'user':
                $this->collectUserFactors($entity, $factors, $alertIds, $traceIds);
                break;
            case 'host':
                $this->collectHostFactors($entity, $factors);
                break;
            case 'ip':
            case 'domain':
                $this->collectNetworkFactors($entity, $factors);
                break;
            case 'process':
                $this->collectProcessFactors($entity, $factors);
                break;
        }

        $this->collectUEBAFactors($entity, $factors);               // UEBA baseline amplification — advisory_only
        $this->collectEndpointOperationalFactors($entity, $factors); // Endpoint fleet hardening — advisory_only

        $score = $this->aggregateScore($factors);
        $level = static::scoreToLevel($score);

        return [
            'score'        => $score,
            'level'        => $level,
            'factors'      => $factors,
            'alert_ids'    => array_values(array_unique($alertIds)),
            'incident_ids' => array_values(array_unique($incidentIds)),
            'trace_ids'    => array_values(array_unique($traceIds)),
            'calculated_at'=> now()->toDateTimeString(),
        ];
    }

    /**
     * Calculate risk and persist: updates entity current state, appends snapshot.
     * Snapshot is always INSERTed — append-only, replay-safe.
     */
    public function calculateAndPersist(int $entityId): ?EntityRiskSnapshot
    {
        $result = $this->calculateRisk($entityId);
        if (empty($result)) {
            return null;
        }

        $entity = DB::table('entities')->where('id', $entityId)->first();

        // Update entity's current advisory risk state
        DB::table('entities')->where('id', $entityId)->update([
            'risk_score'              => $result['score'],
            'risk_level'              => $result['level'],
            'risk_factors'            => json_encode($result['factors']),
            'last_risk_calculated_at' => $result['calculated_at'],
        ]);

        // Always INSERT a new snapshot — append-only history
        return EntityRiskSnapshot::create([
            'entity_id'    => $entityId,
            'entity_type'  => $entity->entity_type,
            'entity_key'   => $entity->entity_key,
            'risk_score'   => $result['score'],
            'risk_level'   => $result['level'],
            'risk_factors' => $result['factors'],
            'alert_ids'    => $result['alert_ids'],
            'incident_ids' => $result['incident_ids'],
            'trace_ids'    => $result['trace_ids'],
            'calculated_at'=> $result['calculated_at'],
        ]);
    }

    /**
     * Recalculate risk for a batch of entities by type.
     * Only triage-relevant types: user, host, ip, domain, process.
     */
    public function recalculateAll(string $type = '', int $limit = 200): int
    {
        $query = DB::table('entities')
            ->orderByDesc('observation_count')
            ->limit($limit);

        if ($type && in_array($type, Entity::TYPES, true)) {
            $query->where('entity_type', $type);
        } else {
            $query->whereIn('entity_type', ['user', 'host', 'ip', 'domain', 'process']);
        }

        $count = 0;
        foreach ($query->get() as $entity) {
            $this->calculateAndPersist($entity->id);
            $count++;
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    public function getTopRiskyEntities(string $type = '', int $limit = 10): Collection
    {
        $query = DB::table('entities')
            ->whereNotNull('risk_score')
            ->whereNotNull('risk_level')
            ->orderByDesc('risk_score')
            ->limit($limit);

        if ($type && in_array($type, Entity::TYPES, true)) {
            $query->where('entity_type', $type);
        } else {
            $query->whereIn('entity_type', ['user', 'host', 'ip', 'domain', 'process']);
        }

        return $query->get();
    }

    public function getRiskHistory(int $entityId, int $limit = 30): Collection
    {
        return DB::table('entity_risk_snapshots')
            ->where('entity_id', $entityId)
            ->orderByDesc('calculated_at')
            ->limit($limit)
            ->get();
    }

    public function getRiskDistribution(): Collection
    {
        return DB::table('entities')
            ->whereNotNull('risk_level')
            ->select('risk_level', DB::raw('count(*) as count'))
            ->groupBy('risk_level')
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->get();
    }

    // -------------------------------------------------------------------------
    // Score utilities — public so they can be unit-tested
    // -------------------------------------------------------------------------

    public static function scoreToLevel(float $score): string
    {
        return match (true) {
            $score >= static::LEVEL_THRESHOLDS['critical'] => 'critical',
            $score >= static::LEVEL_THRESHOLDS['high']     => 'high',
            $score >= static::LEVEL_THRESHOLDS['medium']   => 'medium',
            default                                         => 'low',
        };
    }

    public function aggregateScore(array $factors): float
    {
        $total = 0.0;
        foreach ($factors as $f) {
            $total += (float) ($f['contribution'] ?? 0.0);
        }
        return (float) min(round($total, 2), static::MAX_SCORE);
    }

    // -------------------------------------------------------------------------
    // Factor collection methods
    // -------------------------------------------------------------------------

    private function collectAlertFactors(
        object $entity,
        array  &$factors,
        array  &$alertIds,
        array  &$traceIds
    ): void {
        $alerts = $this->alertsForEntity($entity);
        if ($alerts->isEmpty()) {
            return;
        }

        foreach ($alerts as $a) {
            if (!empty($a->alert_id))  $alertIds[] = $a->alert_id;
            if (!empty($a->trace_id))  $traceIds[] = $a->trace_id;
        }

        $critical = $alerts->where('severity', 'critical');
        $high     = $alerts->where('severity', 'high');
        $medium   = $alerts->where('severity', 'medium');

        if ($critical->count() > 0) {
            $factors[] = $this->makeFactor(
                'critical_alerts',
                min($critical->count(), 3),
                $critical->pluck('alert_id')->filter()->take(5)->values()->toArray()
            );
        }
        if ($high->count() > 0) {
            $factors[] = $this->makeFactor(
                'high_alerts',
                min($high->count(), 3),
                $high->pluck('alert_id')->filter()->take(5)->values()->toArray()
            );
        }
        if ($medium->count() > 0) {
            $factors[] = $this->makeFactor('medium_alerts', min($medium->count(), 5));
        }
    }

    private function collectIncidentFactors(
        object $entity,
        array  &$factors,
        array  &$incidentIds,
        array  &$traceIds
    ): void {
        $incidents = $this->incidentsForEntity($entity);
        if ($incidents->isEmpty()) {
            return;
        }

        foreach ($incidents as $inc) {
            if (!empty($inc->incident_id)) $incidentIds[] = $inc->incident_id;
            if (!empty($inc->trace_id))    $traceIds[]    = $inc->trace_id;
        }

        $factors[] = $this->makeFactor(
            'incident_involvement',
            min($incidents->count(), 3),
            [],
            [],
            $incidents->pluck('incident_id')->filter()->take(5)->values()->toArray()
        );
    }

    private function collectTraceFrequency(
        object $entity,
        array  &$factors,
        array  &$traceIds
    ): void {
        $traces = DB::table('entity_observations')
            ->where('entity_id', $entity->id)
            ->whereNotNull('trace_id')
            ->distinct()
            ->pluck('trace_id');

        if ($traces->isEmpty()) {
            return;
        }

        foreach ($traces as $tid) {
            $traceIds[] = $tid;
        }

        $factors[] = $this->makeFactor('trace_frequency', min($traces->count(), 10));
    }

    private function collectRelationshipFactor(object $entity, array &$factors): void
    {
        $count = DB::table('entity_relationships')
            ->where('source_entity_id', $entity->id)
            ->orWhere('target_entity_id', $entity->id)
            ->count();

        if ($count > 0) {
            $factors[] = $this->makeFactor('relationship_count', min($count, 10));
        }
    }

    private function collectUserFactors(
        object $entity,
        array  &$factors,
        array  &$alertIds,
        array  &$traceIds
    ): void {
        $bursts = DB::table('security_alerts')
            ->where('actor_key', $entity->entity_key)
            ->where('alert_type', 'IDENTITY_MFA_FAILURE_BURST')
            ->get();

        if ($bursts->isNotEmpty()) {
            foreach ($bursts as $a) {
                if (!empty($a->alert_id)) $alertIds[] = $a->alert_id;
                if (!empty($a->trace_id)) $traceIds[] = $a->trace_id;
            }
            $factors[] = $this->makeFactor(
                'mfa_failure_burst',
                min($bursts->count(), 3),
                $bursts->pluck('alert_id')->filter()->take(5)->values()->toArray()
            );
        }
    }

    private function collectHostFactors(object $entity, array &$factors): void
    {
        $persistenceTypes = [
            'ENDPOINT_SCHEDULED_TASK_PERSISTENCE',
            'ENDPOINT_NEW_SERVICE_PERSISTENCE',
        ];

        $persistence = DB::table('security_alerts')
            ->where('ip', $entity->entity_key)
            ->whereIn('alert_type', $persistenceTypes)
            ->count();

        if ($persistence > 0) {
            $factors[] = $this->makeFactor(
                'persistence_indicator', min($persistence, 3), [], [], [], true
            );
        }

        $c2Count = DB::table('security_alerts')
            ->where('ip', $entity->entity_key)
            ->where('alert_type', 'like', '%C2%')
            ->count();

        if ($c2Count > 0) {
            $factors[] = $this->makeFactor(
                'c2_indicator', min($c2Count, 2), [], [], [], true
            );
        }

        $this->collectShadowAdvisory($entity, $factors);
    }

    private function collectNetworkFactors(object $entity, array &$factors): void
    {
        $c2Count = DB::table('security_alerts')
            ->where(function ($q) use ($entity) {
                $q->where('ip', $entity->entity_key)
                  ->orWhere('actor_key', $entity->entity_key);
            })
            ->where('alert_type', 'like', '%C2%')
            ->count();

        if ($c2Count > 0) {
            $factors[] = $this->makeFactor(
                'c2_indicator', min($c2Count, 2), [], [], [], true
            );
        }

        $this->collectShadowAdvisory($entity, $factors);
    }

    private function collectProcessFactors(object $entity, array &$factors): void
    {
        $this->collectShadowAdvisory($entity, $factors);
    }

    private function collectShadowAdvisory(object $entity, array &$factors): void
    {
        $shadowCount = DB::table('entity_observations')
            ->where('entity_id', $entity->id)
            ->where('observation_type', 'like', '%shadow%')
            ->count();

        if ($shadowCount > 0) {
            $factors[] = $this->makeFactor(
                'shadow_alert_advisory', min($shadowCount, 5), [], [], [], true
            );
        }
    }

    /**
     * Cross-domain amplification factors — advisory_only = true.
     * Checks cross_domain_correlations for entity involvement.
     * Does NOT trigger any enforcement actions.
     */
    private function collectCrossDomainFactors(object $entity, array &$factors): void
    {
        // Any cross-domain correlation involving this actor/entity
        $corrCount = DB::table('cross_domain_correlations')
            ->where(function ($q) use ($entity) {
                if ($entity->entity_type === 'user') {
                    $q->where('actor_key', $entity->entity_key);
                } elseif ($entity->entity_type === 'host') {
                    $q->whereJsonContains('involved_hosts', $entity->entity_key);
                } elseif ($entity->entity_type === 'ip') {
                    $q->whereJsonContains('involved_ips', $entity->entity_key)
                      ->orWhere('primary_entity_key', $entity->entity_key);
                }
            })->count();

        if ($corrCount > 0) {
            $factors[] = $this->makeFactor(
                'cross_domain_correlation', min($corrCount, 3), [], [], [], true
            );
        }

        // Multi-stage escalation: correlations with 3+ attack stages
        $multiStageCount = DB::table('cross_domain_correlations')
            ->where(function ($q) use ($entity) {
                if ($entity->entity_type === 'user') {
                    $q->where('actor_key', $entity->entity_key);
                } else {
                    $q->where('primary_entity_key', $entity->entity_key);
                }
            })
            ->whereRaw("jsonb_array_length(attack_stages) >= 3")
            ->count();

        if ($multiStageCount > 0) {
            $factors[] = $this->makeFactor(
                'multi_stage_escalation', min($multiStageCount, 2), [], [], [], true
            );
        }

        // Multi-host destination reuse (only relevant for ip/domain entities)
        if ($entity->entity_type === 'ip' || $entity->entity_type === 'domain') {
            $multiHostCount = DB::table('cross_domain_correlations')
                ->where('correlation_type', \App\Models\CrossDomainCorrelation::TYPE_MULTI_HOST)
                ->where('primary_entity_key', $entity->entity_key)
                ->count();

            if ($multiHostCount > 0) {
                $factors[] = $this->makeFactor(
                    'repeated_destination_reuse', min($multiHostCount, 3), [], [], [], true
                );
            }
        }

        // Identity+endpoint combined (only relevant for user entities)
        if ($entity->entity_type === 'user') {
            $identityEndpointCount = DB::table('cross_domain_correlations')
                ->where('correlation_type', \App\Models\CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT)
                ->where('actor_key', $entity->entity_key)
                ->count();

            if ($identityEndpointCount > 0) {
                $factors[] = $this->makeFactor(
                    'identity_endpoint_combined', min($identityEndpointCount, 2), [], [], [], true
                );
            }
        }
    }

    /**
     * Active response scoring factors — Phase 2.
     * Checks response_executions for target entity involvement.
     */
    private function collectActiveResponseFactors(object $entity, array &$factors): void
    {
        $executions = DB::table('response_executions')
            ->where('target_entity_key', $entity->entity_key)
            ->get(['status', 'blast_radius_score', 'rollback_supported']);

        if ($executions->isEmpty()) {
            return;
        }

        // Amplification: entity targeted with high blast radius execution
        $highBlast = $executions->where('blast_radius_score', '>=', 0.6)->count();
        if ($highBlast > 0) {
            $factors[] = $this->makeFactor('response_blast_radius', min($highBlast, 3), [], [], [], true);
        }

        // Rollback needed — indicates serious threat
        $rollbacks = $executions->whereIn('status', ['rollback_ready', 'rolled_back'])->count();
        if ($rollbacks > 0) {
            $factors[] = $this->makeFactor('response_rollback_impact', min($rollbacks, 2), [], [], [], true);
        }

        // Mitigation applied — reduces risk (negative contribution via negative weight)
        $mitigated = $executions->whereIn('status', ['executed', 'rolled_back'])->count();
        if ($mitigated > 0) {
            $factors[] = $this->makeFactor('response_execution_mitigation', min($mitigated, 2), [], [], [], true);
        }
    }

    // -------------------------------------------------------------------------
    // Data retrieval helpers
    // -------------------------------------------------------------------------

    private function alertsForEntity(object $entity): Collection
    {
        return match ($entity->entity_type) {
            'user'  => DB::table('security_alerts')
                ->where('actor_key', $entity->entity_key)
                ->orderByDesc('detected_at')->limit(50)->get(),
            'ip'    => DB::table('security_alerts')
                ->where('ip', $entity->entity_key)
                ->orderByDesc('detected_at')->limit(50)->get(),
            'host'  => DB::table('security_alerts')
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

    private function incidentsForEntity(object $entity): Collection
    {
        return match ($entity->entity_type) {
            'incident' => DB::table('security_incidents')
                ->where('incident_id', $entity->entity_key)->get(),
            'trace'    => DB::table('security_incidents')
                ->where('trace_id', $entity->entity_key)->limit(50)->get(),
            default    => collect(),
        };
    }

    // -------------------------------------------------------------------------
    // Factor builder
    // -------------------------------------------------------------------------

    /**
     * Endpoint operational hardening factors — Phase 1.
     * Queries endpoint fleet tables for tamper events, spool health, and stale indicators.
     * All contributions are advisory_only = true. Capped to prevent over-amplification.
     */
    private function collectEndpointOperationalFactors(object $entity, array &$factors): void
    {
        // Only applies to host entities
        if ($entity->entity_type !== 'host') {
            return;
        }

        $agent = DB::table('endpoint_agents')
            ->where('hostname', $entity->entity_key)
            ->orWhere('host_id', $entity->entity_key)
            ->first();

        if (!$agent) {
            return;
        }

        // stale_agent_factor — agent is stale or offline
        if (in_array($agent->health_state, ['stale', 'offline'], true)) {
            $factors[] = $this->makeFactor('stale_agent_factor', 1, [], [], [], true);
        }

        $since7d = now()->subDays(7);

        // tamper_visibility_factor — high-confidence tamper events in last 7 days
        $tamperCount = DB::table('endpoint_tamper_events')
            ->where('agent_id', $agent->id)
            ->where('is_advisory', true)
            ->where('confidence', '>=', 0.75)
            ->where('detected_at', '>=', $since7d)
            ->count();

        if ($tamperCount > 0) {
            $factors[] = $this->makeFactor('tamper_visibility_factor', min($tamperCount, 2), [], [], [], true);
        }

        // telemetry_gap_factor — large heartbeat gap in last 24h
        $latestHeartbeat = DB::table('endpoint_agent_heartbeats')
            ->where('agent_id', $agent->id)
            ->orderByDesc('heartbeat_at')
            ->value('heartbeat_at');

        if ($latestHeartbeat) {
            $gapSeconds = now()->diffInSeconds(\Carbon\Carbon::parse($latestHeartbeat), true);
            if ($gapSeconds > 600) { // 10+ minute gap
                $factors[] = $this->makeFactor('telemetry_gap_factor', 1, [], [], [], true);
            }
        }

        // policy_drift_factor — latest spool snapshot shows dropped events
        $latestSpool = DB::table('endpoint_spool_snapshots')
            ->where('agent_id', $agent->id)
            ->where('recorded_at', '>=', $since7d)
            ->where('dropped_events', '>=', 10)
            ->count();

        if ($latestSpool > 0) {
            $factors[] = $this->makeFactor('policy_drift_factor', 1, [], [], [], true);
        }
    }

    /**
     * UEBA behavioral baseline factors — Phase 1.
     * Queries baseline_anomaly_scores for recent high-confidence anomalies.
     * All contributions are advisory_only = true.
     * Capped to prevent UEBA alone from dominating the score.
     */
    private function collectUEBAFactors(object $entity, array &$factors): void
    {
        $since = now()->subDays(7);

        $highConfidenceAnomalies = DB::table('baseline_anomaly_scores')
            ->where('entity_key', $entity->entity_key)
            ->where('entity_type', $entity->entity_type)
            ->where('is_advisory', true)
            ->where('confidence', '>=', 0.75)
            ->where('scored_at', '>=', $since)
            ->get(['anomaly_type', 'confidence', 'score_id']);

        if ($highConfidenceAnomalies->isEmpty()) {
            return;
        }

        // baseline_anomaly_factor: capped contribution from anomaly count
        $anomalyCount = $highConfidenceAnomalies->count();
        $factors[] = $this->makeFactor('baseline_anomaly_factor', min($anomalyCount, 3), [], [], [], true);

        // peer_deviation_factor: peer group deviations
        $peerDeviations = $highConfidenceAnomalies
            ->where('anomaly_type', 'peer_group_behavior_deviation');
        if ($peerDeviations->count() > 0) {
            $factors[] = $this->makeFactor('peer_deviation_factor', min($peerDeviations->count(), 2), [], [], [], true);
        }

        // abnormal_data_volume_factor: bytes_out anomalies
        $bytesAnomalies = $highConfidenceAnomalies
            ->where('anomaly_type', 'abnormal_bytes_out');
        if ($bytesAnomalies->count() > 0) {
            $factors[] = $this->makeFactor('abnormal_data_volume_factor', 1, [], [], [], true);
        }

        // unusual_activity_time_factor: unusual login time anomalies
        $timeAnomalies = $highConfidenceAnomalies
            ->where('anomaly_type', 'unusual_login_time');
        if ($timeAnomalies->count() > 0) {
            $factors[] = $this->makeFactor('unusual_activity_time_factor', 1, [], [], [], true);
        }
    }

    private function makeFactor(
        string $name,
        int    $value,
        array  $alertIds    = [],
        array  $traceIds    = [],
        array  $incidentIds = [],
        bool   $advisoryOnly = false
    ): array {
        $weight       = (float) (self::WEIGHTS[$name] ?? 1.0);
        $contribution = (float) round(min((float) $value * $weight, static::MAX_SCORE), 3);

        $factor = [
            'factor'       => $name,
            'value'        => $value,
            'weight'       => $weight,
            'contribution' => $contribution,
        ];

        if ($alertIds)    $factor['alert_ids']    = $alertIds;
        if ($traceIds)    $factor['trace_ids']    = $traceIds;
        if ($incidentIds) $factor['incident_ids'] = $incidentIds;
        if ($advisoryOnly) $factor['advisory_only'] = true;

        return $factor;
    }
}
