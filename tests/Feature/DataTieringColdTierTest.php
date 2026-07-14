<?php

namespace Tests\Feature;

use App\Services\ColdArchiveWriter;
use App\Services\SecurityRetentionArchiveService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DATA-TIERING (cold tier) — best-effort upload of the finished local gzip
 * archive to an S3-compatible object store (config/filesystems.php's "s3"
 * disk, real MinIO locally via docker-compose.yml's "data-tiering" profile
 * in production, `Storage::fake('s3')` here). The local gzip archive stays
 * the durability guarantee regardless — this only ever adds an additional
 * offload target, matching the warm-tier convention in
 * DataTieringWarmTierTest.
 */
class DataTieringColdTierTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir().'/detector_cold_tier_test_'.Str::uuid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archiveDir)) {
            $this->deleteDirectory($this->archiveDir);
        }
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function seedAlert(string $tenantId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => now()->subDays(100),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $tenantId,
            'score' => 0.9,
            'evidence' => json_encode(['probe' => 'cold-tier-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // ColdArchiveWriter
    // -------------------------------------------------------------------------

    public function test_writer_uploads_local_file_to_the_configured_disk(): void
    {
        Storage::fake('s3');
        $local = "{$this->archiveDir}/probe.jsonl.gz";
        mkdir($this->archiveDir, 0755, true);
        file_put_contents($local, gzencode('{"a":1}'."\n"));

        $ok = (new ColdArchiveWriter)->upload($local, 'security_alerts', 't1');

        $this->assertTrue($ok);
        Storage::disk('s3')->assertExists('archive/security_alerts/t1/probe.jsonl.gz');
    }

    public function test_writer_uses_legacy_scope_when_tenant_id_is_null(): void
    {
        Storage::fake('s3');
        $local = "{$this->archiveDir}/probe.jsonl.gz";
        mkdir($this->archiveDir, 0755, true);
        file_put_contents($local, gzencode('{"a":1}'."\n"));

        (new ColdArchiveWriter)->upload($local, 'security_events', null);

        Storage::disk('s3')->assertExists('archive/security_events/legacy/probe.jsonl.gz');
    }

    public function test_writer_respects_configured_prefix(): void
    {
        Config::set('xdr.infrastructure.cold_tier.prefix', 'cold/custom');
        Storage::fake('s3');
        $local = "{$this->archiveDir}/probe.jsonl.gz";
        mkdir($this->archiveDir, 0755, true);
        file_put_contents($local, gzencode('{"a":1}'."\n"));

        (new ColdArchiveWriter)->upload($local, 'security_alerts', 't1');

        Storage::disk('s3')->assertExists('cold/custom/security_alerts/t1/probe.jsonl.gz');
    }

    public function test_writer_returns_false_when_local_file_is_missing(): void
    {
        Storage::fake('s3');

        $ok = (new ColdArchiveWriter)->upload("{$this->archiveDir}/does-not-exist.jsonl.gz", 'security_alerts', 't1');

        $this->assertFalse($ok);
    }

    public function test_writer_returns_false_and_never_throws_when_disk_write_fails(): void
    {
        $local = "{$this->archiveDir}/probe.jsonl.gz";
        mkdir($this->archiveDir, 0755, true);
        file_put_contents($local, gzencode('{"a":1}'."\n"));

        $failingDisk = \Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('writeStream')->andThrow(new \RuntimeException('disk unreachable'));

        $ok = (new ColdArchiveWriter($failingDisk))->upload($local, 'security_alerts', 't1');

        $this->assertFalse($ok);
    }

    // -------------------------------------------------------------------------
    // SecurityRetentionArchiveService — cold-tier write path
    // -------------------------------------------------------------------------

    public function test_archive_uploads_to_cold_tier_when_enabled(): void
    {
        Config::set('xdr.infrastructure.cold_tier.enabled', true);
        Storage::fake('s3');
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        $uploaded = Storage::disk('s3')->allFiles('archive/security_alerts/t1');
        $this->assertCount(1, $uploaded);
        // The local gzip archive must still exist -- cold tier is additive,
        // never a replacement for the durability guarantee.
        $localFiles = glob("{$this->archiveDir}/security_alerts/t1/*.jsonl.gz");
        $this->assertCount(1, $localFiles);
    }

    public function test_archive_does_not_upload_when_cold_tier_disabled(): void
    {
        Config::set('xdr.infrastructure.cold_tier.enabled', false);
        Storage::fake('s3');
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        Storage::disk('s3')->assertDirectoryEmpty('archive');
    }

    public function test_archive_deletion_proceeds_even_when_cold_tier_upload_fails(): void
    {
        Config::set('xdr.infrastructure.cold_tier.enabled', true);
        $this->seedAlert('t1');

        $failingDisk = \Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('writeStream')->andThrow(new \RuntimeException('disk unreachable'));

        $service = new SecurityRetentionArchiveService($this->archiveDir, null, new ColdArchiveWriter($failingDisk));
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        // Best-effort cold tier: an upload failure must never block the
        // gzip-backed deletion this class exists to guarantee.
        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
    }

    public function test_archive_writes_to_both_warm_and_cold_tiers_when_both_enabled(): void
    {
        Config::set('xdr.infrastructure.clickhouse.http_url', 'http://ch.test:8123');
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', true);
        Config::set('xdr.infrastructure.cold_tier.enabled', true);
        \Illuminate\Support\Facades\Http::fake(['ch.test:8123/*' => \Illuminate\Support\Facades\Http::response('', 200)]);
        Storage::fake('s3');
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertCount(1, Storage::disk('s3')->allFiles('archive/security_alerts/t1'));
    }
}
