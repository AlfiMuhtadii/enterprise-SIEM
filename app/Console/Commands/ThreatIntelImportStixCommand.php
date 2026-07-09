<?php

namespace App\Console\Commands;

use App\Services\StixTaxiiImportService;
use Illuminate\Console\Command;

/**
 * CAP-TI-STIX-TAXII — import STIX 2.1 indicators into the IOC store.
 *
 * Offline-first: with no source flags it loads the bundled sample bundle.
 * --file reads a local STIX JSON bundle; --taxii-url polls a TAXII 2.1 collection.
 * Advisory-only enrichment — never triggers response.
 */
class ThreatIntelImportStixCommand extends Command
{
    protected $signature = 'ti:import-stix
        {--file= : Path to a STIX 2.1 JSON bundle}
        {--taxii-url= : TAXII 2.1 collection URL to poll}
        {--user= : TAXII basic-auth user}
        {--pass= : TAXII basic-auth password}
        {--source=stix-taxii : Source label stored on each IOC}
        {--name=STIX/TAXII import : Feed name}';

    protected $description = 'Import STIX 2.1 indicators (bundled file, local file, or TAXII 2.1 poll) into threat_iocs';

    public const BUNDLED_SAMPLE = 'storage/app/threat-intel/sample-stix-bundle.json';

    public function handle(StixTaxiiImportService $service): int
    {
        $taxiiUrl = (string) $this->option('taxii-url');
        $file = (string) $this->option('file');

        if ($taxiiUrl !== '') {
            $this->info("Polling TAXII collection: {$taxiiUrl}");
            $bundle = $service->pollTaxii($taxiiUrl, $this->option('user'), $this->option('pass'));
        } else {
            $path = $file !== '' ? $file : base_path(self::BUNDLED_SAMPLE);
            if (!is_readable($path)) {
                $this->error("STIX bundle not readable: {$path}");
                return self::FAILURE;
            }
            $bundle = json_decode((string) file_get_contents($path), true);
            if (!is_array($bundle)) {
                $this->error("Invalid JSON in STIX bundle: {$path}");
                return self::FAILURE;
            }
            $this->info("Reading STIX bundle: {$path}");
        }

        $result = $service->importBundle($bundle, (string) $this->option('source'), (string) $this->option('name'));

        $this->info("imported={$result['imported']} skipped={$result['skipped']} feed={$result['feed_id']}");

        return self::SUCCESS;
    }
}
