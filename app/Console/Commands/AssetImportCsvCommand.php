<?php

namespace App\Console\Commands;

use App\Services\AssetContextService;
use Illuminate\Console\Command;

class AssetImportCsvCommand extends Command
{
    protected $signature = 'asset:import {tenant} {path} {--by=cli}';
    protected $description = 'Import an asset inventory CSV (hostname,ip_address,owner,environment,asset_type,external_id) for a tenant.';

    public function handle(AssetContextService $service): int
    {
        $tenant = (string) $this->argument('tenant');
        $path = (string) $this->argument('path');
        if (!is_file($path)) {
            $this->error("file not found: {$path}");
            return self::FAILURE;
        }

        $result = $service->importCsv($tenant, $path, (string) $this->option('by'));

        $this->info("imported={$result['imported']} skipped={$result['skipped']}");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
