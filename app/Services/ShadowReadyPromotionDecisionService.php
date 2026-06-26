<?php

namespace App\Services;

use App\Models\ShadowPromotionDecision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-047: Shadow Ready Domain Soak / Promotion Decision
 *
 * Evaluates the 12 shadow_ready rules (identity/cloud/saas domain, status=shadow)
 * against evidence signals: confidence threshold, DLQ error count, advisory findings.
 *
 * IMPORTANT CONSTRAINTS:
 * - promotion_approved is ALWAYS false — no rule is promoted.
 * - ACTIVE_ALLOWLIST in xdr_rule_registry_validate.py stays empty.
 * - Go correlation scope stays at identity/cloud/SaaS.
 * - This service is advisory-only; decisions are informational evidence only.
 * - Decisions are persisted to shadow_promotion_decisions (append-only).
 */
class ShadowReadyPromotionDecisionService
{
    public const ADVISORY_ONLY         = true;
    public const PROMOTION_APPROVED    = false;

    // Confidence threshold for promote_eligible: rule signal is strong enough
    // that a domain 6h soak PASS would justify promotion request review.
    public const PROMOTE_ELIGIBLE_THRESHOLD = 0.78;

    // Confidence threshold for keep_shadow: rule is functioning but needs
    // more validation data before a soak is worthwhile.
    public const KEEP_SHADOW_THRESHOLD = 0.65;

    // DLQ errors allowed for promote_eligible: zero tolerance.
    // Any DLQ errors in the domain during the evaluation window → keep_shadow at best.
    public const MAX_DLQ_FOR_ELIGIBLE = 0;

    public const DECISION_PROMOTE_ELIGIBLE = 'promote_eligible';
    public const DECISION_KEEP_SHADOW      = 'keep_shadow';
    public const DECISION_DEFER            = 'defer';

    private const SHADOW_READY_DOMAINS = ['identity', 'cloud', 'saas'];

    public function evaluate(string $domain = '', bool $dryRun = false): Collection
    {
        $rules       = $this->loadShadowReadyRules($domain);
        $runId       = (string) Str::uuid();
        $dlqByDomain = $this->getDlqErrorsByDomain();
        $findingsByDomain = $this->getAdvisoryFindingsByDomain();

        $results = $rules->map(function (array $rule) use ($dlqByDomain, $findingsByDomain, $runId): array {
            $ruleId    = $rule['rule_id'];
            $ruleDomain = $rule['domain'];
            $confidence = (float) ($rule['confidence'] ?? 0.0);

            $dlqErrors       = $dlqByDomain[$ruleDomain] ?? 0;
            $findingsCount   = $findingsByDomain[$ruleDomain] ?? 0;
            $fpRisk          = $this->classifyFalsePositiveRisk($confidence);
            $decision        = $this->makeDecision($confidence, $dlqErrors);
            $evidenceBasis   = [
                'confidence'              => $confidence,
                'dlq_errors_in_domain'    => $dlqErrors,
                'advisory_findings_count' => $findingsCount,
                'false_positive_risk'     => $fpRisk,
                'promote_eligible_threshold' => self::PROMOTE_ELIGIBLE_THRESHOLD,
                'keep_shadow_threshold'      => self::KEEP_SHADOW_THRESHOLD,
                'evaluation_note'         => $this->buildEvaluationNote($decision, $confidence, $dlqErrors),
            ];

            return [
                'decision_run_id'         => $runId,
                'rule_id'                 => $ruleId,
                'domain'                  => $ruleDomain,
                'current_status'          => $rule['status'] ?? 'shadow',
                'confidence'              => $confidence,
                'decision'                => $decision,
                'false_positive_risk'     => $fpRisk,
                'dlq_errors_in_domain'    => $dlqErrors,
                'advisory_findings_count' => $findingsCount,
                'evidence_basis'          => $evidenceBasis,
                'promotion_approved'      => false,
                'is_advisory'             => true,
                'evaluated_at'            => now()->format('Y-m-d H:i:sP'),
            ];
        });

        if (!$dryRun) {
            $this->persist($results);
        }

        return $results;
    }

    public function getSummary(Collection $results): array
    {
        return [
            'total'             => $results->count(),
            'promote_eligible'  => $results->where('decision', self::DECISION_PROMOTE_ELIGIBLE)->count(),
            'keep_shadow'       => $results->where('decision', self::DECISION_KEEP_SHADOW)->count(),
            'defer'             => $results->where('decision', self::DECISION_DEFER)->count(),
            'promotion_approved'=> false,
            'advisory_only'     => true,
        ];
    }

    public function getLatestRunResults(string $domain = ''): Collection
    {
        $latestRun = ShadowPromotionDecision::query()
            ->when($domain !== '', fn ($q) => $q->where('domain', $domain))
            ->orderByDesc('evaluated_at')
            ->value('decision_run_id');

        if (!$latestRun) {
            return collect();
        }

        return collect(
            ShadowPromotionDecision::where('decision_run_id', $latestRun)->get()->toArray()
        );
    }

    public function computeDecision(float $confidence, int $dlqErrors): string
    {
        return $this->makeDecision($confidence, $dlqErrors);
    }

    public function computeFalsePositiveRisk(float $confidence): string
    {
        return $this->classifyFalsePositiveRisk($confidence);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function makeDecision(float $confidence, int $dlqErrors): string
    {
        if ($confidence >= self::PROMOTE_ELIGIBLE_THRESHOLD && $dlqErrors <= self::MAX_DLQ_FOR_ELIGIBLE) {
            return self::DECISION_PROMOTE_ELIGIBLE;
        }

        if ($confidence >= self::KEEP_SHADOW_THRESHOLD) {
            return self::DECISION_KEEP_SHADOW;
        }

        return self::DECISION_DEFER;
    }

    private function classifyFalsePositiveRisk(float $confidence): string
    {
        if ($confidence >= self::PROMOTE_ELIGIBLE_THRESHOLD) {
            return 'low';
        }
        if ($confidence >= self::KEEP_SHADOW_THRESHOLD) {
            return 'medium';
        }
        return 'high';
    }

    private function buildEvaluationNote(string $decision, float $confidence, int $dlqErrors): string
    {
        return match ($decision) {
            self::DECISION_PROMOTE_ELIGIBLE =>
                "Confidence {$confidence} >= threshold " . self::PROMOTE_ELIGIBLE_THRESHOLD . " and DLQ errors = {$dlqErrors}. " .
                "Advisory eligible for soak scheduling. Requires domain 6h soak PASS + ACTIVE_ALLOWLIST update by detection-engineering.",
            self::DECISION_KEEP_SHADOW =>
                "Confidence {$confidence} is below promote_eligible threshold " . self::PROMOTE_ELIGIBLE_THRESHOLD .
                " or DLQ errors ({$dlqErrors}) > 0. Keep in shadow; continue accumulating evidence.",
            self::DECISION_DEFER =>
                "Confidence {$confidence} < keep_shadow threshold " . self::KEEP_SHADOW_THRESHOLD . ". " .
                "Insufficient signal. Defer until rule is tuned and re-evaluated.",
            default => 'Unknown decision.',
        };
    }

    private function loadShadowReadyRules(string $domainFilter = ''): Collection
    {
        $service = app(DetectionPromotionReadinessService::class);
        $rules   = $service->getRulesForReadiness(DetectionPromotionReadinessService::READINESS_SHADOW_READY);

        if ($domainFilter !== '') {
            $rules = $rules->filter(fn ($r) => $r['domain'] === $domainFilter)->values();
        }

        return $rules;
    }

    private function getDlqErrorsByDomain(): array
    {
        if (!$this->tableExists('dlq_records')) {
            return [];
        }

        // source_domain column only exists if the pipeline services populate it.
        // Fall back to 0 (no DLQ signal) if the column is absent — this means
        // confidence threshold alone drives the decision, which is the safe default.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('dlq_records', 'source_domain')) {
            return [];
        }

        $rows = DB::table('dlq_records')
            ->selectRaw('source_domain, COUNT(*) as error_count')
            ->whereIn('source_domain', self::SHADOW_READY_DOMAINS)
            ->whereIn('status', ['failed', 'pending'])
            ->groupBy('source_domain')
            ->get();

        return $rows->pluck('error_count', 'source_domain')->toArray();
    }

    private function getAdvisoryFindingsByDomain(): array
    {
        if (!$this->tableExists('advisory_findings')) {
            return [];
        }

        $rows = DB::table('advisory_findings')
            ->selectRaw('domain, COUNT(*) as finding_count')
            ->whereIn('domain', self::SHADOW_READY_DOMAINS)
            ->groupBy('domain')
            ->get();

        return $rows->pluck('finding_count', 'domain')->toArray();
    }

    private function tableExists(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    }

    private function persist(Collection $results): void
    {
        foreach ($results as $row) {
            $row['evidence_basis'] = json_encode($row['evidence_basis']);
            ShadowPromotionDecision::create($row);
        }
    }
}
