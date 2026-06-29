<?php

namespace App\Services;

use App\Models\AdvisoryFinding;
use App\Models\ShadowSoakRun;
use App\Models\ShadowSoakEvidenceSnapshot;
use App\Models\ShadowSoakGateCheck;
use App\Models\ShadowSoakDomainAssessment;
use App\Models\ShadowSoakFindingSummary;
use App\Models\ShadowSoakConfidenceBand;
use App\Models\ShadowSoakSuppressionStat;
use App\Models\ShadowSoakCoverageStat;
use App\Models\ShadowSoakAuditEvent;
use Illuminate\Support\Str;

/**
 * Domain Soak Harness — BACKLOG-PROMOTION-018
 *
 * Advisory-only evidence accumulation for shadow domain promotion decisions.
 *
 * NEVER modifies ACTIVE_ALLOWLIST. NEVER promotes shadow rules to active.
 * NEVER creates security_alerts or security_incidents.
 * NEVER triggers cutover. Evidence is advisory — promotion requires a separate
 * domain-specific 6h soak PASS and explicit operator decision.
 */
class DomainSoakHarnessService
{
    public const ADVISORY_ONLY                  = true;
    public const ACTIVE_ALLOWLIST_MUTATION_FORBIDDEN = true;

    public const SUPPORTED_DOMAINS = ['endpoint', 'network', 'ueba'];

    public const MIN_WINDOW_HOURS = 1;
    public const MAX_WINDOW_HOURS = 168;

    // Minimum findings to consider a soak evidence-rich
    public const MIN_FINDINGS_FOR_SOAK = 10;

    // Gate thresholds
    public const MIN_HIGH_CONFIDENCE_RATE    = 0.50;   // >= 50% findings with confidence >= 0.65
    public const MIN_HIGH_CONFIDENCE_SCORE   = 0.65;   // threshold for "high confidence"
    public const MAX_SUPPRESSION_RATE        = 0.40;   // <= 40% findings suppressed/dismissed
    public const PROMOTION_EVIDENCE_PASS_RATE = 0.80;  // ≥ 80% of gates must pass for evidence_pass

    // Domain → expected shadow rule count from registry
    public const DOMAIN_SHADOW_RULE_COUNTS = [
        'endpoint' => 40,
        'network'  => 9,
        'ueba'     => 9,
    ];

    // =========================================================================
    // Public API
    // =========================================================================

    public function startSoakRun(
        string $domain,
        int    $windowHours,
        bool   $dryRun   = false,
        string $actor    = null,
        string $tenantId = null
    ): ShadowSoakRun {
        $this->assertDomain($domain);
        $windowHours = max(self::MIN_WINDOW_HOURS, min(self::MAX_WINDOW_HOURS, $windowHours));

        $run = ShadowSoakRun::create([
            'run_id'       => 'soak-' . Str::uuid(),
            'domain'       => $domain,
            'window_hours' => $windowHours,
            'status'       => $dryRun ? 'dry_run' : 'running',
            'dry_run'      => $dryRun,
            'started_at'   => now(),
            'initiated_by' => $actor,
            'tenant_id'    => $tenantId,
        ]);

        $this->audit($run->run_id, $domain, 'run_started', $actor, [
            'window_hours' => $windowHours,
            'dry_run'      => $dryRun,
        ]);

        return $run;
    }

    public function computeEvidence(ShadowSoakRun $run): ShadowSoakEvidenceSnapshot
    {
        $since = now()->subHours($run->window_hours);

        $query = AdvisoryFinding::where('domain', $run->domain)
            ->where('last_seen_at', '>=', $since);

        if ($run->tenant_id) {
            $query->where('tenant_id', $run->tenant_id);
        }

        $findings = $query->get();

        $total       = $findings->count();
        $highConf    = $findings->where('confidence', '>=', self::MIN_HIGH_CONFIDENCE_SCORE)->count();
        $suppressed  = $findings->whereIn('status', ['dismissed'])->count();
        $ruleIds     = $findings->pluck('rule_id')->unique()->count();
        $totalRules  = self::DOMAIN_SHADOW_RULE_COUNTS[$run->domain] ?? 1;

        $severityDist = $findings->groupBy('severity')
            ->map(fn($g) => $g->count())
            ->toArray();

        $snapshot = ShadowSoakEvidenceSnapshot::create([
            'snapshot_id'              => 'sse-' . Str::uuid(),
            'run_id'                   => $run->run_id,
            'domain'                   => $run->domain,
            'total_findings'           => $total,
            'high_confidence_findings' => $highConf,
            'high_confidence_rate'     => $total > 0 ? round($highConf / $total, 4) : 0.0,
            'suppressed_findings'      => $suppressed,
            'suppression_rate'         => $total > 0 ? round($suppressed / $total, 4) : 0.0,
            'rules_covered'            => $ruleIds,
            'rule_coverage_rate'       => $totalRules > 0 ? round($ruleIds / $totalRules, 4) : 0.0,
            'severity_distribution'    => $severityDist,
            'tenant_id'                => $run->tenant_id,
        ]);

        $this->audit($run->run_id, $run->domain, 'evidence_computed', null, [
            'total_findings'       => $total,
            'high_confidence_rate' => $snapshot->high_confidence_rate,
            'suppression_rate'     => $snapshot->suppression_rate,
            'rule_coverage_rate'   => $snapshot->rule_coverage_rate,
        ]);

        return $snapshot;
    }

    public function checkGates(ShadowSoakRun $run, ShadowSoakEvidenceSnapshot $snapshot): array
    {
        $checks = [];

        $checks[] = $this->recordGate($run, 'min_findings',
            $snapshot->total_findings, self::MIN_FINDINGS_FOR_SOAK, '>=',
            "Need at least " . self::MIN_FINDINGS_FOR_SOAK . " findings for meaningful evidence");

        $checks[] = $this->recordGate($run, 'high_confidence_rate',
            $snapshot->high_confidence_rate, self::MIN_HIGH_CONFIDENCE_RATE, '>=',
            "At least " . (self::MIN_HIGH_CONFIDENCE_RATE * 100) . "% of findings must be high confidence");

        $checks[] = $this->recordGate($run, 'suppression_rate',
            $snapshot->suppression_rate, self::MAX_SUPPRESSION_RATE, '<=',
            "Suppression rate must not exceed " . (self::MAX_SUPPRESSION_RATE * 100) . "%");

        return $checks;
    }

    public function buildAssessment(ShadowSoakRun $run, array $gateChecks): ShadowSoakDomainAssessment
    {
        $checked = count($gateChecks);
        $passed  = count(array_filter($gateChecks, fn($g) => $g->result === 'pass'));
        $failed  = $checked - $passed;
        $passRate = $checked > 0 ? round($passed / $checked, 4) : 0.0;
        $evidencePass = $passRate >= self::PROMOTION_EVIDENCE_PASS_RATE;

        $note = $evidencePass
            ? "Evidence gates PASS ({$passed}/{$checked}). A domain-specific 6h soak is still required before promotion."
            : "Evidence gates FAIL ({$passed}/{$checked}). Not enough quality evidence for promotion consideration.";

        $assessment = ShadowSoakDomainAssessment::create([
            'assessment_id'         => 'ssa-' . Str::uuid(),
            'run_id'                => $run->run_id,
            'domain'                => $run->domain,
            'gates_checked'         => $checked,
            'gates_passed'          => $passed,
            'gates_failed'          => $failed,
            'pass_rate'             => $passRate,
            'evidence_pass'         => $evidencePass,
            'promotion_recommended' => false,   // NEVER auto-recommend promotion
            'recommendation_note'   => $note,
            'tenant_id'             => $run->tenant_id,
        ]);

        $this->audit($run->run_id, $run->domain, 'assessment_recorded', null, [
            'gates_passed'  => $passed,
            'gates_checked' => $checked,
            'evidence_pass' => $evidencePass,
        ]);

        return $assessment;
    }

    public function recordFindingSummaries(ShadowSoakRun $run): array
    {
        $since = now()->subHours($run->window_hours);

        $query = AdvisoryFinding::where('domain', $run->domain)
            ->where('last_seen_at', '>=', $since)
            ->select('rule_id', 'confidence', 'severity', 'status');

        if ($run->tenant_id) {
            $query->where('tenant_id', $run->tenant_id);
        }

        $byRule = $query->get()->groupBy('rule_id');

        $summaries = [];
        foreach ($byRule as $ruleId => $group) {
            $summaries[] = ShadowSoakFindingSummary::create([
                'summary_id'            => 'ssfs-' . Str::uuid(),
                'run_id'                => $run->run_id,
                'domain'                => $run->domain,
                'rule_id'               => $ruleId,
                'finding_count'         => $group->count(),
                'high_confidence_count' => $group->where('confidence', '>=', self::MIN_HIGH_CONFIDENCE_SCORE)->count(),
                'avg_confidence'        => round($group->avg('confidence') ?? 0.0, 4),
                'max_severity'          => $group->sortByDesc(fn($f) => match($f->severity) {
                    'critical' => 4, 'high' => 3, 'medium' => 2, default => 1
                })->first()?->severity,
                'has_suppressed'        => $group->whereIn('status', ['dismissed'])->isNotEmpty(),
                'tenant_id'             => $run->tenant_id,
            ]);
        }

        return $summaries;
    }

    public function recordConfidenceBands(ShadowSoakRun $run, ShadowSoakEvidenceSnapshot $snapshot): ShadowSoakConfidenceBand
    {
        $since = now()->subHours($run->window_hours);

        $query = AdvisoryFinding::where('domain', $run->domain)
            ->where('last_seen_at', '>=', $since);

        if ($run->tenant_id) {
            $query->where('tenant_id', $run->tenant_id);
        }

        $findings = $query->pluck('confidence');
        $total    = $findings->count();

        $low      = $findings->filter(fn($c) => $c < 0.50)->count();
        $medium   = $findings->filter(fn($c) => $c >= 0.50 && $c < 0.65)->count();
        $high     = $findings->filter(fn($c) => $c >= 0.65 && $c < 0.80)->count();
        $critical = $findings->filter(fn($c) => $c >= 0.80)->count();

        return ShadowSoakConfidenceBand::create([
            'band_id'       => 'sscb-' . Str::uuid(),
            'run_id'        => $run->run_id,
            'domain'        => $run->domain,
            'band_low'      => $low,
            'band_medium'   => $medium,
            'band_high'     => $high,
            'band_critical' => $critical,
            'high_plus_rate'=> $total > 0 ? round(($high + $critical) / $total, 4) : 0.0,
            'tenant_id'     => $run->tenant_id,
        ]);
    }

    public function recordSuppressionStats(ShadowSoakRun $run, ShadowSoakEvidenceSnapshot $snapshot): ShadowSoakSuppressionStat
    {
        return ShadowSoakSuppressionStat::create([
            'stat_id'          => 'ssss-' . Str::uuid(),
            'run_id'           => $run->run_id,
            'domain'           => $run->domain,
            'total_findings'   => $snapshot->total_findings,
            'suppressed_count' => $snapshot->suppressed_findings,
            'suppression_rate' => $snapshot->suppression_rate,
            'within_threshold' => $snapshot->suppression_rate <= self::MAX_SUPPRESSION_RATE,
            'tenant_id'        => $run->tenant_id,
        ]);
    }

    public function recordCoverageStats(ShadowSoakRun $run, ShadowSoakEvidenceSnapshot $snapshot): ShadowSoakCoverageStat
    {
        $totalRules = self::DOMAIN_SHADOW_RULE_COUNTS[$run->domain] ?? 0;

        $since = now()->subHours($run->window_hours);
        $coveredRuleIds = AdvisoryFinding::where('domain', $run->domain)
            ->where('last_seen_at', '>=', $since)
            ->when($run->tenant_id, fn($q) => $q->where('tenant_id', $run->tenant_id))
            ->distinct('rule_id')
            ->pluck('rule_id')
            ->toArray();

        $allExpected     = array_keys(array_fill(0, $totalRules, 1));
        $withoutFindings = array_values(array_diff($allExpected, $coveredRuleIds));

        return ShadowSoakCoverageStat::create([
            'stat_id'               => 'sscs-' . Str::uuid(),
            'run_id'                => $run->run_id,
            'domain'                => $run->domain,
            'total_shadow_rules'    => $totalRules,
            'rules_with_findings'   => count($coveredRuleIds),
            'coverage_rate'         => $snapshot->rule_coverage_rate,
            'rules_without_findings'=> $withoutFindings,
            'tenant_id'             => $run->tenant_id,
        ]);
    }

    public function completeSoakRun(ShadowSoakRun $run, ShadowSoakDomainAssessment $assessment): ShadowSoakRun
    {
        $run->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'evidence_pass'=> $assessment->evidence_pass,
            'gate_summary' => [
                'gates_checked' => $assessment->gates_checked,
                'gates_passed'  => $assessment->gates_passed,
                'pass_rate'     => $assessment->pass_rate,
            ],
        ]);

        $this->audit($run->run_id, $run->domain, 'run_completed', null, [
            'evidence_pass' => $assessment->evidence_pass,
        ]);

        return $run->fresh();
    }

    public function failSoakRun(ShadowSoakRun $run, string $reason): ShadowSoakRun
    {
        $run->update([
            'status'         => 'failed',
            'completed_at'   => now(),
            'failure_reason' => $reason,
        ]);

        $this->audit($run->run_id, $run->domain, 'run_failed', null, [
            'reason' => $reason,
        ]);

        return $run->fresh();
    }

    public function getSummary(string $domain = null, ?string $tenantId = null): array
    {
        $q = ShadowSoakRun::query();
        if ($domain) {
            $q->where('domain', $domain);
        }
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        $runs = $q->get();

        return [
            'total'          => $runs->count(),
            'completed'      => $runs->where('status', 'completed')->count(),
            'failed'         => $runs->where('status', 'failed')->count(),
            'running'        => $runs->where('status', 'running')->count(),
            'evidence_pass'  => $runs->where('evidence_pass', true)->count(),
            'by_domain'      => $runs->groupBy('domain')->map(fn($g) => $g->count())->toArray(),
        ];
    }

    public function getRuns(array $filters = [], int $perPage = 25)
    {
        $q = ShadowSoakRun::query()->orderByDesc('started_at');

        if (!empty($filters['domain'])) {
            $q->where('domain', $filters['domain']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (!empty($filters['tenant_id'])) {
            $q->where('tenant_id', $filters['tenant_id']);
        }

        return $q->paginate($perPage);
    }

    public function getRunDetail(string $runId): array
    {
        $run        = ShadowSoakRun::where('run_id', $runId)->firstOrFail();
        $snapshot   = ShadowSoakEvidenceSnapshot::where('run_id', $runId)->first();
        $gates      = ShadowSoakGateCheck::where('run_id', $runId)->get();
        $assessment = ShadowSoakDomainAssessment::where('run_id', $runId)->first();
        $audit      = ShadowSoakAuditEvent::where('run_id', $runId)->orderByDesc('created_at')->get();

        return compact('run', 'snapshot', 'gates', 'assessment', 'audit');
    }

    /**
     * ENTERPRISE-072 — Pre-flight status for a shadow domain soak.
     * Advisory read-only. Never starts a run or modifies state.
     */
    public function getPreflightStatus(string $domain): array
    {
        $this->assertDomain($domain);

        $checks = [];

        // CHK-01: domain is supported
        $checks['CHK-01'] = [
            'name'   => 'Domain is supported by soak harness',
            'status' => 'PASS',
            'detail' => "'{$domain}' is in SUPPORTED_DOMAINS",
        ];

        // CHK-02: shadow rules registered for domain
        $expectedRules = self::DOMAIN_SHADOW_RULE_COUNTS[$domain] ?? 0;
        $checks['CHK-02'] = [
            'name'   => 'Shadow rules registered for domain',
            'status' => $expectedRules > 0 ? 'PASS' : 'WARN',
            'detail' => "Expected rule count from registry: {$expectedRules}",
        ];

        // CHK-03: advisory findings in last 24h
        $recentFindings = \App\Models\AdvisoryFinding::where('domain', $domain)
            ->where('last_seen_at', '>=', now()->subHours(24))
            ->count();
        $findingsOk = $recentFindings >= self::MIN_FINDINGS_FOR_SOAK;
        $checks['CHK-03'] = [
            'name'   => 'Advisory findings in last 24h (minimum evidence)',
            'status' => $findingsOk ? 'PASS' : 'WARN',
            'detail' => "{$recentFindings} findings in last 24h (minimum: " . self::MIN_FINDINGS_FOR_SOAK . ')',
        ];

        // CHK-04: no currently running soak for this domain
        $activeRun = ShadowSoakRun::where('domain', $domain)
            ->where('status', 'running')
            ->exists();
        $checks['CHK-04'] = [
            'name'   => 'No active soak run in progress',
            'status' => $activeRun ? 'WARN' : 'PASS',
            'detail' => $activeRun
                ? 'A soak run is already in progress for this domain — wait for it to complete'
                : 'No active run for this domain',
        ];

        // CHK-05: promotion_recommended is always false (safety check)
        $checks['CHK-05'] = [
            'name'   => 'Promotion-recommended always false (advisory posture)',
            'status' => 'PASS',
            'detail' => 'promotion_recommended=false enforced — operator explicit action required for promotion',
        ];

        $passed = count(array_filter($checks, fn ($c) => $c['status'] === 'PASS'));
        $ready  = !$activeRun && $recentFindings >= self::MIN_FINDINGS_FOR_SOAK;

        return [
            'domain'               => $domain,
            'preflight_ready'      => $ready,
            'checks'               => $checks,
            'note'                 => 'Advisory only — soak harness never promotes domains autonomously.',
            'expected_shadow_rules'=> $expectedRules,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function assertDomain(string $domain): void
    {
        if (!in_array($domain, self::SUPPORTED_DOMAINS, true)) {
            throw new \InvalidArgumentException(
                "Domain '{$domain}' is not supported. Supported: " . implode(', ', self::SUPPORTED_DOMAINS)
            );
        }
    }

    private function recordGate(
        ShadowSoakRun $run,
        string $gateName,
        float  $actualValue,
        float  $threshold,
        string $comparison,
        string $note = ''
    ): ShadowSoakGateCheck {
        $pass = match ($comparison) {
            '>='  => $actualValue >= $threshold,
            '<='  => $actualValue <= $threshold,
            '>'   => $actualValue > $threshold,
            '<'   => $actualValue < $threshold,
            '='   => abs($actualValue - $threshold) < PHP_FLOAT_EPSILON,
            default => false,
        };

        $check = ShadowSoakGateCheck::create([
            'check_id'        => 'ssgc-' . Str::uuid(),
            'run_id'          => $run->run_id,
            'domain'          => $run->domain,
            'gate_name'       => $gateName,
            'result'          => $pass ? 'pass' : 'fail',
            'actual_value'    => $actualValue,
            'threshold_value' => $threshold,
            'comparison'      => $comparison,
            'note'            => $note,
            'tenant_id'       => $run->tenant_id,
        ]);

        $this->audit($run->run_id, $run->domain, 'gate_checked', null, [
            'gate'   => $gateName,
            'result' => $pass ? 'pass' : 'fail',
            'actual' => $actualValue,
        ]);

        return $check;
    }

    private function audit(string $runId, string $domain, string $eventType, ?string $actor, array $payload = []): void
    {
        ShadowSoakAuditEvent::create([
            'event_id'   => 'ssae-' . Str::uuid(),
            'run_id'     => $runId,
            'domain'     => $domain,
            'event_type' => $eventType,
            'actor'      => $actor,
            'payload'    => $payload,
        ]);
    }
}
