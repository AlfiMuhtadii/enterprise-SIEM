<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClickHouseTelemetryReader;
use App\Services\DetectionBacktestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ARCH-DB-SPLIT (read path): telemetry_events read sites — dashboard
 * domain breakdown, endpoint timeline, threat hunt search, forensic
 * collection, and detection backtest — migrated to read from ClickHouse
 * when telemetry writes are routed there, deliberately excluding the 2
 * correlation detectors that feed real security_alerts (too
 * correctness-sensitive for this pass; see REVIEW_BACKLOG.md).
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

        (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), '', 300);

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

        (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), 'connection_delta', 300);

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

        $rows = (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), '', 300);

        $this->assertCount(1, $rows);
        $this->assertSame('connection_delta', $rows[0]->event_type);
        $this->assertSame(443, $rows[0]->dst_port);
    }

    public function test_host_timeline_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), '', 300);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — domain breakdown
    // -------------------------------------------------------------------------

    public function test_domain_breakdown_sends_parameterized_in_clause(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->domainBreakdown(now()->subDay(), ['identity', 'cloud']);

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

        $rows = (new ClickHouseTelemetryReader)->domainBreakdown(now()->subDay(), ['identity']);

        $this->assertCount(1, $rows);
        $this->assertSame('identity', $rows[0]->telemetry_type);
        $this->assertSame(42, $rows[0]->total);
    }

    public function test_domain_breakdown_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader)->domainBreakdown(now()->subDay(), ['identity']);

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

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — hunt search
    // -------------------------------------------------------------------------

    public function test_hunt_search_sends_parameterized_query(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->huntSearch([
            'minutes' => 60, 'host_id' => '', 'process' => '', 'event_type' => '', 'user' => '', 'ip' => '', 'domain' => '',
        ], 100);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, 'FROM telemetry_events WHERE ts >= {since:DateTime64}')
                && str_contains($body, 'ORDER BY ts DESC LIMIT 100');
        });
    }

    public function test_hunt_search_adds_all_filters_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->huntSearch([
            'minutes' => 60, 'host_id' => 'host-a', 'process' => 'chrome', 'event_type' => 'login',
            'user' => 'alice', 'ip' => '10.0.0.1', 'domain' => 'evil.test',
        ], 100);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'host_id ILIKE {host_id:String}')
                && str_contains($body, 'process_name ILIKE {process:String}')
                && str_contains($body, 'event_type ILIKE {event_type:String}')
                && str_contains($body, 'user_name_hash ILIKE {user:String}')
                && str_contains($body, '(src_ip = {ip_src:String} OR dst_ip = {ip_dst:String})')
                && str_contains($body, 'payload ILIKE {domain:String}')
                && str_contains($url, 'param_host_id='.urlencode('%host-a%'))
                && str_contains($url, 'param_domain='.urlencode('%evil.test%'))
                && str_contains($url, 'param_ip_src=10.0.0.1')
                && str_contains($url, 'param_ip_dst=10.0.0.1');
        });
    }

    public function test_hunt_search_returns_decoded_rows(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'login', 'host_id' => 'host-a', 'process_name' => 'chrome.exe', 'src_ip' => '1.1.1.1', 'dst_ip' => '2.2.2.2', 'dst_port' => 443]),
            200
        )]);

        $rows = (new ClickHouseTelemetryReader)->huntSearch([
            'minutes' => 60, 'host_id' => '', 'process' => '', 'event_type' => '', 'user' => '', 'ip' => '', 'domain' => '',
        ], 100);

        $this->assertCount(1, $rows);
        $this->assertSame('chrome.exe', $rows[0]->process_name);
    }

    public function test_hunt_search_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader)->huntSearch([
            'minutes' => 60, 'host_id' => '', 'process' => '', 'event_type' => '', 'user' => '', 'ip' => '', 'domain' => '',
        ], 100);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — forensic host events
    // -------------------------------------------------------------------------

    public function test_forensic_host_events_sends_parameterized_query_with_host_filter(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->forensicHostEvents('host-forensic', 200);

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'WHERE host_id = {host_id:String}')
                && str_contains($body, 'ORDER BY ts DESC LIMIT 200')
                && str_contains($url, 'param_host_id=host-forensic');
        });
    }

    public function test_forensic_host_events_omits_where_clause_without_host_id(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->forensicHostEvents(null, 200);

        Http::assertSent(fn ($request) => ! str_contains((string) $request->body(), 'WHERE'));
    }

    public function test_forensic_host_events_returns_decoded_rows(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'process_start', 'host_id' => 'host-forensic', 'process_name' => 'evil.exe']),
            200
        )]);

        $rows = (new ClickHouseTelemetryReader)->forensicHostEvents('host-forensic', 200);

        $this->assertCount(1, $rows);
        $this->assertSame('evil.exe', $rows[0]->process_name);
    }

    public function test_forensic_host_events_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader)->forensicHostEvents('host-forensic', 200);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // ClickHouseTelemetryReader — identity/cloud/saas backtest window
    // -------------------------------------------------------------------------

    public function test_identity_cloud_saas_window_page_sends_parameterized_query(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->identityCloudSaasWindowPage(now()->subDays(7), now(), 2000, 0);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, "telemetry_type IN ('identity','cloud','saas')")
                && str_contains($body, 'ts BETWEEN {start:DateTime64} AND {end:DateTime64}')
                && str_contains($body, 'ORDER BY ts ASC, event_id ASC')
                && str_contains($body, 'LIMIT {limit:UInt64} OFFSET {offset:UInt64}');
        });
    }

    public function test_identity_cloud_saas_window_page_returns_decoded_rows(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'telemetry_type' => 'identity', 'event_type' => 'login_failed', 'xdr_user' => 'leo']),
            200
        )]);

        $rows = (new ClickHouseTelemetryReader)->identityCloudSaasWindowPage(now()->subDays(7), now(), 2000, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('leo', $rows[0]->xdr_user);
    }

    public function test_identity_cloud_saas_window_page_returns_null_on_failure(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);

        $rows = (new ClickHouseTelemetryReader)->identityCloudSaasWindowPage(now()->subDays(7), now(), 2000, 0);

        $this->assertNull($rows);
    }

    // -------------------------------------------------------------------------
    // Controller/service wiring — hunt
    // -------------------------------------------------------------------------

    public function test_hunt_uses_postgres_by_default(): void
    {
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'postgres');
        Http::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/hunts?run=1')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'ch.test'));
    }

    public function test_hunt_routes_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'login', 'host_id' => 'host-ch-hunt', 'process_name' => 'clickhouse-hunt.exe', 'src_ip' => '10.0.0.1', 'dst_ip' => '10.0.0.2', 'dst_port' => 443]),
            200
        )]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/hunts?run=1')
            ->assertOk()
            ->assertSee('clickhouse-hunt.exe');

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'FROM telemetry_events'));
        $this->assertDatabaseMissing('telemetry_events', ['process_name' => 'clickhouse-hunt.exe']);
    }

    public function test_hunt_falls_back_to_postgres_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        DB::table('telemetry_events')->insert([
            'ts' => now(), 'event_id' => 'hunt-fallback-1', 'telemetry_type' => 'endpoint', 'event_type' => 'login',
            'host_id' => 'host-fallback-hunt', 'process_name' => 'fallback-hunt.exe', 'src_ip' => '1.1.1.1', 'dst_ip' => '2.2.2.2', 'dst_port' => 443,
            'payload' => json_encode([]),
        ]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get('/soc/hunts?run=1')
            ->assertOk()
            ->assertSee('fallback-hunt.exe');
    }

    // -------------------------------------------------------------------------
    // Controller/service wiring — forensics
    // -------------------------------------------------------------------------

    private function seedForensicAgent(string $agentId, string $hostId): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id' => $agentId, 'host_fingerprint' => 'fp-'.$agentId, 'host_id' => $hostId,
            'agent_version' => '0.2.0', 'status' => 'online', 'last_seen_at' => now(),
            'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requestAndApproveForensics(string $analystEmail, string $agentId, string $hostId)
    {
        $analyst = User::where('email', $analystEmail)->first();
        $this->actingAs($analyst)->post('/soc/forensics', [
            'agent_id' => $agentId, 'host_id' => $hostId, 'collection_type' => 'endpoint-diagnostics',
        ])->assertRedirect();
        $job = DB::table('forensic_collection_jobs')->where('agent_id', $agentId)->first();
        $this->actingAs($analyst)->post('/soc/forensics/'.$job->job_id.'/decision', ['decision' => 'approve'])->assertRedirect();

        return DB::table('forensic_collection_jobs')->where('job_id', $job->job_id)->first();
    }

    public function test_forensic_uses_postgres_by_default(): void
    {
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'postgres');
        Http::fake();
        $this->seedForensicAgent('agent-fx-1', 'host-fx-1');
        $analyst = User::factory()->create(['role' => 'analyst', 'email' => 'analyst-fx-1@test.local']);

        $completed = $this->requestAndApproveForensics($analyst->email, 'agent-fx-1', 'host-fx-1');

        $this->assertSame('completed', $completed->status);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'ch.test'));
    }

    public function test_forensic_routes_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response(
            json_encode(['ts' => '2026-01-01 00:00:00', 'event_type' => 'process_start', 'host_id' => 'host-fx-2', 'process_name' => 'ch-forensic.exe']),
            200
        )]);
        $this->seedForensicAgent('agent-fx-2', 'host-fx-2');
        $analyst = User::factory()->create(['role' => 'analyst', 'email' => 'analyst-fx-2@test.local']);

        $completed = $this->requestAndApproveForensics($analyst->email, 'agent-fx-2', 'host-fx-2');

        $this->assertSame('completed', $completed->status);
        $this->assertNotEmpty($completed->artifact_sha256);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'FROM telemetry_events'));
    }

    public function test_forensic_falls_back_to_postgres_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        $this->seedForensicAgent('agent-fx-3', 'host-fx-3');
        DB::table('telemetry_events')->insert([
            'ts' => now(), 'event_id' => 'forensic-fallback-1', 'telemetry_type' => 'endpoint', 'event_type' => 'process_start',
            'host_id' => 'host-fx-3', 'process_name' => 'fallback-forensic.exe', 'payload' => json_encode([]),
        ]);
        $analyst = User::factory()->create(['role' => 'analyst', 'email' => 'analyst-fx-3@test.local']);

        $completed = $this->requestAndApproveForensics($analyst->email, 'agent-fx-3', 'host-fx-3');

        $this->assertSame('completed', $completed->status);
        $this->assertNotEmpty($completed->artifact_sha256);
    }

    // -------------------------------------------------------------------------
    // Service wiring — detection backtest
    // -------------------------------------------------------------------------

    public function test_backtest_uses_postgres_by_default(): void
    {
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'postgres');
        Http::fake();
        DB::table('telemetry_events')->insert([
            'ts' => now(), 'event_id' => 'bt-pg-1', 'telemetry_type' => 'identity', 'event_type' => 'login_failed',
            'xdr_user' => 'pg-user', 'payload' => json_encode([]),
        ]);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame(1, $run->telemetry_event_count);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'ch.test'));
    }

    public function test_backtest_routes_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = json_encode([
                'ts' => now()->format('Y-m-d H:i:s'), 'telemetry_type' => 'identity', 'event_type' => 'login_failed',
                'host_id' => '', 'xdr_user' => 'leo', 'source_ip' => '', 'src_ip' => '', 'risk_score' => 0,
                'xdr_action' => '', 'xdr_result' => '', 'event_source' => '', 'cloud_account' => '',
            ]);
        }
        Http::fake(['ch.test:8123/*' => Http::response(implode("\n", $rows), 200)]);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame(5, $run->telemetry_event_count);
        $this->assertDatabaseHas('detection_backtest_matches', [
            'run_id' => $run->run_id, 'rule_id' => 'IDENTITY_MFA_FAILURE_BURST', 'actor_key' => 'leo',
        ]);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), "telemetry_type IN ('identity','cloud','saas')"));
    }

    public function test_backtest_falls_back_to_postgres_when_clickhouse_unavailable(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('down', 500)]);
        DB::table('telemetry_events')->insert([
            'ts' => now(), 'event_id' => 'bt-fallback-1', 'telemetry_type' => 'identity', 'event_type' => 'login_failed',
            'xdr_user' => 'fallback-user', 'payload' => json_encode([]),
        ]);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame(1, $run->telemetry_event_count);
    }

    // -------------------------------------------------------------------------
    // TENANT-CLICKHOUSE-LEAK — hostTimeline/domainBreakdown/huntSearch/
    // forensicHostEvents accept an optional tenant_id filter
    // -------------------------------------------------------------------------

    public function test_host_timeline_adds_tenant_filter_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), '', 300, 'tenant-x');

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'tenant_id = {tenant_id:String}')
                && str_contains($url, 'param_tenant_id=tenant-x');
        });
    }

    public function test_host_timeline_omits_tenant_filter_when_null(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->hostTimeline('host-a', now()->subHour(), '', 300);

        Http::assertSent(fn ($request) => ! str_contains((string) $request->body(), 'tenant_id'));
    }

    public function test_domain_breakdown_adds_tenant_filter_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->domainBreakdown(now()->subDay(), ['identity'], 'tenant-x');

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'tenant_id = {tenant_id:String}')
                && str_contains($url, 'param_tenant_id=tenant-x');
        });
    }

    public function test_hunt_search_adds_tenant_filter_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->huntSearch([
            'minutes' => 60, 'host_id' => '', 'process' => '', 'event_type' => '', 'user' => '', 'ip' => '', 'domain' => '',
        ], 100, 'tenant-x');

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'tenant_id = {tenant_id:String}')
                && str_contains($url, 'param_tenant_id=tenant-x');
        });
    }

    public function test_forensic_host_events_adds_tenant_filter_when_given(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        (new ClickHouseTelemetryReader)->forensicHostEvents('host-forensic', 200, 'tenant-x');

        Http::assertSent(function ($request) {
            $url = (string) $request->url();
            $body = (string) $request->body();

            return str_contains($body, 'tenant_id = {tenant_id:String}')
                && str_contains($url, 'param_tenant_id=tenant-x');
        });
    }

    public function test_hunt_route_scopes_clickhouse_query_to_requesting_tenant(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);
        $user = User::factory()->create(['role' => 'admin']);
        app(\App\Services\TenantContextAuthority::class)->grantMembership($user->id, 'tenant-hunt-x', $user->id);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-hunt-x'])
            ->get('/soc/hunts?run=1');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'param_tenant_id=tenant-hunt-x'));
    }

    public function test_endpoint_timeline_route_scopes_clickhouse_query_to_requesting_tenant(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);
        $user = User::factory()->create(['role' => 'admin']);
        app(\App\Services\TenantContextAuthority::class)->grantMembership($user->id, 'tenant-timeline-x', $user->id);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-timeline-x'])
            ->get('/soc/endpoints/host-tenant-test');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'param_tenant_id=tenant-timeline-x'));
    }
}
