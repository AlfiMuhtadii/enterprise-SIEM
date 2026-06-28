<?php

namespace App\Console\Commands;

use App\Services\TenantStrictModeReadinessService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-065 — Strict Mode Readiness Gate
 *
 * Usage:
 *   php artisan tenant:strict-mode-readiness
 *   php artisan tenant:strict-mode-readiness --output=reports/strict_mode_readiness.json
 */
class StrictModeReadinessCommand extends Command
{
    protected $signature = 'tenant:strict-mode-readiness {--output= : Optional path to write JSON report}';

    protected $description = 'Evaluate strict mode readiness gates for XDR_TENANT_STRICT_MODE=true';

    public function __construct(private readonly TenantStrictModeReadinessService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan>Tenant Strict Mode Readiness Assessment (ENTERPRISE-065)</>');
        $this->line('Advisory only — never enables strict mode autonomously.');
        $this->line(str_repeat('-', 70));

        $result = $this->service->assess('cli');
        $summary = $result['summary'];

        foreach ($result['gate_results'] as $gateId => $gate) {
            $icon = match ($gate['result']) {
                'PASS'  => '<fg=green>✓</>',
                'WARN'  => '<fg=yellow>⚠</>',
                default => '<fg=red>✗</>',
            };
            $this->line("  {$icon} [{$gateId}] {$gate['gate_name']}");
            $this->line("       {$gate['detail']}");
        }

        $this->line('');
        $this->line(str_repeat('-', 70));
        $score   = number_format($summary['readiness_score'] * 100, 1);
        $threshold = number_format(TenantStrictModeReadinessService::PASS_THRESHOLD * 100, 1);
        $status  = $summary['overall_status'];

        $statusColor = match ($status) {
            'READY'     => 'green',
            'WARN'      => 'yellow',
            default     => 'red',
        };

        $this->line("  Score   : {$score}% (threshold: {$threshold}%)");
        $this->line("  Status  : <fg={$statusColor}>{$status}</>");
        $this->line("  Recommended : " . ($summary['strict_mode_recommended'] ? 'YES' : 'NO'));

        if (!empty($summary['required_gates_failed'])) {
            $this->warn('Required gates failed: ' . implode(', ', $summary['required_gates_failed']));
        }
        $this->line('');

        if ($path = $this->option('output')) {
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$path}");
        }

        return $summary['overall_status'] === 'READY' ? self::SUCCESS : self::FAILURE;
    }
}
