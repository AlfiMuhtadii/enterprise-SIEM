<?php

namespace App\Console\Commands;

use App\Services\DomainSoakSimulationService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-057: Domain Soak Simulation
 *
 * Usage:
 *   php artisan domain:soak-simulate
 *   php artisan domain:soak-simulate --domain=endpoint
 *   php artisan domain:soak-simulate --dry-run
 */
class RunDomainSoakSimulationCommand extends Command
{
    protected $signature = 'domain:soak-simulate
                            {--domain= : Run simulation for a specific domain (endpoint|network|threat-intel)}
                            {--dry-run : Evaluate without persisting to DB}';

    protected $description = 'Run offline domain soak simulation — E057; advisory-only; promotion_recommended=false always';

    public function handle(DomainSoakSimulationService $service): int
    {
        $domain = (string) $this->option('domain');
        $dryRun = (bool)   $this->option('dry-run');
        $mode   = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[SIMULATE]</>';

        $this->line('');
        $this->line("{$mode} Domain Soak Simulation" . ($domain ? " — domain={$domain}" : ' — all domains'));
        $this->line('  ADVISORY-ONLY. PROMOTION_RECOMMENDED = false ALWAYS.');
        $this->line('  Real 6h soak required before any promotion.');
        $this->line('');

        if ($domain !== '') {
            $results = [$domain => $service->simulate($domain, $dryRun)];
        } else {
            $results = $service->simulateAll($dryRun);
        }

        foreach ($results as $dom => $data) {
            if (isset($data['error'])) {
                $this->error("[{$dom}] {$data['error']}");
                continue;
            }
            $sim   = $data['simulation'];
            $gates = $data['gates'];

            $this->line("── Domain: {$dom} ────────────────────────────────────");
            $this->table(
                ['Gate', 'Status', 'Evidence'],
                array_map(fn ($g) => [$g['gate_id'], strtoupper($g['status']), $g['evidence']], $gates),
            );
            $this->line("  rules_total          : {$sim['rules_total']}");
            $this->line("  structural_match_rate: {$sim['structural_match_rate']}");
            $this->line("  fp_estimate_rate     : {$sim['fp_estimate_rate']}");
            $this->line("  soak_verdict         : {$sim['soak_verdict']}");
            $this->line('  promotion_recommended: false (always)');
            $this->line('  real_soak_required   : true (always)');
            $this->line('');
        }

        return self::SUCCESS;
    }
}
