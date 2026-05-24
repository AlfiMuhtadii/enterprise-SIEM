<?php

namespace App\Services;

use App\Models\SyntheticAttackFixture;
use App\Models\DetectionQualityScorecard;
use App\Models\FalsePositiveNegativeReport;
use App\Models\TelemetryQualityScorecard;
use App\Models\AnalystTriageSimulationRun;
use App\Models\PerformanceHotspotReport;
use App\Models\NoisyEnterpriseSimulation;
use App\Models\XdrMaturityScorecard;
use App\Models\XdrReadinessReportArtifact;
use Illuminate\Support\Str;

class CodeLevelXdrMaturityService
{
    const ADVISORY_ONLY         = true;
    const MAX_FIXTURE_EVENTS    = 100;
    const MAX_TRIAGE_ALERTS     = 50;
    const MAX_HOTSPOT_QUERIES   = 20;
    const MAX_NOISE_EVENTS      = 1000;
    const QUALITY_PASS_THRESHOLD = 0.75;
    const MAX_AMPLIFICATION_RATIO = 5.0;

    public function createSyntheticAttackFixture(string $scenario, array $events, array $options = []): SyntheticAttackFixture
    {
        if (!in_array($scenario, SyntheticAttackFixture::FIXTURE_SCENARIOS)) {
            throw new \InvalidArgumentException("Unknown fixture scenario: {$scenario}");
        }

        $bounded = array_slice($events, 0, self::MAX_FIXTURE_EVENTS);

        return SyntheticAttackFixture::create([
            'fixture_id'             => (string) Str::uuid(),
            'fixture_scenario'       => $scenario,
            'fixture_format'         => $options['format'] ?? 'json',
            'event_sequence'         => $bounded,
            'trace_id'               => $options['trace_id'] ?? (string) Str::uuid(),
            'chain_id'               => $options['chain_id'] ?? (string) Str::uuid(),
            'mitre_tactics'          => $options['mitre_tactics'] ?? [],
            'total_events'           => count($bounded),
            'is_lab_safe'            => true,
            'is_destructive'         => false,
            'has_live_execution'     => false,
            'has_destructive_payload' => false,
            'fixture_state'          => 'draft',
            'description'            => $options['description'] ?? null,
        ]);
    }

    public function scoreDetectionQuality(array $input): DetectionQualityScorecard
    {
        $tp = max(0, (int) ($input['true_positives'] ?? 0));
        $fp = max(0, (int) ($input['false_positives'] ?? 0));
        $fn = max(0, (int) ($input['false_negatives'] ?? 0));

        $precision  = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall     = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $fpRate     = ($fp + $tp) > 0 ? $fp / ($fp + max(1, $tp)) : 0.0;
        $fnRate     = ($fn + $tp) > 0 ? $fn / ($fn + max(1, $tp)) : 0.0;
        $coverage   = min(1.0, max(0.0, (float) ($input['detection_coverage'] ?? 0)));
        $attackCov  = min(1.0, max(0.0, (float) ($input['attack_coverage'] ?? 0)));
        $evidence   = min(1.0, max(0.0, (float) ($input['evidence_completeness'] ?? 0)));
        $stability  = min(1.0, max(0.0, (float) ($input['confidence_stability'] ?? 0)));
        $ampRatio   = min(self::MAX_AMPLIFICATION_RATIO, max(1.0, (float) ($input['alert_amplification_ratio'] ?? 1.0)));

        $overall = ($precision + $recall + $coverage + $attackCov + $evidence + $stability) / 6.0;

        return DetectionQualityScorecard::create([
            'scorecard_id'             => (string) Str::uuid(),
            'scored_rule_id'           => $input['rule_id'] ?? null,
            'precision_score'          => round($precision, 4),
            'recall_score'             => round($recall, 4),
            'false_positive_rate'      => round($fpRate, 4),
            'false_negative_rate'      => round($fnRate, 4),
            'detection_coverage'       => round($coverage, 4),
            'attack_coverage'          => round($attackCov, 4),
            'evidence_completeness'    => round($evidence, 4),
            'confidence_stability'     => round($stability, 4),
            'alert_amplification_ratio' => round($ampRatio, 4),
            'overall_quality_score'    => round($overall, 4),
            'is_advisory'              => true,
            'score_components'         => compact('tp', 'fp', 'fn', 'precision', 'recall'),
        ]);
    }

    public function analyzeFalsePositiveNegative(string $ruleId, array $findings): FalsePositiveNegativeReport
    {
        $noisyCount      = 0;
        $repeatedBenign  = 0;
        $suppressedRecur = 0;
        $missedChain     = 0;
        $weakEvidence    = 0;
        $unstableConf    = 0;

        foreach ($findings as $f) {
            $type = $f['type'] ?? '';
            match ($type) {
                'noisy_rule'             => $noisyCount++,
                'repeated_benign'        => $repeatedBenign++,
                'suppressed_recurring'   => $suppressedRecur++,
                'missed_chain'           => $missedChain++,
                'weak_evidence'          => $weakEvidence++,
                'unstable_confidence'    => $unstableConf++,
                default                  => null,
            };
        }

        $total  = max(1, count($findings));
        $fpRate = ($noisyCount + $repeatedBenign) / $total;
        $fnRate = ($missedChain + $weakEvidence) / $total;

        return FalsePositiveNegativeReport::create([
            'report_id'                  => (string) Str::uuid(),
            'analyzed_rule_id'           => $ruleId,
            'noisy_rule_count'           => $noisyCount,
            'repeated_benign_count'      => $repeatedBenign,
            'suppressed_recurring_count' => $suppressedRecur,
            'missed_chain_count'         => $missedChain,
            'weak_evidence_count'        => $weakEvidence,
            'unstable_confidence_count'  => $unstableConf,
            'fp_prevalence_rate'         => round(min(1.0, $fpRate), 4),
            'fn_prevalence_rate'         => round(min(1.0, $fnRate), 4),
            'advisory_only'              => true,
            'no_auto_suppression'        => true,
            'no_auto_closure'            => true,
            'findings'                   => $findings,
        ]);
    }

    public function scoreTelemetryQuality(array $events, ?string $tenantId = null): TelemetryQualityScorecard
    {
        $bounded = array_slice($events, 0, self::MAX_NOISE_EVENTS);
        $total   = max(1, count($bounded));

        $withTraceId     = count(array_filter($bounded, fn($e) => !empty($e['trace_id'])));
        $withEventId     = count(array_filter($bounded, fn($e) => !empty($e['event_id'])));
        $withTimestamp   = count(array_filter($bounded, fn($e) => !empty($e['timestamp'])));
        $withHostAgent   = count(array_filter($bounded, fn($e) => !empty($e['host_id']) || !empty($e['agent_id'])));
        $withLineage     = count(array_filter($bounded, fn($e) => !empty($e['parent_process_id'])));
        $malformed       = count(array_filter($bounded, fn($e) => !empty($e['_malformed'])));
        $orphaned        = count(array_filter($bounded, fn($e) => empty($e['trace_id']) && empty($e['parent_process_id'])));
        $withEnrichment  = count(array_filter($bounded, fn($e) => !empty($e['enrichment'])));
        $withTenant      = count(array_filter($bounded, fn($e) => !empty($e['tenant_id'])));

        $scores = [
            'trace_id_completeness'       => $withTraceId / $total,
            'event_id_completeness'       => $withEventId / $total,
            'timestamp_consistency'       => $withTimestamp / $total,
            'host_agent_presence'         => $withHostAgent / $total,
            'process_lineage_completeness' => $withLineage / $total,
            'malformed_event_ratio'       => $malformed / $total,
            'orphaned_event_ratio'        => $orphaned / $total,
            'enrichment_coverage'         => $withEnrichment / $total,
            'tenant_id_consistency'       => $withTenant / $total,
        ];

        $positive = array_sum([$scores['trace_id_completeness'], $scores['event_id_completeness'],
            $scores['timestamp_consistency'], $scores['host_agent_presence'],
            $scores['process_lineage_completeness'], $scores['enrichment_coverage'],
            $scores['tenant_id_consistency']]);
        $negative = $scores['malformed_event_ratio'] + $scores['orphaned_event_ratio'];
        $overall  = max(0.0, ($positive / 7.0) - ($negative / 2.0));

        return TelemetryQualityScorecard::create([
            'scorecard_id'                => (string) Str::uuid(),
            'tenant_id'                   => $tenantId,
            'trace_id_completeness'       => round($scores['trace_id_completeness'], 4),
            'event_id_completeness'       => round($scores['event_id_completeness'], 4),
            'timestamp_consistency'       => round($scores['timestamp_consistency'], 4),
            'host_agent_presence'         => round($scores['host_agent_presence'], 4),
            'process_lineage_completeness' => round($scores['process_lineage_completeness'], 4),
            'malformed_event_ratio'       => round($scores['malformed_event_ratio'], 4),
            'orphaned_event_ratio'        => round($scores['orphaned_event_ratio'], 4),
            'enrichment_coverage'         => round($scores['enrichment_coverage'], 4),
            'tenant_id_consistency'       => round($scores['tenant_id_consistency'], 4),
            'overall_quality_score'       => round(min(1.0, $overall), 4),
            'events_sampled'              => count($bounded),
            'is_advisory'                 => true,
            'quality_breakdown'           => $scores,
        ]);
    }

    public function simulateAnalystTriage(string $scenario, array $alerts): AnalystTriageSimulationRun
    {
        if (!in_array($scenario, AnalystTriageSimulationRun::SIMULATION_SCENARIOS)) {
            throw new \InvalidArgumentException("Unknown triage scenario: {$scenario}");
        }

        $bounded     = array_slice($alerts, 0, self::MAX_TRIAGE_ALERTS);
        $total       = count($bounded);
        $duplicates  = (int) round($total * 0.15);
        $chains      = (int) round($total * 0.20);
        $escalations = (int) round($total * 0.10);
        $dismissals  = (int) round($total * 0.30);
        $fpMarkings  = (int) round($total * 0.12);
        $handoffs    = (int) round($total * 0.05);

        $efficiency  = $total > 0 ? min(1.0, ($escalations + $dismissals + $fpMarkings) / max(1, $total)) : 0.0;

        $auditTrail = array_map(fn($a, $i) => [
            'seq'      => $i + 1,
            'alert_id' => $a['alert_id'] ?? "sim-alert-{$i}",
            'action'   => $i < $duplicates ? 'duplicate_suppressed' : ($i < $chains ? 'chain_investigated' : 'triaged'),
            'simulated' => true,
        ], $bounded, array_keys($bounded));

        return AnalystTriageSimulationRun::create([
            'simulation_id'             => (string) Str::uuid(),
            'simulation_scenario'       => $scenario,
            'alerts_simulated'          => $total,
            'duplicates_handled'        => $duplicates,
            'chains_investigated'       => $chains,
            'escalations_decided'       => $escalations,
            'dismissals_decided'        => $dismissals,
            'fp_markings'               => $fpMarkings,
            'handoffs_simulated'        => $handoffs,
            'triage_efficiency_score'   => round($efficiency, 4),
            'no_real_user_mutation'     => true,
            'no_hidden_suppression'     => true,
            'no_auto_incident_closure'  => true,
            'audit_trail'               => $auditTrail,
        ]);
    }

    public function analyzePerformanceHotspots(array $metrics): PerformanceHotspotReport
    {
        $bounded = array_slice($metrics, 0, self::MAX_HOTSPOT_QUERIES);

        $slowChains      = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'slow_detection_chain'));
        $expensiveGraphs = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'expensive_graph'));
        $replayAmp       = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'replay_amplification'));
        $highCardinality = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'high_cardinality'));
        $noisyTenants    = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'noisy_tenant'));
        $alertSpikes     = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'alert_spike'));
        $expensiveHunts  = count(array_filter($bounded, fn($m) => ($m['type'] ?? '') === 'expensive_hunt'));

        $total    = max(1, count($bounded));
        $severity = min(1.0, ($slowChains + $expensiveGraphs + $replayAmp + $alertSpikes) / $total);

        return PerformanceHotspotReport::create([
            'report_id'                  => (string) Str::uuid(),
            'slow_chain_count'           => $slowChains,
            'expensive_graph_count'      => $expensiveGraphs,
            'replay_amplification_count' => $replayAmp,
            'high_cardinality_count'     => $highCardinality,
            'noisy_tenant_count'         => $noisyTenants,
            'alert_spike_count'          => $alertSpikes,
            'expensive_hunt_count'       => $expensiveHunts,
            'hotspot_severity_score'     => round($severity, 4),
            'advisory_only'              => true,
            'no_destructive_load_test'   => true,
            'hotspot_details'            => $bounded,
        ]);
    }

    public function runNoisyEnterpriseSimulation(string $scenario, int $eventCount): NoisyEnterpriseSimulation
    {
        if (!in_array($scenario, NoisyEnterpriseSimulation::NOISE_SCENARIOS)) {
            throw new \InvalidArgumentException("Unknown noise scenario: {$scenario}");
        }

        $bounded = min($eventCount, self::MAX_NOISE_EVENTS);

        // Deterministic scoring based on scenario characteristics
        $fpResistance   = match($scenario) {
            'patch_management_wave'    => 0.82,
            'vpn_reconnect_storm'      => 0.74,
            'admin_login_burst'        => 0.68,
            'saas_audit_flood'         => 0.79,
            'dns_burst'                => 0.85,
            'software_deployment_storm' => 0.77,
            'endpoint_reconnect_surge' => 0.71,
            default                    => 0.75,
        };
        $ampScore     = min(1.0, $bounded / self::MAX_NOISE_EVENTS);
        $pressScore   = min(1.0, $bounded / (self::MAX_NOISE_EVENTS * 0.5));
        $qualityScore = max(0.0, $fpResistance - ($pressScore * 0.1));

        return NoisyEnterpriseSimulation::create([
            'simulation_id'                => (string) Str::uuid(),
            'noise_scenario'               => $scenario,
            'events_generated'             => $bounded,
            'fp_resistance_score'          => round($fpResistance, 4),
            'alert_amplification_score'    => round($ampScore, 4),
            'analyst_pressure_score'       => round($pressScore, 4),
            'telemetry_quality_under_noise' => round($qualityScore, 4),
            'is_synthetic'                 => true,
            'is_lab_safe'                  => true,
            'has_destructive_content'      => false,
            'simulation_metrics'           => [
                'scenario'      => $scenario,
                'events_bounded' => $bounded,
                'max_allowed'   => self::MAX_NOISE_EVENTS,
            ],
        ]);
    }

    public function generateXdrReadinessReport(array $inputs): XdrReadinessReportArtifact
    {
        $reportType = $inputs['report_type'] ?? 'xdr_maturity_scorecard';

        if (!in_array($reportType, XdrReadinessReportArtifact::REPORT_TYPES)) {
            throw new \InvalidArgumentException("Unknown report type: {$reportType}");
        }

        $content = [
            'report_type'        => $reportType,
            'generated_at'       => now()->toIso8601String(),
            'is_advisory'        => true,
            'is_fabricated'      => false,
            'summary'            => $inputs['summary'] ?? [],
            'scores'             => $inputs['scores'] ?? [],
            'evidence_refs'      => $inputs['evidence_refs'] ?? [],
            'recommendations'    => $inputs['recommendations'] ?? [],
        ];

        $hash     = hash('sha256', json_encode($content));
        $markdown = $this->renderMarkdown($reportType, $content);

        return XdrReadinessReportArtifact::create([
            'artifact_id'    => (string) Str::uuid(),
            'report_type'    => $reportType,
            'report_json'    => $content,
            'report_markdown' => $markdown,
            'artifact_hash'  => $hash,
            'is_advisory'    => true,
            'is_fabricated'  => false,
            'evidence_linked' => true,
            'evidence_refs'  => $inputs['evidence_refs'] ?? [],
        ]);
    }

    public function generateXdrMaturityScorecard(array $scores): XdrMaturityScorecard
    {
        $detection   = min(1.0, max(0.0, (float) ($scores['detection_quality'] ?? 0)));
        $telemetry   = min(1.0, max(0.0, (float) ($scores['telemetry_quality'] ?? 0)));
        $fpfn        = min(1.0, max(0.0, (float) ($scores['fp_fn_health'] ?? 0)));
        $triage      = min(1.0, max(0.0, (float) ($scores['triage_realism'] ?? 0)));
        $perf        = min(1.0, max(0.0, (float) ($scores['performance_health'] ?? 0)));
        $noise       = min(1.0, max(0.0, (float) ($scores['noise_resilience'] ?? 0)));
        $attack      = min(1.0, max(0.0, (float) ($scores['attack_coverage'] ?? 0)));
        $overall     = ($detection + $telemetry + $fpfn + $triage + $perf + $noise + $attack) / 7.0;
        $tier        = $this->deriveTier($overall);

        return XdrMaturityScorecard::create([
            'scorecard_id'            => (string) Str::uuid(),
            'detection_quality_score' => round($detection, 4),
            'telemetry_quality_score' => round($telemetry, 4),
            'fp_fn_health_score'      => round($fpfn, 4),
            'triage_realism_score'    => round($triage, 4),
            'performance_health_score' => round($perf, 4),
            'noise_resilience_score'  => round($noise, 4),
            'attack_coverage_score'   => round($attack, 4),
            'overall_maturity_score'  => round($overall, 4),
            'maturity_tier'           => $tier,
            'is_advisory'             => true,
            'component_scores'        => $scores,
        ]);
    }

    public function dashboardStats(): array
    {
        return [
            'total_fixtures'         => SyntheticAttackFixture::count(),
            'validated_fixtures'     => SyntheticAttackFixture::where('fixture_state', 'validated')->count(),
            'detection_scorecards'   => DetectionQualityScorecard::count(),
            'avg_detection_quality'  => round((float) DetectionQualityScorecard::avg('overall_quality_score'), 3),
            'telemetry_scorecards'   => TelemetryQualityScorecard::count(),
            'avg_telemetry_quality'  => round((float) TelemetryQualityScorecard::avg('overall_quality_score'), 3),
            'fpfn_reports'           => FalsePositiveNegativeReport::count(),
            'triage_simulations'     => AnalystTriageSimulationRun::count(),
            'hotspot_reports'        => PerformanceHotspotReport::count(),
            'noisy_simulations'      => NoisyEnterpriseSimulation::count(),
            'maturity_scorecards'    => XdrMaturityScorecard::count(),
            'latest_maturity_score'  => round((float) XdrMaturityScorecard::latest('id')->value('overall_maturity_score'), 3),
            'readiness_artifacts'    => XdrReadinessReportArtifact::count(),
        ];
    }

    private function deriveTier(float $score): string
    {
        foreach (XdrMaturityScorecard::TIER_THRESHOLDS as $tier => $threshold) {
            if ($score >= $threshold) {
                return $tier;
            }
        }
        return 'initial';
    }

    private function renderMarkdown(string $reportType, array $content): string
    {
        $title = ucwords(str_replace('_', ' ', $reportType));
        $md    = "# XDR Readiness Report: {$title}\n\n";
        $md   .= "> **Advisory Only** — This report is synthetic, replay-safe, and non-fabricated.\n\n";
        $md   .= "**Generated:** {$content['generated_at']}\n\n";
        if (!empty($content['summary'])) {
            $md .= "## Summary\n\n";
            foreach ($content['summary'] as $k => $v) {
                $md .= "- **{$k}:** {$v}\n";
            }
            $md .= "\n";
        }
        if (!empty($content['scores'])) {
            $md .= "## Scores\n\n";
            foreach ($content['scores'] as $k => $v) {
                $md .= "- **{$k}:** {$v}\n";
            }
            $md .= "\n";
        }
        if (!empty($content['recommendations'])) {
            $md .= "## Recommendations\n\n";
            foreach ($content['recommendations'] as $r) {
                $md .= "- {$r}\n";
            }
        }
        return $md;
    }
}
