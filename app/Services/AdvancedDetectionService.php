<?php

namespace App\Services;

use App\Models\AdversarialValidationRun;
use App\Models\AttackChainTimeline;
use App\Models\AttackScenarioPack;
use App\Models\ChainedDetectionGraph;
use App\Models\CrossHostCorrelationRun;
use App\Models\DetectionConfidenceReport;
use App\Models\EvasionResilienceReport;
use App\Models\ReplayAttackFixture;
use App\Models\TacticProgressionSnapshot;
use Illuminate\Support\Str;

/**
 * Advanced Detection Coverage & Adversarial Validation Phase 1.
 *
 * All operations are advisory-only, replay-safe, and deterministic.
 * No autonomous enforcement, offensive payload execution, or hidden AI decisions.
 * All chained detections and adversarial validations are evidence-linked and operator-visible.
 */
class AdvancedDetectionService
{
    // ─── Bounded correlation ──────────────────────────────────────────────────

    public const MAX_CHAIN_HOPS          = 10;
    public const MAX_CORRELATION_HOSTS   = 20;
    public const MAX_TACTIC_WINDOW_HOURS = 24;

    // ─── Evasion types ────────────────────────────────────────────────────────

    public const EVASION_TYPES = EvasionResilienceReport::EVASION_TYPES;

    // ─── ATT&CK tactic weights for progression scoring ────────────────────────

    private const TACTIC_WEIGHTS = [
        'initial_access'      => 1.0,
        'execution'           => 1.5,
        'persistence'         => 2.0,
        'privilege_escalation'=> 2.5,
        'defense_evasion'     => 2.0,
        'credential_access'   => 2.5,
        'discovery'           => 1.0,
        'lateral_movement'    => 3.0,
        'collection'          => 2.0,
        'exfiltration'        => 3.5,
        'impact'              => 4.0,
    ];

    // ─── Adversarial Validation ───────────────────────────────────────────────

    /**
     * Run an adversarial replay validation for a scenario.
     * No live exploit execution — uses pre-defined offline fixtures only.
     */
    public function runAdversarialValidation(
        string $scenarioName,
        string $triggeredBy,
        string $attackTactic = '',
        string $attackTechnique = '',
        string $scenarioPackId = '',
        int    $replayEventCount = 0,
        array  $matchedRuleIds = [],
        bool   $detected = false,
        bool   $falsePositiveFree = true,
        array  $validationDetails = [],
    ): AdversarialValidationRun {
        $matchedRules = count($matchedRuleIds);
        $confidence   = $this->computeDetectionConfidence($detected, $falsePositiveFree, $matchedRules);

        $verdict = match (true) {
            $detected && $falsePositiveFree            => AdversarialValidationRun::VERDICT_PASS,
            $detected && !$falsePositiveFree           => AdversarialValidationRun::VERDICT_PARTIAL,
            default                                    => AdversarialValidationRun::VERDICT_FAIL,
        };

        $run = new AdversarialValidationRun([
            'run_id'               => 'avr-' . Str::random(32),
            'scenario_pack_id'     => $scenarioPackId ?: null,
            'scenario_name'        => $scenarioName,
            'attack_tactic'        => $attackTactic,
            'attack_technique'     => $attackTechnique,
            'verdict'              => $verdict,
            'detected'             => $detected,
            'false_positive_free'  => $falsePositiveFree,
            'detection_confidence' => $confidence,
            'replay_event_count'   => $replayEventCount,
            'matched_rules'        => $matchedRules,
            'matched_rule_ids'     => $matchedRuleIds,
            'validation_details'   => $validationDetails,
            'triggered_by'         => $triggeredBy,
        ]);
        $run->save();
        return $run;
    }

    // ─── Chained Detection Graph ──────────────────────────────────────────────

    /**
     * Record a chained behavioral detection.
     * Bounded to MAX_CHAIN_HOPS. Deterministic — no black-box AI.
     */
    public function recordChainedDetection(
        string $chainType,
        array  $nodeSequence,
        array  $tacticSequence,
        string $triggeredBy,
        string $hostId = '',
        string $actor = '',
        array  $evidenceLinks = [],
    ): ChainedDetectionGraph {
        if (!in_array($chainType, ChainedDetectionGraph::CHAIN_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown chain_type: {$chainType}");
        }

        $hopCount  = min(count($nodeSequence), self::MAX_CHAIN_HOPS);
        $confidence = $this->computeChainConfidence($tacticSequence, $hopCount);

        $graph = new ChainedDetectionGraph([
            'graph_id'         => 'cdg-' . Str::random(32),
            'chain_type'       => $chainType,
            'node_sequence'    => array_slice($nodeSequence, 0, self::MAX_CHAIN_HOPS),
            'tactic_sequence'  => $tacticSequence,
            'hop_count'        => $hopCount,
            'chain_confidence' => $confidence,
            'host_id'          => $hostId ?: null,
            'actor'            => $actor ?: null,
            'status'           => 'active',
            'evidence_links'   => $evidenceLinks,
            'triggered_by'     => $triggeredBy,
        ]);
        $graph->save();
        return $graph;
    }

    // ─── Attack Chain Timeline ────────────────────────────────────────────────

    /**
     * Append a timeline event to an attack chain reconstruction.
     */
    public function appendChainTimeline(
        string $tactic,
        string $eventType,
        string $chainId = '',
        string $techniqueId = '',
        string $hostId = '',
        string $actor = '',
        array  $evidenceSnapshot = [],
        int    $sequenceIndex = 0,
        ?string $occurredAt = null,
    ): AttackChainTimeline {
        $entry = new AttackChainTimeline([
            'timeline_id'       => 'act-' . Str::random(32),
            'chain_id'          => $chainId ?: null,
            'tactic'            => $tactic,
            'technique_id'      => $techniqueId ?: null,
            'host_id'           => $hostId ?: null,
            'actor'             => $actor ?: null,
            'event_type'        => $eventType,
            'evidence_snapshot' => $evidenceSnapshot,
            'sequence_index'    => $sequenceIndex,
            'occurred_at'       => $occurredAt ? now()->parse($occurredAt) : now(),
        ]);
        $entry->save();
        return $entry;
    }

    // ─── Evasion Resilience ───────────────────────────────────────────────────

    /**
     * Report evasion resilience test result.
     * Deterministic confidence degradation — no destructive simulation.
     */
    public function reportEvasionResilience(
        string $evasionType,
        string $testedBy,
        string $targetRuleId = '',
        bool   $detectionSurvived = false,
        float  $confidenceDegradation = 0.0,
        array  $degradationFactors = [],
    ): EvasionResilienceReport {
        if (!in_array($evasionType, self::EVASION_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown evasion_type: {$evasionType}");
        }

        $resilienceScore = max(0.0, 1.0 - $confidenceDegradation);

        $report = new EvasionResilienceReport([
            'report_id'              => 'err-' . Str::random(32),
            'evasion_type'           => $evasionType,
            'target_rule_id'         => $targetRuleId ?: null,
            'detection_survived'     => $detectionSurvived,
            'confidence_degradation' => round($confidenceDegradation, 3),
            'resilience_score'       => round($resilienceScore, 3),
            'degradation_factors'    => $degradationFactors,
            'tested_by'              => $testedBy,
        ]);
        $report->save();
        return $report;
    }

    // ─── Detection Confidence ─────────────────────────────────────────────────

    /**
     * Record a detection confidence assessment for a rule.
     */
    public function recordConfidenceReport(
        string $ruleId,
        float  $confidenceScore,
        string $evaluatedBy,
        int    $truePositives = 0,
        int    $falsePositives = 0,
        int    $sampleSize = 0,
        array  $contributingFactors = [],
        string $assessmentMethod = 'replay_validation',
    ): DetectionConfidenceReport {
        $fpRate = ($truePositives + $falsePositives) > 0
            ? round($falsePositives / ($truePositives + $falsePositives), 3)
            : 0.0;

        $report = new DetectionConfidenceReport([
            'report_id'            => 'dcr-' . Str::random(32),
            'rule_id'              => $ruleId,
            'confidence_score'     => round($confidenceScore, 3),
            'true_positive_count'  => $truePositives,
            'false_positive_count' => $falsePositives,
            'replay_sample_size'   => $sampleSize,
            'fp_rate'              => $fpRate,
            'assessment_method'    => $assessmentMethod,
            'contributing_factors' => $contributingFactors,
            'evaluated_by'         => $evaluatedBy,
        ]);
        $report->save();
        return $report;
    }

    // ─── Tactic Progression ───────────────────────────────────────────────────

    /**
     * Snapshot observed ATT&CK tactic progression for a host/actor.
     */
    public function snapshotTacticProgression(
        array  $observedTactics,
        array  $observedTechniques,
        string $hostId = '',
        string $actor = '',
        string $detectionScope = 'endpoint',
    ): TacticProgressionSnapshot {
        $tacticCount      = count($observedTactics);
        $multiStage       = $tacticCount >= 3;
        $progressionScore = $this->computeProgressionScore($observedTactics);

        $snapshot = new TacticProgressionSnapshot([
            'snapshot_id'       => 'tps-' . Str::random(32),
            'host_id'           => $hostId ?: null,
            'actor'             => $actor ?: null,
            'observed_tactics'  => $observedTactics,
            'observed_techniques'=> $observedTechniques,
            'tactic_count'      => $tacticCount,
            'multi_stage'       => $multiStage,
            'progression_score' => round($progressionScore, 3),
            'detection_scope'   => $detectionScope,
        ]);
        $snapshot->save();
        return $snapshot;
    }

    // ─── Cross-Host Correlation ───────────────────────────────────────────────

    /**
     * Run cross-host correlation. Bounded to MAX_CORRELATION_HOSTS.
     */
    public function runCrossHostCorrelation(
        array  $hostIds,
        string $correlationType,
        string $triggeredBy,
        array  $sharedIndicators = [],
        bool   $propagationDetected = false,
    ): CrossHostCorrelationRun {
        if (!in_array($correlationType, CrossHostCorrelationRun::CORRELATION_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown correlation_type: {$correlationType}");
        }

        $boundedHosts = array_slice($hostIds, 0, self::MAX_CORRELATION_HOSTS);
        $confidence   = $this->computeCorrelationConfidence(count($boundedHosts), count($sharedIndicators));

        $run = new CrossHostCorrelationRun([
            'run_id'                 => 'chr-' . Str::random(32),
            'host_ids'               => $boundedHosts,
            'correlation_type'       => $correlationType,
            'host_count'             => count($boundedHosts),
            'propagation_detected'   => $propagationDetected,
            'correlation_confidence' => $confidence,
            'shared_indicators'      => $sharedIndicators,
            'triggered_by'           => $triggeredBy,
        ]);
        $run->save();
        return $run;
    }

    // ─── Scenario Packs & Fixtures ────────────────────────────────────────────

    public function createScenarioPack(
        string $name,
        string $attackTactic,
        array  $techniqueIds,
        string $description = '',
        array  $fixtureEventTypes = [],
        string $difficulty = 'medium',
    ): AttackScenarioPack {
        return AttackScenarioPack::create([
            'pack_id'             => 'asp-' . Str::random(32),
            'name'                => $name,
            'attack_tactic'       => $attackTactic,
            'technique_ids'       => $techniqueIds,
            'description'         => $description,
            'fixture_event_types' => $fixtureEventTypes,
            'difficulty'          => $difficulty,
            'is_active'           => true,
        ]);
    }

    public function createReplayFixture(
        string $name,
        string $attackTactic,
        array  $eventSequence,
        string $fixtureType = 'malicious',
        string $techniqueId = '',
    ): ReplayAttackFixture {
        return ReplayAttackFixture::create([
            'fixture_id'     => 'raf-' . Str::random(32),
            'name'           => $name,
            'attack_tactic'  => $attackTactic,
            'technique_id'   => $techniqueId ?: null,
            'fixture_type'   => $fixtureType,
            'event_sequence' => $eventSequence,
            'is_active'      => true,
        ]);
    }

    // ─── Dashboard Stats ──────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_validation_runs'    => AdversarialValidationRun::count(),
            'pass_runs'                => AdversarialValidationRun::where('verdict', AdversarialValidationRun::VERDICT_PASS)->count(),
            'fail_runs'                => AdversarialValidationRun::where('verdict', AdversarialValidationRun::VERDICT_FAIL)->count(),
            'total_scenario_packs'     => AttackScenarioPack::count(),
            'active_scenario_packs'    => AttackScenarioPack::where('is_active', true)->count(),
            'total_chained_graphs'     => ChainedDetectionGraph::count(),
            'total_evasion_reports'    => EvasionResilienceReport::count(),
            'detection_survived_count' => EvasionResilienceReport::where('detection_survived', true)->count(),
            'total_chain_timelines'    => AttackChainTimeline::count(),
            'multi_stage_progressions' => TacticProgressionSnapshot::where('multi_stage', true)->count(),
            'total_confidence_reports' => DetectionConfidenceReport::count(),
            'total_cross_host_runs'    => CrossHostCorrelationRun::count(),
            'propagation_detected'     => CrossHostCorrelationRun::where('propagation_detected', true)->count(),
            'total_replay_fixtures'    => ReplayAttackFixture::count(),
            'active_replay_fixtures'   => ReplayAttackFixture::where('is_active', true)->count(),
            'advisory_only'            => true,
        ];
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    private function computeDetectionConfidence(bool $detected, bool $fpFree, int $matchedRules): float
    {
        if (!$detected) return 0.0;
        $base = $fpFree ? 0.7 : 0.4;
        $ruleBonus = min(0.3, $matchedRules * 0.05);
        return round(min(1.0, $base + $ruleBonus), 3);
    }

    private function computeChainConfidence(array $tacticSequence, int $hopCount): float
    {
        if ($hopCount === 0) return 0.0;
        $tacticScore = array_reduce($tacticSequence, function ($carry, $t) {
            return $carry + (self::TACTIC_WEIGHTS[$t] ?? 1.0);
        }, 0.0);
        $tacticCount = count($tacticSequence);
        $normalized  = $tacticCount > 0 ? min(1.0, $tacticScore / ($tacticCount * 4.0)) : 0.0;
        $hopFactor = min(1.0, $hopCount / 5.0);
        return round(($normalized * 0.7 + $hopFactor * 0.3), 3);
    }

    private function computeProgressionScore(array $tactics): float
    {
        $score = array_reduce($tactics, fn ($c, $t) => $c + (self::TACTIC_WEIGHTS[$t] ?? 1.0), 0.0);
        return min(1.0, $score / 15.0);
    }

    private function computeCorrelationConfidence(int $hostCount, int $indicatorCount): float
    {
        $hostFactor      = min(1.0, $hostCount / 5.0);
        $indicatorFactor = min(1.0, $indicatorCount / 3.0);
        return round(($hostFactor * 0.4 + $indicatorFactor * 0.6), 3);
    }
}
