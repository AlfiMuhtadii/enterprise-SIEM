<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DATA-TIERING (phase 1): archive-before-delete for SecurityRetentionCommand.
 * Writes rows about to be pruned to a gzip-compressed JSONL file before
 * deleting them, so retention no longer means "gone forever" — a durable
 * local archive exists first.
 *
 * Warm tier (added once real infra became available): when
 * xdr.infrastructure.clickhouse.warm_tier_enabled is true (default false —
 * zero behavior change), the same rows are ALSO written to ClickHouse's
 * archived_records table via ClickHouseArchiveWriter, alongside the gzip
 * file, not instead of it — the gzip archive remains the durability
 * guarantee; ClickHouse is an additional, real, indexed warm-tier search
 * path (see ArchiveSearchService for the read side).
 *
 * Cold tier (added once real infra became available): when
 * xdr.infrastructure.cold_tier.enabled is true (default false — zero
 * behavior change), the finished local gzip file is ALSO uploaded to an
 * S3-compatible object store via ColdArchiveWriter, once writing completes
 * — best-effort, alongside the local file and the warm tier if enabled, not
 * instead of either. The local gzip archive remains the durability
 * guarantee regardless of cold-tier state.
 */
class SecurityRetentionArchiveService
{
    public const CHUNK_SIZE = 500;

    private readonly ?ClickHouseArchiveWriter $warmTier;

    private readonly ?ColdArchiveWriter $coldTier;

    public function __construct(private readonly string $archiveDir, ?ClickHouseArchiveWriter $warmTier = null, ?ColdArchiveWriter $coldTier = null)
    {
        $this->warmTier = config('xdr.infrastructure.clickhouse.warm_tier_enabled', false)
            ? ($warmTier ?? new ClickHouseArchiveWriter())
            : null;
        $this->coldTier = config('xdr.infrastructure.cold_tier.enabled', false)
            ? ($coldTier ?? new ColdArchiveWriter())
            : null;
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
            (clone $query)->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($handle, $table, $column, $tenantId, $hasTenantColumn) {
                $warmTierRows = [];
                foreach ($rows as $row) {
                    $rowArray = (array) $row;
                    $line = json_encode($rowArray, JSON_UNESCAPED_SLASHES);
                    if ($line === false || gzwrite($handle, $line."\n") === false) {
                        throw new RuntimeException('Failed to write archive record.');
                    }
                    if ($this->warmTier !== null) {
                        $warmTierRows[] = $this->warmTier->mapArchivedRow(
                            $table,
                            $rowArray,
                            $hasTenantColumn ? $tenantId : null,
                            (string) $rowArray[$column],
                        );
                    }
                }
                // Best-effort: a warm-tier write failure must never block
                // deletion — the gzip archive above is the durability
                // guarantee this whole class exists to provide; ClickHouse
                // insert() already logs its own failures internally.
                if ($this->warmTier !== null && $warmTierRows !== []) {
                    $this->warmTier->insert($warmTierRows);
                }
            });
        } finally {
            gzclose($handle);
        }

        // Best-effort, same as the warm tier above: a cold-tier upload
        // failure must never block deletion — it runs only after the local
        // gzip file is fully written and closed.
        $this->coldTier?->upload($path, $table, $hasTenantColumn ? $tenantId : null);

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
