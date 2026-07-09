<?php

namespace App\Console\Commands;

use App\Services\ArchiveSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * DATA-TIERING (phase 2): analyst-facing CLI search over the gzip JSONL
 * retention archive. Read-only, advisory — never mutates the archive or
 * the live database.
 */
class SecurityArchiveSearchCommand extends Command
{
    protected $signature = 'security:archive-search
                            {table : Archived table name (e.g. security_alerts)}
                            {--tenant= : Restrict to one tenant_id scope (omit for all tenants)}
                            {--from= : Lower bound (any format Carbon::parse accepts)}
                            {--to= : Upper bound (any format Carbon::parse accepts)}
                            {--filter=* : Exact-match filter as field=value, repeatable}
                            {--limit=100 : Max results (capped at '.ArchiveSearchService::MAX_RESULTS.')}
                            {--archive-dir=storage/app/archives : Base directory for gzip JSONL archives}';

    protected $description = 'Search the archived (pre-deletion) gzip JSONL retention records for a table';

    public function handle(): int
    {
        $service = new ArchiveSearchService($this->option('archive-dir'));

        $filters = [];
        foreach ((array) $this->option('filter') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $filters[$parts[0]] = $parts[1];
            }
        }

        $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;

        $result = $service->search(
            table: (string) $this->argument('table'),
            tenantId: $this->option('tenant'),
            from: $from,
            to: $to,
            filters: $filters,
            limit: (int) $this->option('limit'),
        );

        $this->line(json_encode($result['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info(sprintf(
            'files_scanned=%d rows_scanned=%d results=%d truncated=%s',
            $result['files_scanned'],
            $result['rows_scanned'],
            $result['result_count'],
            $result['truncated'] ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}
