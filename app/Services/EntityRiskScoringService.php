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
        'critical_alerts'       => 3.0,
        'high_alerts'           => 2.0,
        'medium_alerts'         => 0.8,
        'mfa_failure_burst'     => 2.5,
        'incident_involvement'  => 3.0,
        'trace_frequency'       => 0.4,
        'relationship_count'    => 0.3,
        'persistence_indicator' => 2.5,
        'c2_indicator'          => 3.5,
        'shadow_alert_advisory' => 0.5,
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
