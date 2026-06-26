<?php

namespace App\Console\Commands;

use App\Services\RuleEvidenceGovernanceService;
use Illuminate\Console\Command;

class RuleEvidenceInventoryCommand extends Command
{
    protected $signature = 'rule:evidence-inventory
                            {--generate-plan : Also generate domain+tier batch plans}
                            {--dry-run       : Preview without persisting}';

    protected $description = 'Inventory 133 rules for replay fixture and evidence debt (ENTERPRISE-050)';

    public function handle(RuleEvidenceGovernanceService $service): int
    {
        $dryRun       = (bool) $this->option('dry-run');
        $generatePlan = (bool) $this->option('generate-plan');

        $this->info($dryRun ? '[DRY-RUN] Rule evidence inventory' : 'Running rule evidence inventory');

        $results = $service->inventoryRules($dryRun);

        $tier1 = $results->where('priority_tier', RuleEvidenceGovernanceService::TIER_1)->count();
        $tier2 = $results->where('priority_tier', RuleEvidenceGovernanceService::TIER_2)->count();
        $tier3 = $results->where('priority_tier', RuleEvidenceGovernanceService::TIER_3)->count();
        $missingFixture = $results->where('has_replay_fixture', false)->count();

        $this->line("  Total rules      : {$results->count()}");
        $this->line("  Missing fixture  : {$missingFixture}");
        $this->line("  tier_1_immediate : {$tier1}");
        $this->line("  tier_2_next_batch: {$tier2}");
        $this->line("  tier_3_deferred  : {$tier3}");
        $this->line('  advisory_only    : true | plan_approved: false');

        if ($generatePlan) {
            $plan = $service->generateBatchPlan($dryRun);
            $this->line('');
            $this->info('Batch plan generated:');
            foreach ($plan['batches'] as $batch) {
                $this->line("  [{$batch['priority_tier']}] {$batch['domain']}: {$batch['rules_count']} rules, {$batch['missing_fixture_count']} missing fixture, ~{$batch['estimated_effort_days']}d");
            }
        }

        if (!$dryRun) {
            $this->info('Persisted to rule_fixture_backlogs' . ($generatePlan ? ' + rule_evidence_batch_plans' : '') . '.');
        }

        return 0;
    }
}
