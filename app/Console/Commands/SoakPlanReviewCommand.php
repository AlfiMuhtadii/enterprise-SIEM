<?php

namespace App\Console\Commands;

use App\Services\RealDomainSoakPlanService;
use Illuminate\Console\Command;

/** ENTERPRISE-060: soak:plan-review — show 4-phase soak execution plan and pre-soak gate status. */
class SoakPlanReviewCommand extends Command
{
    protected $signature   = 'soak:plan-review
                              {--phase= : Focus on specific phase 1-4}
                              {--dry-run : Evaluate gates without persisting}';
    protected $description = 'Display the real domain soak execution plan and evaluate pre-soak readiness gates.';

    public function __construct(private readonly RealDomainSoakPlanService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $phase  = $this->option('phase') ? (int) $this->option('phase') : null;

        $this->line('ENTERPRISE-060: Real Domain Soak Execution Plan');
        $this->line('ADVISORY_ONLY=true  REAL_EXECUTION_GATED=true');
        $this->line(str_repeat('-', 60));

        $result = $this->service->buildPlan($dryRun);
        $plan   = $result['plan'];
        $phases = $result['phases'];
        $gates  = $result['gates'];

        $this->line("Overall readiness : {$plan['overall_readiness']}");
        $this->line("Gates passed      : {$plan['gates_passed']}/{$plan['total_gates']}");
        $this->line("Phases ready      : {$plan['phases_ready']}/{$plan['phases_total']}");
        $this->line('');

        $targetPhases = $phase ? array_filter($phases, fn ($p) => $p['phase_number'] === $phase) : $phases;
        foreach ($targetPhases as $p) {
            $this->line("Phase {$p['phase_number']}: {$p['phase_name']}");
            $this->line("  scope        : {$p['rule_scope']}");
            $this->line("  readiness    : {$p['readiness_status']}  ({$p['gates_passed']}/{$p['gates_total']} gates)");
            $this->line("  promo_gated  : true (real soak required before promotion)");

            $phaseGates = array_filter($gates, fn ($g) => $g['phase_number'] === $p['phase_number']);
            foreach ($phaseGates as $g) {
                $mark = match($g['status']) { 'pass' => '[PASS]', 'fail' => '[FAIL]', default => '[WARN]' };
                $this->line("  {$mark} {$g['gate_id']}: {$g['gate_name']}");
                $this->line("         {$g['evidence']}");
            }
            $this->line('');
        }

        if (!$dryRun) {
            $this->info('Plan persisted to soak_plan_runs.');
        } else {
            $this->warn('Dry-run mode: plan not persisted.');
        }
        $this->warn('Actual soak execution: .\\scripts\\run_xdr_correlation_soak_6h.ps1');

        return self::SUCCESS;
    }
}
