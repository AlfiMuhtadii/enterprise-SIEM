<?php

namespace Tests\Feature;

use App\Services\SecurityRetentionArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityRetentionArchiveTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir().'/detector_retention_archive_test_'.Str::uuid();
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

    private function seedAlert(string $tenantId, ?\Illuminate\Support\Carbon $detectedAt = null): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => $detectedAt ?? now()->subDays(100),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $tenantId,
            'score' => 0.9,
            'evidence' => json_encode(['probe' => 'archive-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedEvent(?\Illuminate\Support\Carbon $ts = null): void
    {
        DB::table('security_events')->insert([
            'ts' => $ts ?? now()->subDays(60),
            'event_type' => 'probe',
            'event_id' => 'evt-'.uniqid('', true),
            'event_hash' => hash('sha256', uniqid('', true)),
            'payload' => json_encode(['probe' => true]),
        ]);
    }

    private function readGzipLines(string $path): array
    {
        $raw = file_get_contents($path);
        $decoded = gzdecode($raw);
        $lines = array_filter(explode("\n", trim($decoded)));

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }

    public function test_archive_writes_gzip_jsonl_before_deleting_tenant_scoped_rows(): void
    {
        $this->seedAlert('t1');
        $service = new SecurityRetentionArchiveService($this->archiveDir);

        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);

        $files = glob("{$this->archiveDir}/security_alerts/t1/*.jsonl.gz");
        $this->assertCount(1, $files);
        $rows = $this->readGzipLines($files[0]);
        $this->assertCount(1, $rows);
        $this->assertSame('t1', $rows[0]['tenant_id']);
        $this->assertSame('TEST', $rows[0]['alert_type']);
    }

    public function test_archive_supports_tables_with_no_tenant_column(): void
    {
        $this->seedEvent();
        $service = new SecurityRetentionArchiveService($this->archiveDir);

        $deleted = $service->archiveAndDelete('security_events', 'ts', now(), null, hasTenantColumn: false);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('security_events', ['event_type' => 'probe']);

        $files = glob("{$this->archiveDir}/security_events/global/*.jsonl.gz");
        $this->assertCount(1, $files);
    }

    public function test_archive_skips_write_when_nothing_matches(): void
    {
        $service = new SecurityRetentionArchiveService($this->archiveDir);

        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now()->subYears(5), 't1');

        $this->assertSame(0, $deleted);
        $this->assertFalse(is_dir("{$this->archiveDir}/security_alerts"));
    }

    public function test_archive_does_not_touch_rows_outside_tenant_scope(): void
    {
        $this->seedAlert('tenant-a');
        $this->seedAlert('tenant-b');
        $service = new SecurityRetentionArchiveService($this->archiveDir);

        $service->archiveAndDelete('security_alerts', 'detected_at', now(), 'tenant-a');

        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 'tenant-a']);
        $this->assertDatabaseHas('security_alerts', ['tenant_id' => 'tenant-b']);
    }

    public function test_retention_command_archives_before_deleting_by_default(): void
    {
        $this->seedAlert('t1');

        $this->artisan('security:retention', ['--alerts-days' => 90, '--archive-dir' => $this->archiveDir])
            ->assertSuccessful();

        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
        $files = glob("{$this->archiveDir}/security_alerts/t1/*.jsonl.gz");
        $this->assertCount(1, $files);
    }

    public function test_retention_command_no_archive_flag_skips_archiving(): void
    {
        $this->seedAlert('t1');

        $this->artisan('security:retention', ['--alerts-days' => 90, '--no-archive' => true, '--archive-dir' => $this->archiveDir])
            ->assertSuccessful();

        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
        $this->assertFalse(is_dir($this->archiveDir));
    }
}
