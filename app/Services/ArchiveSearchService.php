<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * DATA-TIERING (phase 2): a real, bounded read path over the phase-1
 * gzip JSONL retention archive (SecurityRetentionArchiveService), so
 * archived data is actually searchable instead of a write-only safety net.
 *
 * Deliberately NOT a warm-tier query engine: no index, linear scan of
 * gzip files, bounded by MAX_FILES_SCANNED / MAX_ROWS_SCANNED / MAX_RESULTS.
 * A real warm tier (ClickHouse, months-scale, indexed) remains a separate,
 * larger effort requiring live infra this pass does not have.
 */
class ArchiveSearchService
{
    public const MAX_FILES_SCANNED = 200;

    public const MAX_ROWS_SCANNED = 200000;

    public const MAX_RESULTS = 500;

    public function __construct(private readonly string $archiveDir)
    {
    }

    /**
     * Search archived rows for a table, optionally scoped by tenant and a
     * closed [$from, $to] date range (matched against the archive filename
     * timestamp, so files entirely outside the range are skipped without
     * being opened), applying an exact-match filter map against decoded
     * JSONL fields. Bounded: stops at whichever of files/rows/results limit
     * is hit first and reports that via `truncated`.
     */
    public function search(
        string $table,
        ?string $tenantId,
        ?Carbon $from,
        ?Carbon $to,
        array $filters = [],
        int $limit = 100,
    ): array {
        $limit = min(max($limit, 1), self::MAX_RESULTS);
        $files = $this->listArchiveFiles($table, $tenantId, $from, $to);

        $results = [];
        $filesScanned = 0;
        $rowsScanned = 0;
        $truncated = false;

        foreach ($files as $file) {
            if ($filesScanned >= self::MAX_FILES_SCANNED) {
                $truncated = true;
                break;
            }
            $filesScanned++;

            $handle = @gzopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                while (!gzeof($handle)) {
                    if ($rowsScanned >= self::MAX_ROWS_SCANNED || count($results) >= $limit) {
                        $truncated = true;
                        break 2;
                    }
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    $rowsScanned++;
                    $row = json_decode($trimmed, true);
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($this->matchesFilters($row, $filters)) {
                        $results[] = $row;
                    }
                }
            } finally {
                gzclose($handle);
            }
        }

        return [
            'results' => $results,
            'files_scanned' => $filesScanned,
            'rows_scanned' => $rowsScanned,
            'result_count' => count($results),
            'truncated' => $truncated,
            'is_local_archive_search' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function listArchiveFiles(string $table, ?string $tenantId, ?Carbon $from, ?Carbon $to): array
    {
        $tableDir = "{$this->archiveDir}/{$table}";
        if (!is_dir($tableDir)) {
            return [];
        }

        if ($tenantId !== null) {
            $scope = preg_replace('/[^A-Za-z0-9_-]/', '_', $tenantId);
            $scopeDirs = [$tableDir.'/'.$scope];
        } else {
            $scopeDirs = array_filter(glob($tableDir.'/*') ?: [], 'is_dir');
        }

        $files = [];
        foreach ($scopeDirs as $dir) {
            foreach (glob($dir.'/*.jsonl.gz') ?: [] as $file) {
                $stamp = $this->stampFromFilename($file);
                if ($from !== null && $stamp !== null && $stamp->lt($from)) {
                    continue;
                }
                if ($to !== null && $stamp !== null && $stamp->gt($to)) {
                    continue;
                }
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    private function stampFromFilename(string $file): ?Carbon
    {
        $basename = basename($file, '.jsonl.gz');
        try {
            return Carbon::createFromFormat('Y-m-d_His_u', $basename);
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchesFilters(array $row, array $filters): bool
    {
        foreach ($filters as $field => $value) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            if ((string) $row[$field] !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
