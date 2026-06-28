<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocResponseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_recommendation_can_be_approved_into_agent_command(): void
    {
        $analyst  = User::factory()->create(['role' => 'analyst']);
        $approver = User::factory()->create(['role' => 'analyst']);
        DB::table('endpoint_agents')->insert([
            'agent_id' => 'agent-response-1',
            'host_fingerprint' => 'fp-response',
            'host_id' => 'host-response',
            'agent_version' => '0.2.0',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)->post('/soc/responses', [
            'source_type' => 'incident',
            'source_id' => 'INC-1',
            'agent_id' => 'agent-response-1',
            'action_type' => 'collect-now',
            'severity' => 'medium',
            'reason' => 'collect fresh evidence',
        ])->assertRedirect();

        $response = DB::table('soc_response_workflows')->where('source_id', 'INC-1')->first();
        $this->assertNotNull($response);

        // Use a different user to approve — self-approval is blocked
        $this->actingAs($approver)->post('/soc/responses/'.$response->response_id.'/decision', [
            'decision' => 'approve',
        ])->assertRedirect();

        $this->assertDatabaseHas('soc_response_workflows', [
            'response_id' => $response->response_id,
            'status' => 'approved_queued',
            'approved_by' => $approver->email,
        ]);
        // RESP-1: command now routed through EndpointResponseCommandService (endpoint_response_commands).
        // collect-now maps to collect_diagnostics; initial status is pending_approval.
        $this->assertDatabaseHas('endpoint_response_commands', [
            'command_type' => 'collect_diagnostics',
            'status' => 'draft',
        ]);
    }

    public function test_agent_heartbeat_records_stream_metrics(): void
    {
        Config::set('soc.agent_enrollment_token', 'test-token');
        $registered = $this->postJson('/api/agents/register', [
            'host_fingerprint' => 'fp-stream',
            'host_id' => 'host-stream',
            'agent_version' => '0.2.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();

        $payload = [
            'event_count_total' => 5,
            'last_batch_event_count' => 5,
            'retry_queue_depth' => 2,
            'stream_status' => 'healthy',
            'events_streamed_total' => 5,
            'events_dropped_total' => 0,
            'stream_retry_total' => 1,
            'avg_event_latency_ms' => 12.5,
        ];

        $this->postSignedAgentJson('/api/agents/heartbeat', $registered['agent_id'], $registered['agent_secret'], $payload)->assertOk();

        $this->assertDatabaseHas('agent_stream_metrics', [
            'agent_id' => $registered['agent_id'],
            'stream_status' => 'healthy',
            'events_streamed_total' => 5,
            'stream_retry_total' => 1,
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
