<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-AGENT-UPDATE + PERF-AGENT-HEALTH-N1 — agent-management N+1 refactors.
 *
 * PERF-AGENT-UPDATE: AgentIngestionController::config() marks all retrieved
 * queued commands sent in one bulk UPDATE.
 * PERF-AGENT-HEALTH-N1: AgentHealthCheckCommand eager-loads policies + failure
 * agents and batches stale-offline updates.
 */
class PerfAgentN1Test extends TestCase
{
    use RefreshDatabase;

    // ---- helpers -----------------------------------------------------------

    private function registerAgent(string $fingerprint, string $hostId): array
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        return $this->postJson('/api/agents/register', [
            'host_fingerprint' => $fingerprint,
            'host_id' => $hostId,
            'agent_version' => '0.1.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();
    }

    private function postSignedAgentJson(string $uri, string $agentId, string $secret, array $payload)
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_ID' => $agentId,
            'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_AGENT_SIGNATURE' => $signature,
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function queueCommand(string $commandId, string $agentId): void
    {
        DB::table('agent_commands')->insert([
            'command_id' => $commandId,
            'agent_id' => $agentId,
            'command_type' => 'collect-now',
            'status' => 'queued',
            'payload' => json_encode([]),
            'queued_at' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- PERF-AGENT-UPDATE --------------------------------------------------

    public function test_config_marks_all_queued_commands_sent_in_bulk(): void
    {
        $agent = $this->registerAgent('fp-bulk', 'host-bulk');
        $this->queueCommand('cmd-1', $agent['agent_id']);
        $this->queueCommand('cmd-2', $agent['agent_id']);
        $this->queueCommand('cmd-3', $agent['agent_id']);

        $config = $this->postSignedAgentJson('/api/agents/config', $agent['agent_id'], $agent['agent_secret'], [])
            ->assertOk()
            ->json();

        $this->assertCount(3, $config['commands']);
        foreach (['cmd-1', 'cmd-2', 'cmd-3'] as $id) {
            $this->assertDatabaseHas('agent_commands', [
                'command_id' => $id,
                'status' => 'sent',
                'attempts' => 1,
            ]);
        }
        $this->assertSame(0, DB::table('agent_commands')->where('status', 'queued')->count());
    }

    public function test_config_with_no_commands_is_noop(): void
    {
        $agent = $this->registerAgent('fp-empty', 'host-empty');

        $config = $this->postSignedAgentJson('/api/agents/config', $agent['agent_id'], $agent['agent_secret'], [])
            ->assertOk()
            ->json();

        $this->assertSame([], $config['commands']);
        $this->assertSame(0, DB::table('agent_commands')->count());
    }

    // ---- PERF-AGENT-HEALTH-N1 ----------------------------------------------

    private function seedAgent(string $agentId, array $overrides = []): void
    {
        // PostgreSQL session is +07 while app tz is UTC; seed timestamptz with an
        // explicit offset so the PHP-side diffInSeconds() staleness check is exact.
        DB::table('endpoint_agents')->insert(array_merge([
            'agent_id' => $agentId,
            'host_fingerprint' => 'fp-'.$agentId,
            'host_id' => 'host-'.$agentId,
            'agent_version' => '0.1.0',
            'status' => 'online',
            'last_seen_at' => now()->format('Y-m-d H:i:sP'),
            'policy_id' => null,
            'policy_version_seen' => 0,
            'retry_queue_depth' => 0,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_health_check_flags_stale_agents_and_marks_offline(): void
    {
        $this->seedAgent('stale-1', ['last_seen_at' => now()->subHours(2)->format('Y-m-d H:i:sP')]);
        $this->seedAgent('fresh-1', ['last_seen_at' => now()->format('Y-m-d H:i:sP')]);

        $this->artisan('soc:agent-health-check')->assertExitCode(0);

        $this->assertDatabaseHas('endpoint_agents', ['agent_id' => 'stale-1', 'status' => 'offline']);
        $this->assertDatabaseHas('endpoint_agents', ['agent_id' => 'fresh-1', 'status' => 'online']);
        $this->assertDatabaseHas('security_alerts', ['actor_key' => 'stale-1', 'alert_type' => 'AGENT_STALE_OR_STOPPED']);
    }

    public function test_health_check_detects_outdated_policy_via_eager_map(): void
    {
        DB::table('agent_policies')->insert([
            'policy_id' => 'pol-1',
            'name' => 'Pol 1',
            'version' => 5,
            'is_default' => true,
            'collection_interval_seconds' => 60,
            'enabled_collectors' => json_encode([]),
            'max_batch_size' => 100,
            'retry_policy' => json_encode([]),
            'telemetry_categories' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedAgent('pol-agent', ['policy_id' => 'pol-1', 'policy_version_seen' => 2, 'last_seen_at' => now()->format('Y-m-d H:i:sP')]);

        $this->artisan('soc:agent-health-check')->assertExitCode(0);

        $this->assertDatabaseHas('security_alerts', [
            'actor_key' => 'pol-agent',
            'alert_type' => 'AGENT_POLICY_OUTDATED',
        ]);
    }

    public function test_health_check_flags_repeated_delivery_failures(): void
    {
        Config::set('soc.agent_delivery_failure_alert_threshold', 3);
        $this->seedAgent('fail-agent', ['last_seen_at' => now()->format('Y-m-d H:i:sP')]);

        for ($i = 0; $i < 4; $i++) {
            DB::table('agent_delivery_failures')->insert([
                'agent_id' => 'fail-agent',
                'failure_type' => 'bad_signature',
                'message' => 'x',
                'payload' => json_encode([]),
                'failed_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('soc:agent-health-check')->assertExitCode(0);

        $this->assertDatabaseHas('security_alerts', [
            'actor_key' => 'fail-agent',
            'alert_type' => 'AGENT_REPEATED_DELIVERY_FAILURE',
        ]);
    }
}
