<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EndpointAgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_registration_requires_valid_enrollment_token(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        $payload = [
            'host_fingerprint' => 'fp-test-1',
            'host_id' => 'host-a',
            'agent_version' => '0.2.0',
            'os_family' => 'windows',
        ];

        $this->postJson('/api/agents/register', $payload, [
            'X-Agent-Enrollment-Token' => 'wrong',
        ])->assertUnauthorized();

        $this->postJson('/api/agents/register', $payload, [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->assertOk()
            ->assertJsonStructure(['ok', 'agent_id', 'agent_secret']);
    }

    public function test_signed_heartbeat_and_telemetry_are_accepted(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        $registered = $this->postJson('/api/agents/register', [
            'host_fingerprint' => 'fp-test-2',
            'host_id' => 'host-b',
            'agent_version' => '0.2.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();

        $agentId = $registered['agent_id'];
        $secret = $registered['agent_secret'];

        $heartbeat = [
            'event_count_total' => 3,
            'error_count_total' => 0,
            'last_batch_event_count' => 3,
            'agent_version' => '0.2.0',
        ];
        $this->postSignedAgentJson('/api/agents/heartbeat', $agentId, $secret, $heartbeat)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $telemetry = [
            'events' => [[
                'schema_version' => 1,
                'ts' => now()->toIso8601String(),
                'event_id' => 'agent-test-event-1',
                'telemetry_type' => 'endpoint',
                'event_type' => 'process_observed',
                'host_id' => 'host-b',
                'process_name' => 'powershell.exe',
            ]],
        ];

        $this->postSignedAgentJson('/api/agents/telemetry', $agentId, $secret, $telemetry)
            ->assertOk()
            ->assertJsonPath('received', 1);

        $this->assertDatabaseHas('endpoint_agents', [
            'agent_id' => $agentId,
            'host_id' => 'host-b',
            'status' => 'online',
        ]);
        $this->assertDatabaseHas('telemetry_events', [
            'event_id' => 'agent-test-event-1',
            'event_type' => 'process_observed',
        ]);
    }

    public function test_agent_signature_is_required(): void
    {
        $this->postJson('/api/agents/heartbeat', [])->assertUnauthorized();

        $this->assertDatabaseHas('agent_delivery_failures', [
            'failure_type' => 'missing_signature',
        ]);
    }

    public function test_agent_can_pull_policy_and_report_command_result(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        DB::table('agent_policies')->insert([
            'policy_id' => 'policy-test',
            'name' => 'Policy Test',
            'version' => 3,
            'is_default' => true,
            'collection_interval_seconds' => 30,
            'enabled_collectors' => json_encode(['process' => true, 'network' => false]),
            'max_batch_size' => 25,
            'retry_policy' => json_encode(['initial_backoff_seconds' => 1, 'max_backoff_seconds' => 30]),
            'telemetry_categories' => json_encode(['endpoint']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registered = $this->postJson('/api/agents/register', [
            'host_fingerprint' => 'fp-test-3',
            'host_id' => 'host-c',
            'agent_version' => '0.1.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();

        DB::table('agent_commands')->insert([
            'command_id' => 'cmd-test-1',
            'agent_id' => $registered['agent_id'],
            'command_type' => 'collect-now',
            'status' => 'queued',
            'payload' => json_encode([]),
            'queued_at' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = $this->postSignedAgentJson('/api/agents/config', $registered['agent_id'], $registered['agent_secret'], [])
            ->assertOk()
            ->assertJsonPath('policy.policy_id', 'policy-test')
            ->json();

        $this->assertSame('cmd-test-1', $config['commands'][0]['command_id']);
        $this->assertDatabaseHas('agent_commands', [
            'command_id' => 'cmd-test-1',
            'status' => 'sent',
        ]);

        $this->postSignedAgentJson('/api/agents/commands/result', $registered['agent_id'], $registered['agent_secret'], [
            'command_id' => 'cmd-test-1',
            'status' => 'succeeded',
            'result' => ['collected' => 10],
        ])->assertOk();

        $this->assertDatabaseHas('agent_commands', [
            'command_id' => 'cmd-test-1',
            'status' => 'succeeded',
        ]);
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
}
