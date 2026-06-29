<?php

namespace App\Console\Commands;

use App\Services\SecurityHardeningEvidenceFreezeService;
use Illuminate\Console\Command;

class SecurityHardeningFreezeCommand extends Command
{
    protected $signature = 'security:hardening-freeze
                            {--output= : Optional JSON output path for the freeze report}';

    protected $description = 'Run a consolidated security hardening evidence freeze (advisory-only, read-only).';

    public function handle(SecurityHardeningEvidenceFreezeService $svc): int
    {
        $this->info('[ENTERPRISE-074] Security Hardening Evidence Freeze — advisory-only');
        $this->info('No enforcement changes will be made.');

        $run = $svc->runFreeze('artisan:security:hardening-freeze');

        $this->info("Freeze run started: {$run->run_id}");
        $this->info('Evaluating ' . count(SecurityHardeningEvidenceFreezeService::CONTROL_IDS) . ' controls...');

        $passed = 0;
        $failed = 0;
        foreach (SecurityHardeningEvidenceFreezeService::CONTROL_IDS as $controlId) {
            $check = $svc->evaluateControl($controlId, $run);
            $icon  = $check->passed ? '✓' : '✗';
            $this->line("  [{$icon}] {$controlId}: {$check->result}");
            $check->passed ? $passed++ : $failed++;
        }

        $completed = $svc->completeRun($run, $passed, $failed);
        $coverage  = $svc->computeCoverage($completed);

        $this->info('');
        $this->info("Controls:  {$passed} passed / {$failed} failed / " . count(SecurityHardeningEvidenceFreezeService::CONTROL_IDS) . ' total');
        $this->info(sprintf('Coverage:  %.1f%%', $coverage->overall_score * 100));
        $this->info('Threshold: ' . ($coverage->meets_pass_threshold ? 'PASS' : 'BELOW ' . (SecurityHardeningEvidenceFreezeService::MIN_PASS_SCORE * 100) . '%'));

        if ($output = $this->option('output')) {
            $report = [
                'run_id'          => $completed->run_id,
                'freeze_version'  => $completed->freeze_version,
                'advisory_only'   => true,
                'controls_passed' => $passed,
                'controls_failed' => $failed,
                'controls_total'  => count(SecurityHardeningEvidenceFreezeService::CONTROL_IDS),
                'coverage_score'  => $coverage->overall_score,
                'meets_threshold' => $coverage->meets_pass_threshold,
                'completed_at'    => $completed->completed_at?->toIso8601String(),
            ];
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$output}");
        }

        return self::SUCCESS;
    }
}
