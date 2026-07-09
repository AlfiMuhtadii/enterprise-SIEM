<?php

namespace Tests\Feature;

use App\Services\ArchiveSearchService;
use App\Services\SecurityRetentionArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArchiveSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir().'/detector_archive_search_test_'.Str::uuid();
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

    private function seedAndArchiveAlert(string $tenantId, string $alertType, ?\Illuminate\Support\Carbon $detectedAt = null): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => $detectedAt ?? now()->subDays(100),
            'alert_type' => $alertType,
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $tenantId,
            'score' => 0.9,
            'evidence' => json_encode(['probe' => 'archive-search-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new SecurityRetentionArchiveService($this->archiveDir))
            ->archiveAndDelete('security_alerts', 'detected_at', now(), $tenantId);
    }

    public function test_search_returns_archived_rows_for_table(): void
    {
        $this->seedAndArchiveAlert('t1', 'IDENTITY_MFA_FAILURE_BURST');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 't1', null, null);

        $this->assertSame(1, $result['result_count']);
        $this->assertSame('IDENTITY_MFA_FAILURE_BURST', $result['results'][0]['alert_type']);
        $this->assertTrue($result['is_local_archive_search']);
        $this->assertFalse($result['truncated']);
    }

    public function test_search_applies_exact_match_filters(): void
    {
        $this->seedAndArchiveAlert('t1', 'IDENTITY_MFA_FAILURE_BURST');
        $this->seedAndArchiveAlert('t1', 'CLOUD_MASS_DOWNLOAD');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 't1', null, null, ['alert_type' => 'CLOUD_MASS_DOWNLOAD']);

        $this->assertSame(1, $result['result_count']);
        $this->assertSame('CLOUD_MASS_DOWNLOAD', $result['results'][0]['alert_type']);
    }

    public function test_search_returns_empty_for_unfiled_filter(): void
    {
        $this->seedAndArchiveAlert('t1', 'IDENTITY_MFA_FAILURE_BURST');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 't1', null, null, ['alert_type' => 'NO_SUCH_TYPE']);

        $this->assertSame(0, $result['result_count']);
        $this->assertSame([], $result['results']);
    }

    public function test_search_scopes_by_tenant(): void
    {
        $this->seedAndArchiveAlert('tenant-a', 'TYPE_A');
        $this->seedAndArchiveAlert('tenant-b', 'TYPE_B');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 'tenant-a', null, null);

        $this->assertSame(1, $result['result_count']);
        $this->assertSame('tenant-a', $result['results'][0]['tenant_id']);
    }

    public function test_search_across_all_tenants_when_tenant_id_null(): void
    {
        $this->seedAndArchiveAlert('tenant-a', 'TYPE_A');
        $this->seedAndArchiveAlert('tenant-b', 'TYPE_B');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', null, null, null);

        $this->assertSame(2, $result['result_count']);
    }

    public function test_search_returns_empty_for_nonexistent_table_dir(): void
    {
        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 't1', null, null);

        $this->assertSame(0, $result['result_count']);
        $this->assertSame(0, $result['files_scanned']);
    }

    public function test_search_respects_limit(): void
    {
        $this->seedAndArchiveAlert('t1', 'TYPE_A');
        $this->seedAndArchiveAlert('t1', 'TYPE_B');
        $this->seedAndArchiveAlert('t1', 'TYPE_C');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search('security_alerts', 't1', null, null, [], limit: 2);

        $this->assertSame(2, $result['result_count']);
        $this->assertTrue($result['truncated']);
    }

    public function test_search_date_range_excludes_files_outside_window(): void
    {
        $this->seedAndArchiveAlert('t1', 'OLD_ARCHIVE_RUN');

        $service = new ArchiveSearchService($this->archiveDir);
        // The archive file's timestamp in its filename is "now" (archived just now);
        // a range entirely in the past should exclude it.
        $result = $service->search(
            'security_alerts',
            't1',
            now()->subYears(2),
            now()->subYear(),
        );

        $this->assertSame(0, $result['files_scanned']);
        $this->assertSame(0, $result['result_count']);
    }

    public function test_search_date_range_includes_files_inside_window(): void
    {
        $this->seedAndArchiveAlert('t1', 'RECENT_ARCHIVE_RUN');

        $service = new ArchiveSearchService($this->archiveDir);
        $result = $service->search(
            'security_alerts',
            't1',
            now()->subMinute(),
            now()->addMinute(),
        );

        $this->assertSame(1, $result['result_count']);
    }

    public function test_command_prints_results_and_summary(): void
    {
        $this->seedAndArchiveAlert('t1', 'CLI_TEST_TYPE');

        $this->artisan('security:archive-search', [
            'table' => 'security_alerts',
            '--tenant' => 't1',
            '--archive-dir' => $this->archiveDir,
        ])
            ->expectsOutputToContain('CLI_TEST_TYPE')
            ->assertSuccessful();
    }

    public function test_command_supports_field_value_filters(): void
    {
        $this->seedAndArchiveAlert('t1', 'TYPE_A');
        $this->seedAndArchiveAlert('t1', 'TYPE_B');

        $this->artisan('security:archive-search', [
            'table' => 'security_alerts',
            '--tenant' => 't1',
            '--archive-dir' => $this->archiveDir,
            '--filter' => ['alert_type=TYPE_B'],
        ])
            ->expectsOutputToContain('results=1')
            ->assertSuccessful();
    }
}
