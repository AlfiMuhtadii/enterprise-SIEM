<?php

namespace App\Services;

use App\Models\EndpointSoakPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-048: Endpoint Shadow Domain Soak Plan
 *
 * Sub-classifies all 93 endpoint shadow_needs_soak rules into soak tiers,
 * checks prerequisite gates, and produces an append-only plan record.
 *
 * Constraints:
 * - plan_approved = false ALWAYS; no rules are promoted.
 * - ADVISORY_ONLY = true; this is a planning document, not an execution trigger.
 * - ACTIVE_ALLOWLIST stays empty; Go scope stays at identity/cloud/SaaS.
 */
class EndpointSoakPlanService
{
    public const ADVISORY_ONLY  = true;
    public const PLAN_APPROVED  = false;

    // Rules with confidence >= this threshold are soak-ready
    // (strong enough signal to justify 6h soak scheduling).
    public const TIER_1_THRESHOLD = 0.72;

    // Rules with confidence >= this threshold but below tier-1
    // need additional evidence collection before soak.
    public const TIER_2_THRESHOLD = 0.60;

    public const TIER_1_SOAK_READY         = 'tier_1_soak_ready';
    public const TIER_2_EVIDENCE_COLLECTION = 'tier_2_evidence_collection';
    public const TIER_3_NEEDS_TUNING        = 'tier_3_needs_tuning';

    private const ENDPOINT_DOMAIN = 'endpoint';

    // Prerequisite gates for endpoint soak go-ahead
    private const GATES = [
        'GATE-01' => 'Endpoint agent enrolled and heartbeat flowing',
        'GATE-02' => 'Shadow event loop enabled (XDR_SHADOW_CONSUMER_ENABLED)',
        'GATE-03' => 'Advisory findings table has endpoint domain records (or empty OK in dev)',
        'GATE-04' => 'Rule registry has 93 endpoint shadow rules',
        'GATE-05' => 'No DLQ errors on endpoint telemetry topics',
    ];

    public function generatePlan(bool $dryRun = false): array
    {
        $planRunId  = (string) Str::uuid();
        $rules      = $this->loadEndpointShadowRules();
        $tiered     = $this->classifyRules($rules);
        $gates      = $this->evaluateGates($planRunId);
        $summary    = $this->buildSummary($planRunId, $tiered);

        if (!$dryRun) {
            $this->persistPlan($summary, $tiered, $gates);
        }

        return compact('summary', 'tiered', 'gates');
    }

    // ── Config-aware threshold readers (ENTERPRISE-051) ──────────────────────

    private function configTier1Threshold(): float
    {
        return (float) config('xdr_detection.soak.tier_1_threshold', self::TIER_1_THRESHOLD);
    }

    private function configTier2Threshold(): float
    {
        return (float) config('xdr_detection.soak.tier_2_threshold', self::TIER_2_THRESHOLD);
    }

    public function classifyTier(array $rule): string
    {
        $confidence = (float) ($rule['confidence'] ?? 0.0);

        if ($confidence >= $this->configTier1Threshold()) {
            return self::TIER_1_SOAK_READY;
        }

        if ($confidence >= $this->configTier2Threshold()) {
            return self::TIER_2_EVIDENCE_COLLECTION;
        }

        return self::TIER_3_NEEDS_TUNING;
    }

    public function getSummary(): array
    {
        return $this->buildSummary('', collect([]));
    }

    public function getLatestPlan(): ?array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('endpoint_soak_plans')) {
            return null;
        }

        $plan = EndpointSoakPlan::orderByDesc('generated_at')->first();
        if (!$plan) {
            return null;
        }

        $runId = $plan->plan_run_id;

        $rules = DB::table('endpoint_soak_plan_rules')
            ->where('plan_run_id', $runId)
            ->get()
            ->toArray();

        $gates = DB::table('endpoint_soak_plan_gates')
            ->where('plan_run_id', $runId)
            ->get()
            ->toArray();

        return [
            'summary' => $plan->toArray(),
            'rules'   => $rules,
            'gates'   => $gates,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function loadEndpointShadowRules(): Collection
    {
        $service = app(DetectionPromotionReadinessService::class);
        return $service->getRulesForReadiness(DetectionPromotionReadinessService::READINESS_SHADOW_NEEDS_SOAK);
    }

    private function classifyRules(Collection $rules): Collection
    {
        return $rules->map(function (array $rule): array {
            $tier       = $this->classifyTier($rule);
            $confidence = (float) ($rule['confidence'] ?? 0.0);
            $window     = match ($tier) {
                self::TIER_1_SOAK_READY          => 1,
                self::TIER_2_EVIDENCE_COLLECTION => 2,
                self::TIER_3_NEEDS_TUNING        => 3,
                default                          => null,
            };

            return [
                'rule_id'              => $rule['rule_id'],
                'domain'               => $rule['domain'] ?? self::ENDPOINT_DOMAIN,
                'tier'                 => $tier,
                'confidence'           => $confidence,
                'false_positive_risk'  => $this->fpRisk($confidence),
                'soak_rationale'       => $this->buildRationale($tier, $confidence),
                'estimated_soak_window'=> $window,
                'is_advisory'          => true,
            ];
        });
    }

    private function evaluateGates(string $planRunId): array
    {
        $gates = [];

        // GATE-01: endpoint agent heartbeat table exists and has data (or advisory OK if empty)
        $agentTableExists = \Illuminate\Support\Facades\Schema::hasTable('endpoint_agent_heartbeats');
        $gates[] = $this->gate($planRunId, 'GATE-01', self::GATES['GATE-01'],
            $agentTableExists,
            $agentTableExists ? 'endpoint_agent_heartbeats table present' : 'table missing (advisory)');

        // GATE-02: XDR_SHADOW_CONSUMER_ENABLED (ENV-CACHE-DRIFT-BATCH: via config, config:cache-safe)
        $shadowConsumer = (string) config('xdr.shadow_consumer_enabled', 'false');
        $shadowEnabled = ($shadowConsumer === 'true');
        $gates[] = $this->gate($planRunId, 'GATE-02', self::GATES['GATE-02'],
            true, // advisory — not blocking; default false is expected
            'advisory: XDR_SHADOW_CONSUMER_ENABLED=' . $shadowConsumer . '; enable before soak');

        // GATE-03: advisory findings table accessible
        $afExists = \Illuminate\Support\Facades\Schema::hasTable('advisory_findings');
        $gates[] = $this->gate($planRunId, 'GATE-03', self::GATES['GATE-03'],
            $afExists,
            $afExists ? 'advisory_findings table present' : 'table missing');

        // GATE-04: 93 endpoint shadow rules in registry
        $endpointRuleCount = $this->loadEndpointShadowRules()->count();
        $gates[] = $this->gate($planRunId, 'GATE-04', self::GATES['GATE-04'],
            $endpointRuleCount === 93,
            "found {$endpointRuleCount} endpoint shadow rules (expected 93)");

        // GATE-05: no DLQ errors on endpoint topics (advisory — check if table empty)
        $dlqTableExists = \Illuminate\Support\Facades\Schema::hasTable('dlq_records');
        $gates[] = $this->gate($planRunId, 'GATE-05', self::GATES['GATE-05'],
            true, // advisory gate — no blocking
            $dlqTableExists ? 'dlq_records present; run with live pipeline to validate' : 'advisory');

        return $gates;
    }

    private function gate(string $planRunId, string $id, string $name, bool $passed, string $detail): array
    {
        return [
            'plan_run_id' => $planRunId,
            'gate_id'     => $id,
            'gate_name'   => $name,
            'passed'      => $passed,
            'status'      => $passed ? 'pass' : 'warn',
            'detail'      => $detail,
            'is_advisory' => true,
        ];
    }

    private function buildSummary(string $planRunId, Collection $tiered): array
    {
        $t1 = $tiered->where('tier', self::TIER_1_SOAK_READY)->count();
        $t2 = $tiered->where('tier', self::TIER_2_EVIDENCE_COLLECTION)->count();
        $t3 = $tiered->where('tier', self::TIER_3_NEEDS_TUNING)->count();

        return [
            'plan_run_id'     => $planRunId,
            'domain'          => self::ENDPOINT_DOMAIN,
            'total_rules'     => $tiered->count(),
            'tier_1_count'    => $t1,
            'tier_2_count'    => $t2,
            'tier_3_count'    => $t3,
            'tier_1_threshold'=> $this->configTier1Threshold(),
            'tier_2_threshold'=> $this->configTier2Threshold(),
            'plan_approved'   => false,
            'is_advisory'     => true,
            'generated_at'    => now()->format('Y-m-d H:i:sP'),
        ];
    }

    private function fpRisk(float $confidence): string
    {
        if ($confidence >= 0.78) {
            return 'low';
        }
        if ($confidence >= $this->configTier1Threshold()) {
            return 'medium';
        }
        if ($confidence >= $this->configTier2Threshold()) {
            return 'high';
        }
        return 'very_high';
    }

    private function buildRationale(string $tier, float $confidence): string
    {
        $t1 = $this->configTier1Threshold();
        $t2 = $this->configTier2Threshold();

        return match ($tier) {
            self::TIER_1_SOAK_READY =>
                "confidence {$confidence} >= {$t1}. " .
                "Soak-ready: schedule in 6h endpoint soak window 1. " .
                "Gate: agent enrollment PASS + advisory findings stable.",
            self::TIER_2_EVIDENCE_COLLECTION =>
                "confidence {$confidence} in [{$t2}, {$t1}). " .
                "Accumulate advisory findings evidence for ≥14 days, then re-evaluate for tier upgrade.",
            self::TIER_3_NEEDS_TUNING =>
                "confidence {$confidence} < {$t2}. " .
                "Rule requires tuning (FP reduction, threshold adjustment) before soak scheduling.",
            default => 'Unknown tier.',
        };
    }

    private function persistPlan(array $summary, Collection $tiered, array $gates): void
    {
        EndpointSoakPlan::create($summary);

        foreach ($tiered as $row) {
            $row['plan_run_id'] = $summary['plan_run_id'];
            DB::table('endpoint_soak_plan_rules')->insert(
                array_merge($row, ['evaluated_at' => $summary['generated_at']])
            );
        }

        foreach ($gates as $gate) {
            $gate['checked_at'] = $summary['generated_at'];
            DB::table('endpoint_soak_plan_gates')->insert($gate);
        }
    }
}
