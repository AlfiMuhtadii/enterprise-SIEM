<?php

namespace App\Services;

use App\Models\RuleEvidenceBatchPlan;
use App\Models\RuleFixtureBacklog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-050: Empirical Rule Evidence & Replay Fixture Plan
 *
 * Governs fixture/evidence debt across 133 rules.
 * Does NOT create fixtures — only tracks and batches the backlog.
 * ADVISORY_ONLY = true; PLAN_APPROVED = false always.
 */
class RuleEvidenceGovernanceService
{
    public const ADVISORY_ONLY  = true;
    public const PLAN_APPROVED  = false;

    // tier_1: staged_active rules missing replay fixture (active and unvalidated)
    // tier_2: high-priority shadow (soaked domains or high confidence ≥ 0.72)
    // tier_3: everything else
    public const TIER_1 = 'tier_1_immediate';
    public const TIER_2 = 'tier_2_next_batch';
    public const TIER_3 = 'tier_3_deferred';

    // Effort estimates (days per rule per tier)
    private const EFFORT_DAYS_PER_RULE = [
        self::TIER_1 => 1,
        self::TIER_2 => 2,
        self::TIER_3 => 3,
    ];

    private const SOAKED_DOMAINS = ['identity', 'cloud', 'saas'];
    private const HIGH_CONF_MIN  = 0.72;

    private string $registryPath;

    public function __construct()
    {
        $this->registryPath = base_path('docs/detection/rules/registry.v1.json');
    }

    // ── Public helpers (tested directly) ─────────────────────────────────────

    public function classifyTier(array $rule): string
    {
        if (($rule['status'] ?? '') === 'staged_active') {
            return self::TIER_1;
        }
        $domain     = $rule['domain'] ?? '';
        $confidence = (float) ($rule['confidence'] ?? 0);
        if (in_array($domain, self::SOAKED_DOMAINS, true) || $confidence >= self::HIGH_CONF_MIN) {
            return self::TIER_2;
        }
        return self::TIER_3;
    }

    public function deriveConfidenceSource(array $rule): string
    {
        $hasFixture   = !empty($rule['replay_fixture']);
        $hasEvidence  = !empty($rule['validation_evidence']);
        if ($hasFixture && $hasEvidence) {
            return 'empirical';
        }
        if ($hasFixture) {
            return 'fixture_tested';
        }
        return 'manual';
    }

    // ── Main operations ───────────────────────────────────────────────────────

    public function inventoryRules(bool $dryRun = false): Collection
    {
        $rules = $this->loadRegistry();
        $now   = now()->format('Y-m-d H:i:sP');

        $results = $rules->map(function (array $rule) use ($dryRun, $now): array {
            $tier            = $this->classifyTier($rule);
            $confidenceSource= $this->deriveConfidenceSource($rule);
            $hasFixture      = !empty($rule['replay_fixture']);
            $hasEvidence     = !empty($rule['validation_evidence']);

            $row = [
                'rule_id'                => $rule['rule_id'],
                'domain'                 => $rule['domain'] ?? 'unknown',
                'title'                  => $rule['title'] ?? $rule['rule_id'],
                'status'                 => $rule['status'] ?? 'shadow',
                'confidence'             => (float) ($rule['confidence'] ?? 0.0),
                'confidence_source'      => $confidenceSource,
                'has_replay_fixture'     => $hasFixture,
                'has_validation_evidence'=> $hasEvidence,
                'fixture_path'           => $rule['replay_fixture'] ?? null,
                'priority_tier'          => $tier,
                'is_advisory'            => true,
                'last_inventoried_at'    => $now,
            ];

            if (!$dryRun) {
                RuleFixtureBacklog::updateOrCreate(
                    ['rule_id' => $row['rule_id']],
                    $row
                );
            }

            return $row;
        });

        return $results;
    }

    public function generateBatchPlan(bool $dryRun = false): array
    {
        $rules    = $this->loadRegistry();
        $batches  = [];
        $batchId  = (string) Str::uuid();

        // Group by domain + tier
        $grouped = $rules->groupBy(function (array $rule): string {
            return ($rule['domain'] ?? 'unknown') . '|' . $this->classifyTier($rule);
        });

        $batchRecords = [];
        foreach ($grouped as $key => $group) {
            [$domain, $tier] = explode('|', $key, 2);
            $ruleIds          = $group->pluck('rule_id')->all();
            $missingFixture   = $group->filter(fn(array $r) => empty($r['replay_fixture']))->count();
            $missingEvidence  = $group->filter(fn(array $r) => empty($r['validation_evidence']))->count();
            $effortDays       = $missingFixture * (self::EFFORT_DAYS_PER_RULE[$tier] ?? 3);

            $record = [
                'batch_id'              => (string) Str::uuid(),
                'domain'                => $domain,
                'priority_tier'         => $tier,
                'rules_count'           => $group->count(),
                'missing_fixture_count' => $missingFixture,
                'missing_evidence_count'=> $missingEvidence,
                'estimated_effort_days' => $effortDays,
                'rule_ids'              => $ruleIds,
                'plan_approved'         => false,
                'is_advisory'           => true,
            ];

            $batchRecords[] = $record;

            if (!$dryRun) {
                RuleEvidenceBatchPlan::create($record);
            }
        }

        // Sort: tier_1 first, then tier_2, then tier_3
        usort($batchRecords, function (array $a, array $b): int {
            return strcmp($a['priority_tier'], $b['priority_tier']);
        });

        $totals = [
            'total_rules'            => $rules->count(),
            'rules_with_fixture'     => $rules->filter(fn(array $r) => !empty($r['replay_fixture']))->count(),
            'rules_with_evidence'    => $rules->filter(fn(array $r) => !empty($r['validation_evidence']))->count(),
            'rules_missing_fixture'  => $rules->filter(fn(array $r) => empty($r['replay_fixture']))->count(),
            'tier_1_count'           => $rules->filter(fn(array $r) => $this->classifyTier($r) === self::TIER_1)->count(),
            'tier_2_count'           => $rules->filter(fn(array $r) => $this->classifyTier($r) === self::TIER_2)->count(),
            'tier_3_count'           => $rules->filter(fn(array $r) => $this->classifyTier($r) === self::TIER_3)->count(),
            'plan_approved'          => false,
            'is_advisory'            => true,
        ];

        return [
            'summary' => $totals,
            'batches' => $batchRecords,
        ];
    }

    public function getBacklogSummary(): array
    {
        $total          = RuleFixtureBacklog::count();
        $hasFixture     = RuleFixtureBacklog::where('has_replay_fixture', true)->count();
        $hasEvidence    = RuleFixtureBacklog::where('has_validation_evidence', true)->count();
        $missingFixture = RuleFixtureBacklog::where('has_replay_fixture', false)->count();
        $tier1          = RuleFixtureBacklog::where('priority_tier', self::TIER_1)->count();
        $tier2          = RuleFixtureBacklog::where('priority_tier', self::TIER_2)->count();
        $tier3          = RuleFixtureBacklog::where('priority_tier', self::TIER_3)->count();

        return [
            'total'           => $total,
            'has_fixture'     => $hasFixture,
            'has_evidence'    => $hasEvidence,
            'missing_fixture' => $missingFixture,
            'tier_1_count'    => $tier1,
            'tier_2_count'    => $tier2,
            'tier_3_count'    => $tier3,
            'is_advisory'     => true,
            'plan_approved'   => false,
        ];
    }

    public function getBacklog(string $domain = '', string $tier = ''): Collection
    {
        $query = RuleFixtureBacklog::query();
        if ($domain !== '') {
            $query->where('domain', $domain);
        }
        if ($tier !== '') {
            $query->where('priority_tier', $tier);
        }
        return $query->orderByRaw("CASE priority_tier WHEN 'tier_1_immediate' THEN 1 WHEN 'tier_2_next_batch' THEN 2 ELSE 3 END")
                     ->get();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function loadRegistry(): Collection
    {
        $data = json_decode(file_get_contents($this->registryPath), true);
        return collect($data['rules'] ?? []);
    }
}
