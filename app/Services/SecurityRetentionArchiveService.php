<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DATA-TIERING (phase 1): archive-before-delete for SecurityRetentionCommand.
 * Writes rows about to be pruned to a gzip-compressed JSONL file before
 * deleting them, so retention no longer means "gone forever" — a durable
 * local archive exists first. Full hot/warm/cold tiering (ClickHouse,
 * object storage) remains a separate, larger effort; this is the bounded
 * "never delete without archiving" step that doesn't need any new infra.
 */
class SecurityRetentionArchiveService
{
    public const CHUNK_SIZE = 500;

    public function __construct(private readonly string $archiveDir)
    {
    }

    /**
     * Archives every row matching the given cutoff (and optional tenant
     * scope) to a gzip JSONL file, then deletes exactly those rows. Returns
     * the number of rows deleted. Archiving happens BEFORE deletion — if the
     * archive write fails, nothing is deleted.
     *
     * $hasTenantColumn distinguishes "this table has no tenant_id column at
     * all" (e.g. security_events — no filter applied regardless of
     * $tenantId) from "tenant_id IS NULL" (legacy/untagged rows in tables
     * that do have the column, when $tenantId is null).
     */
    public function archiveAndDelete(string $table, string $column, Carbon $cutoff, ?string $tenantId, bool $hasTenantColumn = true): int
    {
        $query = DB::table($table)->where($column, '<', $cutoff);
        if ($hasTenantColumn) {
            $query = $tenantId !== null ? $query->where('tenant_id', $tenantId) : $query->whereNull('tenant_id');
        }

        if ((clone $query)->count() === 0) {
            return 0;
        }

        $path = $this->archivePath($table, $hasTenantColumn ? $tenantId : 'global');
        $this->ensureDirectoryExists(dirname($path));

        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new RuntimeException("Failed to open archive file for writing: {$path}");
        }

        try {
            (clone $query)->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    $line = json_encode((array) $row, JSON_UNESCAPED_SLASHES);
                    if ($line === false || gzwrite($handle, $line."\n") === false) {
                        throw new RuntimeException('Failed to write archive record.');
                    }
                }
            });
        } finally {
            gzclose($handle);
        }

        return $query->delete();
    }

    private function archivePath(string $table, ?string $tenantId): string
    {
        $scope = $tenantId !== null ? preg_replace('/[^A-Za-z0-9_-]/', '_', $tenantId) : 'legacy';
        // Full-precision timestamp (not just date) — a unique filename per run
        // avoids any dependency on gzip append semantics; multiple archive
        // files per day for the same table/tenant is expected and fine.
        $stamp = now()->format('Y-m-d_His_u');

        return "{$this->archiveDir}/{$table}/{$scope}/{$stamp}.jsonl.gz";
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create archive directory: {$dir}");
        }
    }
}
