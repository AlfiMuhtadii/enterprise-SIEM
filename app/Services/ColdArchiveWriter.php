<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DATA-TIERING (cold tier): best-effort upload of a finished local gzip
 * archive file to an S3-compatible object store, via Laravel's own "s3"
 * filesystem disk (config/filesystems.php already supports a custom
 * `endpoint` + `use_path_style_endpoint` for S3-compatible backends like
 * MinIO -- no hand-rolled request signing here). Never throws: a cold-tier
 * upload failure must not block SecurityRetentionArchiveService's
 * archive-then-delete guarantee, the same convention ClickHouseArchiveWriter
 * (the warm tier) already follows.
 */
class ColdArchiveWriter
{
    private readonly Filesystem $disk;

    public function __construct(?Filesystem $disk = null)
    {
        $this->disk = $disk ?? Storage::disk(config('xdr.infrastructure.cold_tier.disk', 's3'));
    }

    /**
     * Uploads the local archive file at $localPath to the cold tier, under
     * a key mirroring the local archive's own table/tenant/filename layout.
     * Returns true on success, false on any failure (logged, not thrown).
     */
    public function upload(string $localPath, string $table, ?string $tenantId): bool
    {
        $scope = $tenantId !== null ? preg_replace('/[^A-Za-z0-9_-]/', '_', $tenantId) : 'legacy';
        $prefix = trim((string) config('xdr.infrastructure.cold_tier.prefix', 'archive'), '/');
        $key = "{$prefix}/{$table}/{$scope}/".basename($localPath);

        try {
            $stream = fopen($localPath, 'rb');
            if ($stream === false) {
                Log::warning('ColdArchiveWriter: failed to open local archive for upload.', ['path' => $localPath]);

                return false;
            }

            try {
                $ok = $this->disk->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($ok === false) {
                Log::warning('ColdArchiveWriter: cold-tier upload returned failure.', ['key' => $key]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('ColdArchiveWriter: cold-tier upload threw.', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
