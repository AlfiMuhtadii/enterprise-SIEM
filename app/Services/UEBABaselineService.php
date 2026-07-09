<?php

namespace App\Services;

use App\Models\BaselineAnomalyScore;
use App\Models\BaselineObservation;
use App\Models\EndpointStreamEvent;
use App\Models\EntityBehaviorBaseline;
use App\Models\PeerGroupProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UEBA Phase 1 — Explainable Behavioral Baseline Analytics.
 *
 * All analytics are:
 *   - advisory-only: results MUST NOT trigger autonomous enforcement
 *   - deterministic: same inputs → same outputs (replay-safe)
 *   - evidence-linked: every score references source events and trace_ids
 *   - explainable: scoring method, observed/baseline values, and deviation are always stored
 *
 * Scoring methods used:
 *   - robust_z_score:        (value − median) / (1.4826 × MAD)  — outlier-resistant
 *   - percentile_rank:       position of observed value in sorted peer distribution
 *   - frequency_rarity:      inverse of observed frequency relative to window average
 *   - peer_group_deviation:  deviation from peer group mean normalized by peer stddev
 *   - rolling_average_deviation: deviation from own rolling mean normalized by own stddev
 *
 * NOT implemented:
 *   - opaque neural models
 *   - LLM-based scoring
 *   - random or non-deterministic scoring
 *   - autonomous enforcement of any kind
 */
class UEBABaselineService
{
    // Scoring thresholds — anomaly is triggered when z-score exceeds this
    public const ANOMALY_Z_THRESHOLD        = 2.5;
    public const PEER_DEVIATION_THRESHOLD   = 2.0;
    public const MIN_SAMPLES_FOR_SCORING    = 5;   // minimum observations before scoring is meaningful
    public const WINDOW_DAYS               = 30;
    public const MAX_ANOMALY_HISTORY        = 200;
    public const MAX_PEER_GROUP_MEMBERS     = 500;

    // Dimension → anomaly type mapping
    private const DIMENSION_ANOMALY_MAP = [
        'login_frequency'               => 'unusual_login_time',
        'failed_login_ratio'            => 'abnormal_failed_login_ratio',
        'source_ip_diversity'           => 'unusual_source_ip_diversity',
        'saas_action_frequency'         => 'unusual_saas_action_frequency',
        'process_execution_frequency'   => 'unusual_process_execution_frequency',
        'network_destination_frequency' => 'abnormal_network_destination_frequency',
        'bytes_out_volume'              => 'abnormal_bytes_out',
        'host_usage'                    => 'unusual_host_usage',
    ];

    // -----------------------------------------------------------------------
    // Baseline computation
    // -----------------------------------------------------------------------

    /**
     * Compute or refresh the rolling behavioral baseline for one entity+dimension.
     * Uses observations from the last WINDOW_DAYS days.
     * Deterministic — same observations always produce same baseline.
     */
    public function computeBaseline(string $entityKey, string $entityType, string $dimension, ?string $tenantId = null): ?EntityBehaviorBaseline
    {
        if (!in_array($dimension, EntityBehaviorBaseline::DIMENSIONS, true)) {
            return null;
        }

        $windowStart = now()->subDays(self::WINDOW_DAYS);

        $observations = DB::table('baseline_observations')
            ->where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('dimension', $dimension)
            ->where('observed_at', '>=', $windowStart)
            ->where('tenant_id', $tenantId)
            ->orderBy('observed_at')
            ->pluck('observed_value')
            ->map(fn ($v) => (float) $v)
            ->values()
            ->toArray();

        if (empty($observations)) {
            return null;
        }

        $stats = $this->computeStats($observations);

        $existing = EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('dimension', $dimension)
            ->where('tenant_id', $tenantId)
            ->first();

        $attributes = [
            'entity_id'        => $entityKey,
            'entity_type'      => $entityType,
            'entity_key'       => $entityKey,
            'tenant_id'        => $tenantId,
            'dimension'        => $dimension,
            'baseline_mean'    => $stats['mean'],
            'baseline_median'  => $stats['median'],
            'baseline_stddev'  => $stats['stddev'],
            'baseline_mad'     => $stats['mad'],
            'baseline_p10'     => $stats['p10'],
            'baseline_p90'     => $stats['p90'],
            'sample_count'     => count($observations),
            'window_days'      => self::WINDOW_DAYS,
            'advisory_only'    => true,
            'window_start'     => $windowStart,
            'window_end'       => now(),
            'computed_at'      => now(),
        ];

        if ($existing) {
            $existing->update($attributes);
            return $existing->fresh();
        }

        return EntityBehaviorBaseline::create($attributes);
    }

    /**
     * Record a new observation for a given entity+dimension.
     * Append-only — never updates an existing observation.
     */
    public function recordObservation(
        string  $entityKey,
        string  $entityType,
        string  $dimension,
        float   $value,
        ?string $sourceTable   = null,
        ?string $sourceEventId = null,
        ?string $traceId       = null,
        array   $context       = [],
        ?string $tenantId      = null
    ): BaselineObservation {
        return BaselineObservation::create([
            'observation_id'  => (string) Str::uuid(),
            'entity_key'      => $entityKey,
            'entity_type'     => $entityType,
            'tenant_id'       => $tenantId,
            'dimension'       => $dimension,
            'observed_value'  => $value,
            'source_table'    => $sourceTable,
            'source_event_id' => $sourceEventId,
            'trace_id'        => $traceId,
            'context'         => $context ?: null,
            'advisory_only'   => true,
            'observed_at'     => now(),
            'created_at'      => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Anomaly scoring
    // -----------------------------------------------------------------------

    /**
     * Score an observed value against the stored baseline for an entity+dimension.
     * Returns null if the baseline has insufficient samples.
     *
     * Every returned score includes:
     *   - metric name, observed value, baseline value, deviation
     *   - scoring method, confidence, evidence references, trace_ids
     *   - is_advisory = true (always — no autonomous action)
     */
    public function scoreAnomaly(
        string  $entityKey,
        string  $entityType,
        string  $dimension,
        float   $observedValue,
        array   $evidenceRefs = [],
        array   $traceIds     = [],
        ?string $traceId      = null,
        ?string $tenantId     = null
    ): ?BaselineAnomalyScore {
        $baseline = EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('dimension', $dimension)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$baseline || $baseline->sample_count < self::MIN_SAMPLES_FOR_SCORING) {
            return null;
        }

        $baselineValue = $baseline->baseline_median ?? $baseline->baseline_mean ?? 0.0;
        $deviation     = abs($observedValue - $baselineValue);
        $zScore        = $this->robustZScore($observedValue, $baseline->baseline_median, $baseline->baseline_mad);
        $pctRank       = $this->percentileRankFromBaseline($observedValue, $baseline);

        $confidence   = $this->computeConfidence($zScore, $baseline->sample_count);
        $anomalyType  = self::DIMENSION_ANOMALY_MAP[$dimension] ?? 'peer_group_behavior_deviation';

        if ($traceId && !in_array($traceId, $traceIds, true)) {
            $traceIds[] = $traceId;
        }

        return BaselineAnomalyScore::create([
            'score_id'           => (string) Str::uuid(),
            'entity_key'         => $entityKey,
            'entity_type'        => $entityType,
            'tenant_id'          => $tenantId,
            'anomaly_type'       => $anomalyType,
            'dimension'          => $dimension,
            'observed_value'     => $observedValue,
            'baseline_value'     => $baselineValue,
            'deviation'          => $deviation,
            'z_score'            => $zScore,
            'percentile_rank'    => $pctRank,
            'scoring_method'     => 'robust_z_score',
            'confidence'         => $confidence,
            'evidence_references'=> $evidenceRefs ?: null,
            'trace_ids'          => $traceIds ?: null,
            'peer_group_key'     => $baseline->peer_group_key,
            'is_advisory'        => true,
            'acted_on'           => false,
            'scored_at'          => now(),
            'created_at'         => now(),
        ]);
    }

    /**
     * Score deviation from peer group mean for an entity.
     * Compares the entity's own baseline to the group's dimension stats.
     */
    public function scorePeerGroupDeviation(
        string $entityKey,
        string $entityType,
        string $dimension,
        string $peerGroupKey,
        ?string $tenantId = null
    ): ?BaselineAnomalyScore {
        $entityBaseline = EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('dimension', $dimension)
            ->where('tenant_id', $tenantId)
            ->first();

        $peerGroup = PeerGroupProfile::where('peer_group_key', $peerGroupKey)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$entityBaseline || !$peerGroup || empty($peerGroup->dimension_stats)) {
            return null;
        }

        $dimStats = $peerGroup->dimension_stats[$dimension] ?? null;
        if (!$dimStats || ($dimStats['sample_count'] ?? 0) < self::MIN_SAMPLES_FOR_SCORING) {
            return null;
        }

        $peerMean   = (float) ($dimStats['mean'] ?? 0.0);
        $peerStddev = (float) ($dimStats['stddev'] ?? 0.0);
        $entityVal  = $entityBaseline->baseline_mean ?? 0.0;

        $deviation      = abs($entityVal - $peerMean);
        $peerDeviation  = ($peerStddev > 0) ? ($deviation / $peerStddev) : 0.0;
        $confidence     = $this->computeConfidence($peerDeviation, $dimStats['sample_count'] ?? 0);

        return BaselineAnomalyScore::create([
            'score_id'            => (string) Str::uuid(),
            'entity_key'          => $entityKey,
            'entity_type'         => $entityType,
            'tenant_id'           => $tenantId,
            'anomaly_type'        => 'peer_group_behavior_deviation',
            'dimension'           => $dimension,
            'observed_value'      => $entityVal,
            'baseline_value'      => $peerMean,
            'deviation'           => $deviation,
            'z_score'             => null,
            'percentile_rank'     => null,
            'scoring_method'      => 'peer_group_deviation',
            'confidence'          => $confidence,
            'evidence_references' => null,
            'trace_ids'           => null,
            'peer_group_key'      => $peerGroupKey,
            'peer_group_deviation'=> $peerDeviation,
            'is_advisory'         => true,
            'acted_on'            => false,
            'scored_at'           => now(),
            'created_at'          => now(),
        ]);
    }

    /**
     * Run all applicable anomaly detectors for an entity.
     * Returns collection of BaselineAnomalyScore (may be empty if no anomalies or insufficient data).
     * Advisory-only — never triggers enforcement.
     */
    public function detectAnomalies(string $entityKey, string $entityType, ?string $tenantId = null): Collection
    {
        $scores = collect();

        // Collect current observed values from recent activity
        $observed = $this->collectCurrentObservations($entityKey, $entityType);

        foreach ($observed as $dimension => $value) {
            $score = $this->scoreAnomaly($entityKey, $entityType, $dimension, $value, [], [], null, $tenantId);
            if ($score && abs($score->z_score ?? 0.0) >= self::ANOMALY_Z_THRESHOLD) {
                $scores->push($score);
            }
        }

        // Peer group deviation check
        $baseline = EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('peer_group_key')
            ->first();

        if ($baseline?->peer_group_key) {
            foreach (EntityBehaviorBaseline::DIMENSIONS as $dim) {
                $pgScore = $this->scorePeerGroupDeviation(
                    $entityKey, $entityType, $dim, $baseline->peer_group_key, $tenantId
                );
                if ($pgScore && ($pgScore->peer_group_deviation ?? 0.0) >= self::PEER_DEVIATION_THRESHOLD) {
                    $scores->push($pgScore);
                }
            }
        }

        return $scores;
    }

    // -----------------------------------------------------------------------
    // Peer group management
    // -----------------------------------------------------------------------

    /**
     * Assign an entity to a deterministic peer group based on entity type and attributes.
     * No sensitive attribute inference — uses only public entity_type and metadata.
     */
    /**
     * ENT-TENANCY-UEBA: $tenantId is part of the peer group's identity, not
     * just a tag. deriveGroupKey() intentionally still produces a global
     * label like "user_role:admin" (kept human-readable/unchanged) — tenant
     * separation is enforced by including tenant_id in the firstOrCreate
     * lookup criteria, backed by the DB-level
     * peer_group_profiles_key_tenant_unique expression index. Without this,
     * two tenants' admin users would be assigned to the exact same peer
     * group row and their behavioral baselines would be mixed together.
     */
    public function assignPeerGroup(string $entityKey, string $entityType, array $attributes = [], ?string $tenantId = null): ?PeerGroupProfile
    {
        $groupKey  = $this->deriveGroupKey($entityType, $attributes);
        $groupType = $this->entityTypeToGroupType($entityType);
        $label     = $this->deriveGroupLabel($entityType, $attributes);

        $group = PeerGroupProfile::firstOrCreate(
            ['peer_group_key' => $groupKey, 'tenant_id' => $tenantId],
            [
                'group_type'       => $groupType,
                'group_label'      => $label,
                'criteria'         => $this->buildGroupCriteria($entityType, $attributes),
                'advisory_only'    => true,
                'computed_at'      => now(),
            ]
        );

        // Add entity to members (bounded)
        $members = $group->member_entity_keys ?? [];
        if (!in_array($entityKey, $members, true)) {
            $members[] = $entityKey;
            if (count($members) > self::MAX_PEER_GROUP_MEMBERS) {
                array_shift($members);
            }
            $group->update([
                'member_entity_keys' => $members,
                'entity_count'       => count($members),
            ]);
        }

        // Update baselines to reference this peer group
        EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->where('tenant_id', $tenantId)
            ->update(['peer_group_key' => $groupKey]);

        return $group->fresh();
    }

    /**
     * Recompute aggregate dimension stats for a peer group.
     * Deterministic — uses stored baselines of all members.
     */
    public function computePeerGroupProfile(string $peerGroupKey, ?string $tenantId = null): ?PeerGroupProfile
    {
        $group = PeerGroupProfile::where('peer_group_key', $peerGroupKey)
            ->where('tenant_id', $tenantId)
            ->first();
        if (!$group) {
            return null;
        }

        $members = $group->member_entity_keys ?? [];
        if (empty($members)) {
            return $group;
        }

        $dimensionStats = [];
        foreach (EntityBehaviorBaseline::DIMENSIONS as $dim) {
            $values = EntityBehaviorBaseline::whereIn('entity_key', $members)
                ->where('dimension', $dim)
                ->where('tenant_id', $tenantId)
                ->whereNotNull('baseline_mean')
                ->pluck('baseline_mean')
                ->map(fn ($v) => (float) $v)
                ->values()
                ->toArray();

            if (count($values) >= 2) {
                $stats = $this->computeStats($values);
                $dimensionStats[$dim] = [
                    'mean'         => $stats['mean'],
                    'median'       => $stats['median'],
                    'stddev'       => $stats['stddev'],
                    'p10'          => $stats['p10'],
                    'p90'          => $stats['p90'],
                    'sample_count' => count($values),
                ];
            }
        }

        $group->update([
            'dimension_stats' => $dimensionStats,
            'entity_count'    => count($members),
            'computed_at'     => now(),
        ]);

        return $group->fresh();
    }

    // -----------------------------------------------------------------------
    // Profile & history
    // -----------------------------------------------------------------------

    /**
     * Build a complete advisory-only baseline profile for an entity.
     * Returns all dimensions with baselines, recent anomaly scores, and peer group.
     */
    public function buildBaselineProfile(string $entityKey, string $entityType, ?string $tenantId = null): array
    {
        $baselines = EntityBehaviorBaseline::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get();

        $recentScores = BaselineAnomalyScore::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('scored_at')
            ->limit(50)
            ->get();

        $peerGroupKey = $baselines->whereNotNull('peer_group_key')->first()?->peer_group_key;
        $peerGroup    = $peerGroupKey
            ? PeerGroupProfile::where('peer_group_key', $peerGroupKey)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->first()
            : null;

        return [
            'entity_key'      => $entityKey,
            'entity_type'     => $entityType,
            'baselines'       => $baselines->values()->toArray(),
            'anomaly_scores'  => $recentScores->values()->toArray(),
            'peer_group'      => $peerGroup?->toArray(),
            'advisory_only'   => true,
            'disclaimer'      => 'Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.',
            'generated_at'    => now()->toDateTimeString(),
        ];
    }

    /**
     * Retrieve recent anomaly history for an entity.
     */
    public function getAnomalyHistory(string $entityKey, string $entityType, int $limit = 100, ?string $tenantId = null): Collection
    {
        return BaselineAnomalyScore::where('entity_key', $entityKey)
            ->where('entity_type', $entityType)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('scored_at')
            ->limit(min($limit, self::MAX_ANOMALY_HISTORY))
            ->get();
    }

    /**
     * Get top anomalous entities by recent high-confidence anomaly scores.
     */
    public function getTopAnomalousEntities(string $entityType = '', int $limit = 20, ?string $tenantId = null): Collection
    {
        $query = DB::table('baseline_anomaly_scores')
            ->select('entity_key', 'entity_type', DB::raw('count(*) as anomaly_count'), DB::raw('max(confidence) as max_confidence'), DB::raw('max(scored_at) as last_anomaly_at'))
            ->where('is_advisory', true)
            ->where('confidence', '>=', BaselineAnomalyScore::CONFIDENCE_MEDIUM)
            ->where('scored_at', '>=', now()->subDays(7))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('entity_key', 'entity_type')
            ->orderByDesc('anomaly_count')
            ->limit($limit);

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->get();
    }

    /**
     * Get baseline drift summary — entities whose baselines have shifted significantly.
     */
    public function getBaselineDriftSummary(int $limit = 50, ?string $tenantId = null): Collection
    {
        return DB::table('entity_behavior_baselines')
            ->select('entity_key', 'entity_type', 'dimension', 'baseline_mean', 'baseline_stddev', 'sample_count', 'computed_at')
            ->whereNotNull('baseline_stddev')
            ->where('baseline_stddev', '>', 0)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('baseline_stddev')
            ->limit($limit)
            ->get();
    }

    /**
     * Get anomaly volume grouped by hour for trend charts.
     */
    public function getAnomalyVolumeTrend(int $days = 7, ?string $tenantId = null): Collection
    {
        return DB::table('baseline_anomaly_scores')
            ->select(DB::raw("date_trunc('hour', scored_at) as hour"), DB::raw('count(*) as count'), 'anomaly_type')
            ->where('scored_at', '>=', now()->subDays($days))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy(DB::raw("date_trunc('hour', scored_at)"), 'anomaly_type')
            ->orderBy(DB::raw("date_trunc('hour', scored_at)"))
            ->get();
    }

    // -----------------------------------------------------------------------
    // Deterministic scoring math — public for unit testing
    // -----------------------------------------------------------------------

    /**
     * Robust z-score using median absolute deviation (MAD).
     * Outlier-resistant compared to mean/stddev based z-score.
     * Formula: (value − median) / (1.4826 × MAD)
     */
    public function robustZScore(float $value, ?float $median, ?float $mad): float
    {
        if ($median === null || $mad === null || $mad < 1e-10) {
            return 0.0;
        }
        return ($value - $median) / (1.4826 * $mad);
    }

    /**
     * Percentile rank of a value within a sorted array of reference values.
     * Returns 0–100.
     */
    public function percentileRank(float $value, array $sortedValues): float
    {
        if (empty($sortedValues)) {
            return 50.0;
        }
        $below = count(array_filter($sortedValues, fn ($v) => $v < $value));
        return round(($below / count($sortedValues)) * 100.0, 2);
    }

    /**
     * Compute median absolute deviation (MAD) from a set of values.
     */
    public function computeMAD(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $median = $this->computeMedian($values);
        $deviations = array_map(fn ($v) => abs($v - $median), $values);
        return $this->computeMedian($deviations);
    }

    /**
     * Compute median of an array of floats. Deterministic.
     */
    public function computeMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return ($n % 2 === 0)
            ? (($values[$mid - 1] + $values[$mid]) / 2.0)
            : (float) $values[$mid];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Compute all stats needed for a baseline from an array of float observations.
     */
    private function computeStats(array $values): array
    {
        $n      = count($values);
        $sum    = array_sum($values);
        $mean   = $sum / $n;
        $median = $this->computeMedian($values);
        $mad    = $this->computeMAD($values);

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
        $stddev   = sqrt($variance);

        $sorted = $values;
        sort($sorted);
        $p10 = $sorted[(int) floor(0.10 * ($n - 1))];
        $p90 = $sorted[(int) floor(0.90 * ($n - 1))];

        return compact('mean', 'median', 'mad', 'stddev', 'p10', 'p90');
    }

    private function percentileRankFromBaseline(float $value, EntityBehaviorBaseline $baseline): float
    {
        $p10 = $baseline->baseline_p10 ?? 0.0;
        $p90 = $baseline->baseline_p90 ?? 1.0;
        $range = max($p90 - $p10, 1e-10);
        $rank = (($value - $p10) / $range) * 80.0 + 10.0;
        return round(max(0.0, min(100.0, $rank)), 2);
    }

    private function computeConfidence(float $zOrDeviation, int $sampleCount): float
    {
        $absZ = abs($zOrDeviation);
        if ($absZ < 2.0) {
            return 0.30;
        }
        if ($absZ < 2.5) {
            return 0.50;
        }
        if ($absZ < 3.0) {
            return 0.65;
        }
        if ($absZ < 4.0) {
            return 0.75;
        }
        $base = 0.85;
        // More samples → more confidence (capped at 0.97)
        $sampleBonus = min(0.12, ($sampleCount / 500) * 0.12);
        return min(0.97, round($base + $sampleBonus, 4));
    }

    /**
     * Collect current observed metric values for an entity from recent activity.
     * Uses last 24h window for current-state comparison.
     * Degrades gracefully if a source table is unavailable or has no matching column.
     *
     * ENT-TENANCY-UEBA: deliberately NOT tenant-scoped. Its source tables
     * (identity_provider_events, saas_audit_events, endpoint_agents,
     * endpoint_process_entries, endpoint_behavioral_findings,
     * endpoint_stream_events) have no tenant_id column at all — scoping
     * this method would require new migrations across a half-dozen
     * unrelated tables, out of bounds for this task. Documented residual
     * gap: a same-keyed entity's "current" observation window can still
     * include another tenant's activity, even though the resulting
     * baseline/score persisted from it is correctly tenant-tagged.
     */
    private function collectCurrentObservations(string $entityKey, string $entityType): array
    {
        $since    = now()->subDay();
        $observed = [];

        try {
            if ($entityType === 'user') {
                // login_frequency: logins in last 24h
                $loginCount = DB::table('identity_provider_events')
                    ->where('user_email', $entityKey)
                    ->where('occurred_at', '>=', $since)
                    ->count();
                if ($loginCount > 0) {
                    $observed['login_frequency'] = (float) $loginCount;
                }

                // failed_login_ratio: failed / total
                $total  = DB::table('identity_provider_events')
                    ->where('user_email', $entityKey)->where('occurred_at', '>=', $since)->count();
                $failed = DB::table('identity_provider_events')
                    ->where('user_email', $entityKey)->where('occurred_at', '>=', $since)
                    ->where('is_failed', true)->count();
                if ($total > 0) {
                    $observed['failed_login_ratio'] = (float) round($failed / $total, 4);
                }

                // source_ip_diversity: distinct source IPs
                $ipCount = DB::table('identity_provider_events')
                    ->where('user_email', $entityKey)->where('occurred_at', '>=', $since)
                    ->distinct()->count('source_ip');
                if ($ipCount > 0) {
                    $observed['source_ip_diversity'] = (float) $ipCount;
                }

                // saas_action_frequency: SaaS actions in last 24h
                $saasCount = DB::table('saas_audit_events')
                    ->where('actor_email', $entityKey)->where('occurred_at', '>=', $since)
                    ->count();
                if ($saasCount > 0) {
                    $observed['saas_action_frequency'] = (float) $saasCount;
                }
            }
        } catch (\Exception $e) {
            // Degrade gracefully — observation collection is advisory, not critical
        }

        try {
            if ($entityType === 'host' || $entityType === 'ip') {
                // process_execution_frequency: processes started on this host
                // Use subquery to avoid ambiguous 'created_at' in JOIN
                $agentId = DB::table('endpoint_agents')
                    ->where('hostname', $entityKey)->value('id');
                if ($agentId) {
                    $procCount = DB::table('endpoint_process_entries')
                        ->where('agent_id', $agentId)
                        ->where('endpoint_process_entries.created_at', '>=', $since)
                        ->count();
                    if ($procCount > 0) {
                        $observed['process_execution_frequency'] = (float) $procCount;
                    }

                    // host_usage: behavioral finding frequency
                    $findingCount = DB::table('endpoint_behavioral_findings')
                        ->where('agent_id', $agentId)
                        ->where('endpoint_behavioral_findings.created_at', '>=', $since)
                        ->count();
                    if ($findingCount > 0) {
                        $observed['host_usage'] = (float) $findingCount;
                    }
                }

                // network_destination_frequency: distinct outbound destinations
                $destCount = DB::table('endpoint_stream_events')
                    ->where('host_id', $entityKey)
                    ->where('occurred_at', '>=', $since)
                    ->where('event_type', EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED)
                    ->distinct()->count('connection_dest');
                if ($destCount > 0) {
                    $observed['network_destination_frequency'] = (float) $destCount;
                }
            }
        } catch (\Exception $e) {
            // Degrade gracefully — observation collection is advisory, not critical
        }

        return $observed;
    }

    private function deriveGroupKey(string $entityType, array $attributes): string
    {
        $role     = $attributes['role'] ?? 'default';
        $platform = $attributes['platform'] ?? 'default';
        return match ($entityType) {
            'user'    => "user_role:{$role}",
            'host'    => "host_platform:{$platform}",
            'ip'      => 'network_destination:external',
            'domain'  => 'network_destination:domain',
            'process' => "process_type:{$platform}",
            default   => "{$entityType}:default",
        };
    }

    private function entityTypeToGroupType(string $entityType): string
    {
        return match ($entityType) {
            'user'   => 'user_role',
            'host'   => 'host_function',
            'ip'     => 'network_destination',
            'domain' => 'network_destination',
            default  => 'endpoint_agent',
        };
    }

    private function deriveGroupLabel(string $entityType, array $attributes): string
    {
        return match ($entityType) {
            'user'   => 'Users – ' . ($attributes['role'] ?? 'default role'),
            'host'   => 'Hosts – ' . ($attributes['platform'] ?? 'default platform'),
            'ip'     => 'Network Destinations – External IPs',
            'domain' => 'Network Destinations – Domains',
            default  => ucfirst($entityType) . ' – default group',
        };
    }

    private function buildGroupCriteria(string $entityType, array $attributes): array
    {
        return [
            'entity_type' => $entityType,
            'role'        => $attributes['role'] ?? null,
            'platform'    => $attributes['platform'] ?? null,
        ];
    }
}
