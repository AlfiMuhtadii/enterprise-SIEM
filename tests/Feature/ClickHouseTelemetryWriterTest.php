<?php

namespace Tests\Feature;

use App\Services\ClickHouseTelemetryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ARCH-DB-SPLIT: ClickHouseTelemetryWriter talks to ClickHouse's HTTP
 * interface directly (no live ClickHouse in this environment -- these
 * tests mock the HTTP layer, matching XdrStorageValidateCommand's existing
 * Http::fake() convention for the same infrastructure.clickhouse config).
 */
class ClickHouseTelemetryWriterTest extends TestCase
{
    use RefreshDatabase;

    private function configureClickHouse(): void
    {
        Config::set('xdr.infrastructure.clickhouse.http_url', 'http://ch.test:8123');
        Config::set('xdr.infrastructure.clickhouse.database', 'detector_analytics');
        Config::set('xdr.infrastructure.clickhouse.user', 'detector');
        Config::set('xdr.infrastructure.clickhouse.password', 'detector');
    }

    public function test_insert_posts_json_each_row_body_to_clickhouse_http_interface(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        $writer = app(ClickHouseTelemetryWriter::class);
        $ok = $writer->insert([
            ['event_id' => 'e1', 'ts' => '2026-07-12T00:00:00Z'],
            ['event_id' => 'e2', 'ts' => '2026-07-12T00:00:01Z'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_starts_with($body, "INSERT INTO telemetry_events FORMAT JSONEachRow\n")
                && str_contains($body, '"event_id":"e1"')
                && str_contains($body, '"event_id":"e2"')
                && str_contains((string) $request->url(), 'database=detector_analytics');
        });
    }

    public function test_insert_sends_basic_auth_credentials(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        app(ClickHouseTelemetryWriter::class)->insert([['event_id' => 'e1']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_insert_returns_false_on_non_2xx_response(): void
    {
        $this->configureClickHouse();
        Http::fake(['ch.test:8123/*' => Http::response('Code: 62. Syntax error', 400)]);

        $ok = app(ClickHouseTelemetryWriter::class)->insert([['event_id' => 'e1']]);

        $this->assertFalse($ok);
    }

    public function test_insert_is_a_no_op_for_empty_rows(): void
    {
        $this->configureClickHouse();
        Http::fake();

        $ok = app(ClickHouseTelemetryWriter::class)->insert([]);

        $this->assertTrue($ok);
        Http::assertNothingSent();
    }

    public function test_map_agent_event_produces_expected_row_shape(): void
    {
        $writer = app(ClickHouseTelemetryWriter::class);

        $row = $writer->mapAgentEvent([
            'ts' => '2026-07-12T00:00:00Z',
            'event_id' => 'e1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => 'host-a',
            'process_name' => 'powershell.exe',
        ], 'tenant-a');

        $this->assertSame('e1', $row['event_id']);
        $this->assertSame('tenant-a', $row['tenant_id']);
        $this->assertSame('host-a', $row['host_id']);
        $this->assertSame('powershell.exe', $row['process_name']);
        $this->assertArrayHasKey('payload', $row);
    }

    public function test_map_agent_event_defaults_tenant_id_to_empty_string_when_null(): void
    {
        $writer = app(ClickHouseTelemetryWriter::class);

        $row = $writer->mapAgentEvent([
            'ts' => '2026-07-12T00:00:00Z',
            'event_id' => 'e1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => 'host-a',
        ], null);

        $this->assertSame('', $row['tenant_id']);
    }

    // -----------------------------------------------------------------------
    // Full route: AgentIngestionController::telemetry() routing to ClickHouse
    // -----------------------------------------------------------------------

    private function registerAgent(string $hostId): array
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        return $this->postJson('/api/agents/register', [
            'host_fingerprint' => 'fp-'.$hostId,
            'host_id' => $hostId,
            'agent_version' => '0.2.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();
    }

    private function postSignedAgentJson(string $uri, string $agentId, string $secret, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_X_AGENT_ID' => $agentId,
            'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_AGENT_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_telemetry_route_writes_to_postgres_by_default(): void
    {
        $registered = $this->registerAgent('host-ch-default');
        Http::fake();

        $telemetry = ['events' => [[
            'schema_version' => 1,
            'ts' => now()->toIso8601String(),
            'event_id' => 'agent-pg-default-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => 'host-ch-default',
        ]]];

        $this->postSignedAgentJson('/api/agents/telemetry', $registered['agent_id'], $registered['agent_secret'], $telemetry)
            ->assertOk()
            ->assertJsonPath('received', 1)
            ->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('telemetry_events', ['event_id' => 'agent-pg-default-1']);
        Http::assertNothingSent();
    }

    public function test_telemetry_route_writes_to_clickhouse_when_configured(): void
    {
        $this->configureClickHouse();
        Config::set('xdr.infrastructure.clickhouse.telemetry_write_target', 'clickhouse');
        $registered = $this->registerAgent('host-ch-routed');
        Http::fake(['ch.test:8123/*' => Http::response('', 200)]);

        $telemetry = ['events' => [[
            'schema_version' => 1,
            'ts' => now()->toIso8601String(),
            'event_id' => 'agent-ch-routed-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => 'host-ch-routed',
        ]]];

        $this->postSignedAgentJson('/api/agents/telemetry', $registered['agent_id'], $registered['agent_secret'], $telemetry)
            ->assertOk()
            ->assertJsonPath('received', 1)
            ->assertJsonPath('inserted', 1);

        // The whole point of the split: this event must NOT land in Postgres.
        $this->assertDatabaseMissing('telemetry_events', ['event_id' => 'agent-ch-routed-1']);
        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), '"event_id":"agent-ch-routed-1"');
        });
        // The agent's own status/heartbeat bookkeeping still lands in
        // Postgres regardless of telemetry target -- only the telemetry
        // event rows themselves move.
        $this->assertDatabaseHas('endpoint_agents', [
            'agent_id' => $registered['agent_id'],
            'status' => 'online',
            'last_batch_event_count' => 1,
        ]);
    }
}
