<?php

namespace App\Console\Commands;

use App\Services\SigmaImportService;
use Illuminate\Console\Command;

/**
 * CAP-DETECT-AS-CODE-SIGMA: imports one or more SigmaHQ rule YAML files into
 * docs/detection/rules/registry.v1.json as shadow-only catalog entries.
 *
 * Always writes status=shadow — never staged_active (that requires a
 * domain-specific 6h soak PASS, which an import can't satisfy). Run
 * `python scripts/xdr_rule_registry_validate.py` after importing to confirm
 * the updated registry still passes all 21 checks before committing.
 */
class DetectionImportSigmaCommand extends Command
{
    protected $signature = 'detection:import-sigma
                            {files* : Path(s) to Sigma rule YAML files}
                            {--dry-run : Compile and report without writing to the registry}
                            {--registry= : Override the registry path (default: docs/detection/rules/registry.v1.json)}';

    protected $description = 'Compile SigmaHQ YAML rule(s) into shadow-only registry.v1.json entries';

    public function handle(SigmaImportService $sigma): int
    {
        $registryPath = $this->option('registry') ?: base_path('docs/detection/rules/registry.v1.json');
        $registry = json_decode(file_get_contents($registryPath), true);
        if (!is_array($registry) || !isset($registry['rules'])) {
            $this->error("Failed to parse registry at {$registryPath}");
            return self::FAILURE;
        }

        $existingIds = array_column($registry['rules'], 'rule_id');
        $compiled = [];
        $hadError = false;

        foreach ($this->argument('files') as $file) {
            if (!is_file($file)) {
                $this->error("File not found: {$file}");
                $hadError = true;
                continue;
            }

            try {
                $parsed = $sigma->parse(file_get_contents($file));
                $entry = $sigma->compile($parsed, array_merge($existingIds, array_column($compiled, 'rule_id')));
                $compiled[] = $entry;
                $this->info("Compiled {$file} -> rule_id={$entry['rule_id']} domain={$entry['domain']} severity={$entry['severity']}");
            } catch (\Throwable $e) {
                $this->error("Failed to compile {$file}: {$e->getMessage()}");
                $hadError = true;
            }
        }

        if ($hadError) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written. '.count($compiled).' rule(s) would be added.');
            return self::SUCCESS;
        }

        $registry['rules'] = array_merge($registry['rules'], $compiled);
        $registry['generated_at'] = now()->toIso8601String();
        file_put_contents(
            $registryPath,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->info(count($compiled)." rule(s) added to {$registryPath}.");
        $this->warn('Run: python scripts/xdr_rule_registry_validate.py to confirm the updated registry still passes all checks before committing.');

        return self::SUCCESS;
    }
}
