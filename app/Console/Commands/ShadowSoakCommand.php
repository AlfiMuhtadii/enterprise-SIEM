<?php

namespace App\Console\Commands;

use App\Services\DomainSoakHarnessService;
use Illuminate\Console\Command;

/**
 * Shadow domain soak harness runner.
 *
 * Advisory-only — records promotion evidence for shadow endpoint/network/UEBA findings.
 * NEVER promotes rules. NEVER modifies ACTIVE_ALLOWLIST. NEVER creates alerts/incidents.
 *
 * Usage:
 *   php artisan xdr:shadow-soak --domain=endpoint --hours=24
 *   php artisan xdr:shadow-soak --domain=network --hours=6 --dry-run
 *   php artisan xdr:shadow-soak --list
 */
class ShadowSoakCommand extends Command
{
    protected $signature = 'xdr:shadow-soak
        {--domain= : Shadow domain to soak (endpoint|network|ueba)}
        {--hours=24 : Soak window in hours (1–168)}
        {--dry-run : Show what would run without writing evidence}
        {--list : List recent soak runs}';

    protected $description = 'Run a shadow domain soak and record promotion evidence (advisory-only)';

    public function handle(DomainSoakHarnessService $service): int
    {
        if ($this->option('list')) {
            return $this->listRuns($service);
        }

        $domain  = $this->option('domain');
        $hours   = (int) $this->option('hours');
        $dryRun  = (bool) $this->option('dry-run');

        if (!$domain) {
            $this->error('--domain is required. Supported: ' . implode(', ', DomainSoakHarnessService::SUPPORTED_DOMAINS));
            return 1;
        }

        if (!in_array($domain, DomainSoakHarnessService::SUPPORTED_DOMAINS, true)) {
            $this->error("Unsupported domain '{$domain}'. Supported: " . implode(', ', DomainSoakHarnessService::SUPPORTED_DOMAINS));
            return 1;
        }

        $this->line("Starting shadow soak: domain={$domain}  hours={$hours}" . ($dryRun ? '  [DRY RUN]' : ''));
        $this->line("Advisory-only — no rules promoted, no alerts created.");

        $run = $service->startSoakRun($domain, $hours, $dryRun, 'cli');

        if ($dryRun) {
            $this->line("[DRY RUN] run_id={$run->run_id}  status=dry_run — no evidence written.");
            return 0;
        }

        try {
            $snapshot   = $service->computeEvidence($run);
            $this->line("Evidence: total_findings={$snapshot->total_findings}  high_conf_rate={$snapshot->high_confidence_rate}  suppression_rate={$snapshot->suppression_rate}");

            $gates      = $service->checkGates($run, $snapshot);
            foreach ($gates as $gate) {
                $icon = $gate->result === 'pass' ? '✓' : '✗';
                $this->line("  Gate [{$icon}] {$gate->gate_name}  actual={$gate->actual_value}  threshold={$gate->threshold_value}  ({$gate->comparison})");
            }

            $assessment = $service->buildAssessment($run, $gates);
            $service->recordFindingSummaries($run);
            $service->recordConfidenceBands($run, $snapshot);
            $service->recordSuppressionStats($run, $snapshot);
            $service->recordCoverageStats($run, $snapshot);

            $run = $service->completeSoakRun($run, $assessment);

            $passIcon = $assessment->evidence_pass ? '✓ PASS' : '✗ FAIL';
            $this->line("Assessment: {$passIcon}  gates={$assessment->gates_passed}/{$assessment->gates_checked}");
            $this->line("Note: {$assessment->recommendation_note}");
            $this->line("run_id={$run->run_id}");

            return 0;
        } catch (\Throwable $e) {
            $service->failSoakRun($run, $e->getMessage());
            $this->error("Soak run failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function listRuns(DomainSoakHarnessService $service): int
    {
        $summary = $service->getSummary();
        $this->line("Soak runs — total={$summary['total']}  completed={$summary['completed']}  evidence_pass={$summary['evidence_pass']}");

        $runs = $service->getRuns([], 20);
        foreach ($runs as $run) {
            $pass = $run->evidence_pass ? '[PASS]' : '      ';
            $this->line("  {$pass}  {$run->run_id}  domain={$run->domain}  status={$run->status}  hours={$run->window_hours}  started={$run->started_at}");
        }

        return 0;
    }
}
