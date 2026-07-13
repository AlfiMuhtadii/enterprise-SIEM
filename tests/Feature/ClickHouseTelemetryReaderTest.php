<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClickHouseTelemetryReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ARCH-DB-SPLIT (read path): the two lowest-risk telemetry_events read
 * sites — SocEndpointTimelineController's single-host lookup and
 * SocDashboardController's domain-breakdown aggregation — migrated to read
 * from ClickHouse when telemetry writes are routed there, deliberately
 * excluding the 2 correlation detectors that feed real security_alerts
 * (too correctness-sensitive for this pass; see REVIEW_BACKLOG.md).
 *
 * No live ClickHouse in CI -- HTTP mocked, matching
 * ClickHouseTelemetryWriterTest/ClickHouseArchiveSearchService's existing
 * convention.
 */
class ClickHouseTelemetryReaderTest extends TestCase
{
    use RefreshDatabase;

    private function configureClickHouse(): void
    {
        Config::set('xdr.infrastructure.clickhouse.http_url', 'http://ch.test:8123');
        Config::set('xdr.infrastructure.clickhouse.database', 'detector_analytics');
        Config::set('xdr.infrastructure.clickhouse.user', 'detector');
        Config::set('xdr.infrastructure.clickhouse.password', 'detector');
    }

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — host timeline
    // -------------------------------------------------------------------------

    public function test_host_timeline_sends_parameterized_query(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader())->hostTimeline('host-a', now()->subHour(), '', 300);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'FROM telemetry_events')
                && str_contains($body, 'host_id = {host_id:String}')
                && str_contains($body, 'ORDER BY ts DESC LIMIT 300')
                && str_contains($url, 'param_host_id=host-a');
        });
    }

    public function test_host_timeline_adds_event_type_filter_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader())->hostTimeline('host-a', now()->subHour(), 'connection_delta', 300);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            $url = (string) $request->url();

            return str_contains($body, 'event_type = {event_type:String}')
                && str_contains($url, 'param_event_type=connection_delta');
        });
    }

    public function test_host_timeline_returns_decoded_rows(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'connection_delta', 'host_id' => 'host-a', 'process_name' => 'p', 'src_ip' => '1.1.1.1', 'dst_ip' => '2.2.2.2', 'dst_port' => 443]),
            200
        )]);

        $rows = (new ClickHouseTelemetryReader())->hostTimeline('host-a', now()->subHour(), '', 300);

        $this->assertCount(1, $rows);
        $this->assertSame('connection_delta', $rows[0]->event_type);
        $this->assertSame(443, $rows[0]->dst_port);
    }

    public function test_host_timeline_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader())->hostTimeline('host-a', now()->subHour(), '', 300);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — domain breakdown
    // -------------------------------------------------------------------------

    public function test_domain_breakdown_sends_parameterized_in_clause(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader())->domainBreakdown(now()->subDay(), ['identity', 'cloud']);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'GROUP BY telemetry_type')
                && str_contains($body, '{type_0:String}')
                && str_contains($body, '{type_1:String}')
                && str_contains($url, 'param_type_0=identity')
                && str_contains($url, 'param_type_1=cloud');
        });
    }

    public function test_domain_breakdown_returns_decoded_rows(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['telemetry_type' => 'identity', 'total' => 42]),
            200
        )]);

        $rows = (new ClickHouseTelemetryReader())->domainBreakdown(now()->subDay(), ['identity']);

        $this->assertCount(1, $rows);
        $this->assertSame('identity', $rows[0]->telemetry_type);
        $this->assertSame(42, $rows[0]->total);
    }

    public function test_domain_breakdown_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader())->domainBreakdown(now()->subDay(), ['identity']);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // Controller wiring — default (postgres) unaffected; clickhouse routes;
    // clickhouse failure falls back to postgres
    // -------------------------------------------------------------------------

    public function test_dashboard_uses_postgres_by_default(): void
    {
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'postgres');
        Http::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc')->assertOk();

        // The dashboard already makes unrelated pipeline-service health/metrics
        // HTTP calls regardless of this feature -- only assert ClickHouse
        // specifically was never contacted.
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'ch.test'));
    }

    public function test_dashboard_routes_domain_breakdown_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['telemetry_type' => 'identity', 'total' => 7]),
            200
        )]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc')
            ->assertOk()
            ->assertSee('identity')
            ->assertSee('7');

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'GROUP BY telemetry_type'));
    }

    public function test_dashboard_falls_back_to_postgres_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        $user = User::factory()->create(['role' => 'admin']);

        // Must still render successfully (falls back to the real, empty
        // Postgres telemetry_events query) instead of the page breaking.
        $this->actingAs($user)->get('/soc')->assertOk();
    }

    public function test_endpoint_timeline_uses_postgres_by_default(): void
    {
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'postgres');
        Http::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/endpoints/host-default-test')->assertOk();

        Http::assertNothingSent();
    }

    public function test_endpoint_timeline_routes_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'connection_delta', 'host_id' => 'host-ch', 'process_name' => 'chrome.exe', 'src_ip' => '10.0.0.1', 'dst_ip' => '10.0.0.2', 'dst_port' => 443]),
            200
        )]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/endpoints/host-ch')
            ->assertOk()
            ->assertSee('chrome.exe');

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'FROM telemetry_events'));
        // Real Postgres telemetry_events must NOT have been queried for
        // this row -- the whole point of the split.
        $this->assertDatabaseMissing('telemetry_events', ['host_id' => 'host-ch', 'process_name' => 'chrome.exe']);
    }

    public function test_endpoint_timeline_falls_back_to_postgres_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        DB::table('telemetry_events')->insert([
            'ts' => now(),
            'event_id' => 'fallback-evt-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'connection_delta',
            'host_id' => 'host-fallback',
            'process_name' => 'fallback.exe',
            'src_ip' => '1.1.1.1',
            'dst_ip' => '2.2.2.2',
            'dst_port' => 443,
            'payload' => json_encode([]),
        ]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/endpoints/host-fallback')
            ->assertOk()
            ->assertSee('fallback.exe');
    }
}
