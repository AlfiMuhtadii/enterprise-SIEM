<?php

namespace App\Console\Commands;

use App\Services\DomainSoakHarnessService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-072 — Shadow Domain Soak Pre-Flight Check
 *
 * Validates whether a shadow domain is ready to begin a soak run.
 * Advisory only — never starts or modifies a soak run.
 *
 * Usage:
 *   php artisan domain:soak-preflight endpoint
 *   php artisan domain:soak-preflight network --output=reports/soak_preflight.json
 */
class ShadowSoakPreflightCommand extends Command
{
    protected $signature   = 'domain:soak-preflight
                                {domain : Shadow domain to check (endpoint|network|ueba)}
                                {--output= : Optional JSON output file}';
    protected $description = 'Pre-flight readiness check for a shadow domain soak run (advisory, read-only)';

    public function __construct(private readonly DomainSoakHarnessService $harness)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $domain = $this->argument('domain');

        try {
            $report = $this->harness->getPreflightStatus($domain);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('Supported domains: ' . implode(', ', DomainSoakHarnessService::SUPPORTED_DOMAINS));
            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        if ($output = $this->option('output')) {
            @mkdir(dirname($output), 0755, true);
            file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Report written to: {$output}");
        }

        return self::SUCCESS;
    }
}
