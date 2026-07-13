<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClickHouseArchiveSearchService;
use App\Services\ClickHouseArchiveWriter;
use App\Services\SecurityRetentionArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DATA-TIERING (warm tier) — ClickHouse becomes a real, indexed,
 * months-scale searchable tier alongside the phase 1/2 local gzip archive,
 * closing the gap both SecurityRetentionArchiveService and
 * ArchiveSearchService's own docblocks always described as separate,
 * larger, live-infra-dependent scope.
 *
 * No live ClickHouse in CI -- these tests mock the HTTP layer, matching
 * ClickHouseTelemetryWriterTest's existing convention for the same
 * infrastructure.clickhouse config.
 */
class DataTieringWarmTierTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = sys_get_temp_dir().'/detector_warm_tier_test_'.Str::uuid();
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

    private function configureClickHouse(): void
    {
        Config::set('xdr.infrastructure.clickhouse.http_url', 'http://ch.test:8123');
        Config::set('xdr.infrastructure.clickhouse.database', 'detector_analytics');
        Config::set('xdr.infrastructure.clickhouse.user', 'detector');
        Config::set('xdr.infrastructure.clickhouse.password', 'detector');
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
            'evidence' => json_encode(['probe' => 'warm-tier-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // ClickHouseArchiveWriter
    // -------------------------------------------------------------------------

    public function test_writer_inserts_into_archived_records_table(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        $ok = app(ClickHouseArchiveWriter::class)->insert([
            ['source_table' => 'security_alerts', 'tenant_id' => 't1', 'record_id' => '1', 'original_ts' => '2026-01-01 00:00:00.000', 'payload' => '{}'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_starts_with($body, "INSERT INTO archived_records FORMAT JSONEachRow\n")
                && str_contains($body, '"source_table":"security_alerts"');
        });
    }

    public function test_writer_insert_is_noop_for_empty_rows(): void
    {
        $this->configureClickHouse();
        Http::fake();

        $ok = app(ClickHouseArchiveWriter::class)->insert([]);

        $this->assertTrue($ok);
        Http::assertNothingSent();
    }

    public function test_writer_insert_returns_false_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('error', 500)]);

        $ok = app(ClickHouseArchiveWriter::class)->insert([['source_table' => 'x', 'tenant_id' => '', 'record_id' => '1', 'original_ts' => '2026-01-01 00:00:00.000', 'payload' => '{}']]);

        $this->assertFalse($ok);
    }

    public function test_map_archived_row_produces_expected_shape(): void
    {
        $writer = app(ClickHouseArchiveWriter::class);

        $row = $writer->mapArchivedRow('security_alerts', ['id' => 42, 'alert_type' => 'TEST'], 'tenant-a', '2026-01-01 00:00:00.000000');

        $this->assertSame('security_alerts', $row['source_table']);
        $this->assertSame('tenant-a', $row['tenant_id']);
        $this->assertSame('42', $row['record_id']);
        $this->assertSame('2026-01-01 00:00:00.000000', $row['original_ts']);
        $this->assertStringContainsString('"alert_type":"TEST"', $row['payload']);
    }

    public function test_map_archived_row_defaults_tenant_id_to_empty_string_when_null(): void
    {
        $row = app(ClickHouseArchiveWriter::class)->mapArchivedRow('security_events', ['id' => 1], null, '2026-01-01 00:00:00.000000');

        $this->assertSame('', $row['tenant_id']);
    }

    // -------------------------------------------------------------------------
    // SecurityRetentionArchiveService — warm-tier write path
    // -------------------------------------------------------------------------

    public function test_archive_writes_to_clickhouse_when_warm_tier_enabled(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', true);
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_starts_with($body, "INSERT INTO archived_records FORMAT JSONEachRow\n")
                && str_contains($body, '"tenant_id":"t1"');
        });
        // The gzip archive must still be written -- warm tier is additive,
        // never a replacement for the durability guarantee.
        $files = glob("{$this->archiveDir}/security_alerts/t1/*.jsonl.gz");
        $this->assertCount(1, $files);
    }

    public function test_archive_does_not_call_clickhouse_when_warm_tier_disabled(): void
    {
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', false);
        Http::fake();
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        $this->assertSame(1, $deleted);
        Http::assertNothingSent();
    }

    public function test_archive_deletion_proceeds_even_when_clickhouse_write_fails(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', true);
        Http::fake(['ch.test:8123/*' => Http::response('unavailable', 503)]);
        $this->seedAlert('t1');

        $service = new SecurityRetentionArchiveService($this->archiveDir);
        $deleted = $service->archiveAndDelete('security_alerts', 'detected_at', now(), 't1');

        // Best-effort warm tier: a ClickHouse failure must never block the
        // gzip-backed deletion this class exists to guarantee.
        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
    }

    // -------------------------------------------------------------------------
    // ClickHouseArchiveSearchService — warm-tier read path
    // -------------------------------------------------------------------------

    public function test_search_sends_parameterized_query_with_indexed_filters(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        app(ClickHouseArchiveSearchService::class)->search(
            table: 'security_alerts',
            tenantId: 't1',
            from: now()->subDays(10),
            to: now(),
            filters: [],
            limit: 50,
        );

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'FROM archived_records')
                && str_contains($body, 'source_table = {source_table:String}')
                && str_contains($body, 'tenant_id = {tenant_id:String}')
                && str_contains($url, 'param_source_table=security_alerts')
                && str_contains($url, 'param_tenant_id=t1');
        });
    }

    public function test_search_decodes_payload_and_applies_exact_match_filters(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['payload' => json_encode(['alert_type' => 'MATCH'])])."\n"
            .json_encode(['payload' => json_encode(['alert_type' => 'OTHER'])]),
            200
        )]);

        $result = app(ClickHouseArchiveSearchService::class)->search(
            table: 'security_alerts',
            tenantId: null,
            from: null,
            to: null,
            filters: ['alert_type' => 'MATCH'],
        );

        $this->assertSame(1, $result['result_count']);
        $this->assertSame('MATCH', $result['results'][0]['alert_type']);
        $this->assertFalse($result['is_local_archive_search']);
    }

    public function test_search_reports_unavailable_on_http_failure_so_caller_can_fall_back(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('server error', 500)]);

        $result = app(ClickHouseArchiveSearchService::class)->search('security_alerts', null, null, null);

        $this->assertTrue($result['warm_tier_unavailable']);
        $this->assertSame([], $result['results']);
    }

    // -------------------------------------------------------------------------
    // ArchiveSearchController — end-to-end wiring + fallback (real HTTP
    // route, matching ArchiveSearchControllerTest's own convention: the
    // controller hard-codes storage/app/archives with no injectable
    // override for a browser route, so these write real fixtures there
    // under a unique per-test tenant id and clean up only what they create).
    // -------------------------------------------------------------------------

    private string $controllerArchiveDir;

    private string $controllerTenantId;

    private function seedAndArchiveAlertForController(string $alertType): void
    {
        $this->controllerArchiveDir = storage_path('app/archives');
        $this->controllerTenantId = 'warm-tier-controller-test-'.uniqid();

        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => now()->subDays(100),
            'alert_type' => $alertType,
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $this->controllerTenantId,
            'score' => 0.9,
            'evidence' => json_encode(['probe' => 'warm-tier-controller-test']),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new SecurityRetentionArchiveService($this->controllerArchiveDir))
            ->archiveAndDelete('security_alerts', 'detected_at', now(), $this->controllerTenantId);
    }

    private function cleanupControllerArchive(): void
    {
        $dir = "{$this->controllerArchiveDir}/security_alerts/{$this->controllerTenantId}";
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*.jsonl.gz") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_controller_uses_gzip_path_when_warm_tier_disabled(): void
    {
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', false);
        Http::fake();
        $this->seedAndArchiveAlertForController('WARM_TIER_OFF_TYPE');
        $viewer = User::factory()->create(['role' => 'viewer']);

        try {
            $this->actingAs($viewer)
                ->withHeaders(['X-Tenant-ID' => $this->controllerTenantId])
                ->get('/archive-search?table=security_alerts')
                ->assertOk()
                ->assertSee('WARM_TIER_OFF_TYPE');

            Http::assertNothingSent();
        } finally {
            $this->cleanupControllerArchive();
        }
    }

    public function test_controller_falls_back_to_gzip_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.warm_tier_enabled', true);
        // Fake ClickHouse as failing for BOTH the archive-time insert (so
        // seeding the fixture doesn't depend on a real network call to a
        // fake hostname -- ClickHouseArchiveWriter::insert() must already
        // swallow this, proven by test_archive_deletion_proceeds_even_when_
        // clickhouse_write_fails above) and the controller's own search
        // request, exercising the real end-to-end fallback path.
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        $this->seedAndArchiveAlertForController('WARM_TIER_FALLBACK_TYPE');
        $viewer = User::factory()->create(['role' => 'viewer']);

        try {
            $this->actingAs($viewer)
                ->withHeaders(['X-Tenant-ID' => $this->controllerTenantId])
                ->get('/archive-search?table=security_alerts')
                ->assertOk()
                ->assertSee('WARM_TIER_FALLBACK_TYPE');
        } finally {
            $this->cleanupControllerArchive();
        }
    }
}
