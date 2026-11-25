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
    protected $description = 'Review advisory diagnostic readiness without scheduling or verifying a live soak.';

    public function __construct(private readonly RealDomainSoakPlanService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $phase  = $this->option('phase') ? (int) $this->option('phase') : null;

        $this->line('ENTERPRISE-060: Domain Soak Readiness');
        $this->line('ADVISORY_ONLY=true  REAL_EXECUTION_GATED=true');
        $this->line(str_repeat('-', 60));

        $result = $this->service->buildPlan($dryRun);
        $plan   = $result['plan'];
        $phases = $result['phases'];
        $gates  = $result['gates'];
        $definitions = $this->service->getPhaseDefinitions();

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
            $this->line('  diagnostic   : ' . $definitions[$p['phase_number']]['diagnostic_command']);

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
        $this->warn('Diagnostic readiness is not authorization to schedule a live soak or promote rules.');

        return self::SUCCESS;
    }
}
