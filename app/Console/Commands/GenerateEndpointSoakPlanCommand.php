<?php

namespace App\Console\Commands;

use App\Services\EndpointSoakPlanService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-048: Generate endpoint shadow domain soak plan.
 *
 * Usage:
 *   php artisan endpoint:generate-soak-plan
 *   php artisan endpoint:generate-soak-plan --dry-run
 *
 * Produces tier assignments for all 93 endpoint shadow rules and evaluates
 * 5 prerequisite gates. plan_approved = false always; no rules are promoted.
 */
class GenerateEndpointSoakPlanCommand extends Command
{
    protected $signature = 'endpoint:generate-soak-plan
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Generate endpoint shadow domain soak plan — advisory only, no promotion';

    public function handle(EndpointSoakPlanService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $mode = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[GENERATE]</>';
        $this->line('');
        $this->line("{$mode} Endpoint Shadow Domain Soak Plan");
        $this->line('  ADVISORY-ONLY. No rules are promoted. plan_approved = false always.');
        $this->line('');

        $result  = $service->generatePlan($dryRun);
        $summary = $result['summary'];
        $tiered  = $result['tiered'];
        $gates   = $result['gates'];

        // Gate summary
        $this->line('── Prerequisite Gates ───────────────────────────────────');
        foreach ($gates as $gate) {
            $icon = $gate['passed'] ? '<fg=green>PASS</>' : '<fg=yellow>WARN</>';
            $this->line("  [{$icon}] {$gate['gate_id']}: {$gate['gate_name']}");
            $this->line("         {$gate['detail']}");
        }
        $this->line('');

        // Tier summary
        $this->line('── Tier Summary ─────────────────────────────────────────');
        $this->line("  Total endpoint shadow rules : {$summary['total_rules']}");
        $this->line("  Tier 1 (soak_ready ≥{$summary['tier_1_threshold']})       : {$summary['tier_1_count']}  → schedule 6h soak window 1");
        $this->line("  Tier 2 (evidence_collection ≥{$summary['tier_2_threshold']}) : {$summary['tier_2_count']}  → accumulate evidence ≥14 days");
        $this->line("  Tier 3 (needs_tuning <{$summary['tier_2_threshold']})       : {$summary['tier_3_count']}  → rule tuning required");
        $this->line("  plan_approved                : false (always)");
        $this->line('');

        // Top tier-1 rules
        $t1 = $tiered->where('tier', EndpointSoakPlanService::TIER_1_SOAK_READY)->take(10);
        if ($t1->isNotEmpty()) {
            $this->line('── Tier 1 Sample (first 10) ─────────────────────────────');
            $this->table(
                ['Rule ID', 'Confidence', 'FP Risk', 'Soak Window'],
                $t1->map(fn ($r) => [
                    $r['rule_id'],
                    number_format((float) $r['confidence'], 2),
                    $r['false_positive_risk'],
                    $r['estimated_soak_window'] ?? '-',
                ])->all(),
            );
        }

        if (!$dryRun) {
            $this->info("Plan persisted (plan_run_id: {$summary['plan_run_id']}).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
