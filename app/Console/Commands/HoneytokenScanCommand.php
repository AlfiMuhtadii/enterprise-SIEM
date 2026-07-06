<?php

namespace App\Console\Commands;

use App\Services\HoneytokenService;
use Illuminate\Console\Command;

/**
 * CAP-DECEPTION-HONEYTOKEN: scans recent security_alerts evidence for any
 * seeded honeytoken value. Purely detective — writes append-only hit
 * records only, no active response.
 */
class HoneytokenScanCommand extends Command
{
    protected $signature = 'honeytoken:scan';
    protected $description = 'Scan recent alert evidence for touched honeytokens (advisory, no active response)';

    public function handle(HoneytokenService $service): int
    {
        $hits = $service->scanForHits();
        $this->info("Honeytoken scan complete: {$hits} match(es) found.");

        return self::SUCCESS;
    }
}
