<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointAgentConfig;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentMetric;
use App\Models\SecurityHardeningEvent;
use App\Models\User;
use App\Services\EndpointAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EndpointAgentHardeningTest extends TestCase
{
    use RefreshDatabase;

    private EndpointAgentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EndpointAgentService::class);
        Config::set('soc.agent_enrollment_token', 'test-enrollment-token-for-hardening');
        Config::set('soc.agent_heartbeat_interval_seconds', 60);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_endpoint_agents_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_agents'));
    }

    public function test_endpoint_agent_configs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_agent_configs'));
    }

    public function test_endpoint_agent_heartbeats_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_agent_heartbeats'));
    }

    public function test_endpoint_agent_metrics_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_agent_metrics'));
    }

    // -----------------------------------------------------------------------
    // Enrollment
    // -----------------------------------------------------------------------

    public function test_enroll_creates_agent_record(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-abc-123',
            'my-linux-server',
            '1.0.0',
            null,
            '10.0.0.1',
            'Linux'
        );

        $this->assertInstanceOf(EndpointAgent::class, $agent);
        $this->assertSame('host-abc-123', $agent->host_id);
        $this->assertSame('my-linux-server', $agent->hostname);
        $this->assertSame('1.0.0', $agent->agent_version);
        $this->assertSame('10.0.0.1', $agent->ip_address);
        $this->assertSame('Linux', $agent->platform);
    }

    public function test_enroll_generates_agent_id(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-gen-id',
            'gen-host'
        );
        $this->assertNotEmpty($agent->agent_id);
        $this->assertStringStartsWith('agent-', $agent->agent_id);
    }

    public function test_enroll_stores_token_hash_not_plaintext(): void
    {
        $token = 'test-enrollment-token-for-hardening';
        $agent = $this->service->enroll($token, 'host-hash-test', 'hash-host');

        $expectedHash = hash('sha256', $token);
        $this->assertSame($expectedHash, $agent->enrollment_token_hash);
        $this->assertStringNotContainsString($token, $agent->enrollment_token_hash);
    }

    public function test_enroll_creates_default_config_policy(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-config-test',
            'config-host'
        );

        $config = EndpointAgentConfig::where('agent_id', $agent->id)->first();
        $this->assertNotNull($config);
        $this->assertArrayHasKey('telemetry', $config->config);
        $this->assertArrayHasKey('max_buffer_size', $config->config);
    }

    public function test_enroll_duplicate_host_updates_existing(): void
    {
        $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-dup', 'dup-host', '1.0.0'
        );
        $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-dup', 'dup-host', '1.0.1'
        );

        $this->assertSame(1, EndpointAgent::where('host_id', 'host-dup')->count());
        $agent = EndpointAgent::where('host_id', 'host-dup')->first();
        $this->assertSame('1.0.1', $agent->agent_version);
    }

    public function test_validate_enrollment_token_valid(): void
    {
        $this->assertTrue($this->service->validateEnrollmentToken('test-enrollment-token-for-hardening'));
    }

    public function test_validate_enrollment_token_invalid(): void
    {
        $this->assertFalse($this->service->validateEnrollmentToken('wrong-token'));
    }

    public function test_validate_enrollment_token_empty(): void
    {
        $this->assertFalse($this->service->validateEnrollmentToken(''));
    }

    // -----------------------------------------------------------------------
    // Heartbeat signature
    // -----------------------------------------------------------------------

    public function test_heartbeat_signature_valid_passes(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-sig-test', 'sig-host'
        );

        $payload    = '{"agent_id":"' . $agent->agent_id . '","timestamp":"2026-05-17T12:00:00Z"}';
        $signature  = 'sha256=' . hash_hmac('sha256', $payload, 'test-enrollment-token-for-hardening');

        $valid = $this->service->verifyHeartbeatSignature($signature, $payload);
        $this->assertTrue($valid);
    }

    public function test_heartbeat_signature_invalid_rejected(): void
    {
        $payload   = '{"agent_id":"test","timestamp":"2026-05-17T12:00:00Z"}';
        $signature = 'sha256=' . str_repeat('0', 64);

        $valid = $this->service->verifyHeartbeatSignature($signature, $payload);
        $this->assertFalse($valid);
    }

    public function test_heartbeat_empty_token_signature_rejected(): void
    {
        Config::set('soc.agent_enrollment_token', '');
        $valid = $this->service->verifyHeartbeatSignature('sha256=anything', '{}');
        $this->assertFalse($valid);
    }

    public function test_heartbeat_invalid_signature_logs_hardening_event(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-sig-log', 'sig-log-host'
        );

        $rawPayload = '{"agent_id":"' . $agent->agent_id . '","timestamp":"t"}';
        $badSig     = 'sha256=' . str_repeat('f', 64);

        $before = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();
        $this->service->processHeartbeat($agent, $badSig, $rawPayload, ['trace_id' => 'trace-sig-log']);
        $after  = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();

        $this->assertGreaterThan($before, $after);
    }

    public function test_heartbeat_valid_signature_does_not_log_failure(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-sig-ok', 'sig-ok-host'
        );

        $rawPayload = json_encode(['agent_id' => $agent->agent_id, 'timestamp' => now()->toIso8601String()]);
        $sig        = 'sha256=' . hash_hmac('sha256', $rawPayload, 'test-enrollment-token-for-hardening');

        $before = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();
        $this->service->processHeartbeat($agent, $sig, $rawPayload, []);
        $after  = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();

        $this->assertSame($before, $after);
    }

    public function test_heartbeat_appends_to_heartbeat_log(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-hb-log', 'hb-log-host'
        );

        $rawPayload = json_encode(['agent_id' => $agent->agent_id]);
        $sig        = 'sha256=' . hash_hmac('sha256', $rawPayload, 'test-enrollment-token-for-hardening');

        $before = EndpointAgentHeartbeat::where('agent_id', $agent->id)->count();
        $this->service->processHeartbeat($agent, $sig, $rawPayload, []);
        $this->service->processHeartbeat($agent, $sig, $rawPayload, []);
        $after  = EndpointAgentHeartbeat::where('agent_id', $agent->id)->count();

        $this->assertSame($before + 2, $after);
    }

    // -----------------------------------------------------------------------
    // Health state
    // -----------------------------------------------------------------------

    public function test_health_state_online_when_recently_seen(): void
    {
        $agent = EndpointAgent::factory()->create([
            'last_seen_at' => now()->subSeconds(30),
        ]);
        $state = $this->service->calculateHealthState($agent);
        $this->assertSame(EndpointAgent::HEALTH_ONLINE, $state);
    }

    public function test_health_state_degraded_when_moderately_stale(): void
    {
        // heartbeat_interval=60, degraded threshold = 2-5x = 120-300s
        $agent = EndpointAgent::factory()->create([
            'last_seen_at' => now()->subSeconds(200),
        ]);
        $state = $this->service->calculateHealthState($agent);
        $this->assertSame(EndpointAgent::HEALTH_DEGRADED, $state);
    }

    public function test_health_state_stale_when_very_stale(): void
    {
        // stale threshold = 5-30x = 300-1800s
        $agent = EndpointAgent::factory()->create([
            'last_seen_at' => now()->subSeconds(1000),
        ]);
        $state = $this->service->calculateHealthState($agent);
        $this->assertSame(EndpointAgent::HEALTH_STALE, $state);
    }

    public function test_health_state_offline_when_beyond_stale(): void
    {
        // offline threshold > 30x = 1800s+
        $agent = EndpointAgent::factory()->create([
            'last_seen_at' => now()->subSeconds(3600),
        ]);
        $state = $this->service->calculateHealthState($agent);
        $this->assertSame(EndpointAgent::HEALTH_OFFLINE, $state);
    }

    public function test_health_state_offline_when_never_seen(): void
    {
        $agent = EndpointAgent::factory()->create([
            'last_seen_at' => null,
        ]);
        $state = $this->service->calculateHealthState($agent);
        $this->assertSame(EndpointAgent::HEALTH_OFFLINE, $state);
    }

    public function test_refresh_all_health_states_updates_stale_agents(): void
    {
        EndpointAgent::factory()->create(['last_seen_at' => now()->subSeconds(30),   'health_state' => 'offline']);
        EndpointAgent::factory()->create(['last_seen_at' => now()->subSeconds(3600), 'health_state' => 'online']);

        $updated = $this->service->refreshAllHealthStates();
        $this->assertGreaterThan(0, $updated);
    }

    // -----------------------------------------------------------------------
    // Config policy
    // -----------------------------------------------------------------------

    public function test_config_policy_returns_defaults_for_new_agent(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-cfg-default', 'cfg-default-host'
        );

        $config = $this->service->getConfig($agent);
        $this->assertArrayHasKey('telemetry', $config);
        $this->assertArrayHasKey('max_buffer_size', $config);
        $this->assertSame(5000, $config['max_buffer_size']);
        $this->assertSame(1000, $config['buffer_size']);
    }

    public function test_config_policy_returns_custom_config(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-cfg-custom', 'cfg-custom-host'
        );

        $custom = [
            'policy_version'       => '2.0.0',
            'telemetry'            => ['process' => false, 'network' => true, 'dns' => false],
            'batch_interval_seconds' => 90,
            'buffer_size'          => 2000,
            'max_buffer_size'      => 8000,
        ];
        $this->service->updateConfig($agent, $custom);

        $config = $this->service->getConfig($agent);
        $this->assertSame(90, $config['batch_interval_seconds']);
        $this->assertSame(8000, $config['max_buffer_size']);
    }

    public function test_config_policy_update_creates_new_record(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-cfg-history', 'cfg-history-host'
        );

        $before = EndpointAgentConfig::where('agent_id', $agent->id)->count();
        $this->service->updateConfig($agent, ['policy_version' => '1.1.0', 'buffer_size' => 500]);
        $after  = EndpointAgentConfig::where('agent_id', $agent->id)->count();

        $this->assertGreaterThan($before, $after);
    }

    public function test_buffer_size_enforcement_reflected_in_config(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-buf-size', 'buf-host'
        );

        $this->service->updateConfig($agent, [
            'policy_version' => '1.0.0',
            'buffer_size'    => 999,
            'max_buffer_size'=> 3333,
        ]);

        $config = $this->service->getConfig($agent);
        $this->assertSame(999, $config['buffer_size']);
        $this->assertSame(3333, $config['max_buffer_size']);
    }

    // -----------------------------------------------------------------------
    // API endpoints
    // -----------------------------------------------------------------------

    public function test_api_enroll_returns_agent_id(): void
    {
        $this->postJson('/api/agents/enroll', [
            'host_id'      => 'api-host-001',
            'hostname'     => 'api-linux-01',
            'agent_version'=> '1.0.0',
        ], ['Authorization' => 'Bearer test-enrollment-token-for-hardening'])
            ->assertStatus(201)
            ->assertJsonFragment(['host_id' => 'api-host-001'])
            ->assertJsonStructure(['agent_id', 'host_id', 'health_state', 'enrolled_at']);
    }

    public function test_api_enroll_rejects_invalid_token(): void
    {
        $this->postJson('/api/agents/enroll', [
            'host_id' => 'bad-host',
        ], ['Authorization' => 'Bearer wrong-token'])
            ->assertStatus(401);
    }

    public function test_api_enroll_rejects_missing_host_id(): void
    {
        $this->postJson('/api/agents/enroll', [], [
            'Authorization' => 'Bearer test-enrollment-token-for-hardening',
        ])->assertStatus(422);
    }

    public function test_api_heartbeat_accepts_valid_signature(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-api-hb', 'api-hb-host'
        );

        // Build the raw JSON body, sign it, then send the exact bytes as the request body
        // so the server-side signature verification sees the same bytes we signed.
        $rawPayload = json_encode([
            'agent_id'  => $agent->agent_id,
            'timestamp' => now()->toIso8601String(),
            'metrics'   => [],
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', $rawPayload, 'test-enrollment-token-for-hardening');

        $this->call(
            'POST',
            '/api/agents/' . $agent->agent_id . '/heartbeat',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AGENT_SIGNATURE' => $sig],
            $rawPayload
        )->assertOk()
         ->assertJsonFragment(['ok' => true]);
    }

    public function test_api_heartbeat_rejects_invalid_signature(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-api-bad-sig', 'api-bad-sig'
        );

        $this->withHeaders(['X-Agent-Signature' => 'sha256=' . str_repeat('0', 64)])
            ->postJson('/api/agents/' . $agent->agent_id . '/heartbeat', ['agent_id' => $agent->agent_id])
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
    }

    public function test_api_heartbeat_logs_security_event_on_invalid_signature(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-api-sig-log', 'api-sig-log'
        );

        $before = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();

        $this->withHeaders(['X-Agent-Signature' => 'sha256=' . str_repeat('a', 64)])
            ->postJson('/api/agents/' . $agent->agent_id . '/heartbeat', ['agent_id' => $agent->agent_id]);

        $after = SecurityHardeningEvent::where('event_type', 'signature_failure')->count();
        $this->assertGreaterThan($before, $after);
    }

    public function test_api_heartbeat_returns_404_for_unknown_agent(): void
    {
        $this->postJson('/api/agents/agent-does-not-exist/heartbeat', [])
            ->assertStatus(404);
    }

    public function test_api_config_returns_policy_for_enrolled_agent(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-api-config', 'api-config-host'
        );

        $this->getJson('/api/agents/' . $agent->agent_id . '/config')
            ->assertOk()
            ->assertJsonStructure(['agent_id', 'config'])
            ->assertJsonFragment(['agent_id' => $agent->agent_id]);
    }

    public function test_api_config_returns_404_for_unknown_agent(): void
    {
        $this->getJson('/api/agents/unknown-agent/config')
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Web UI (auth required)
    // -----------------------------------------------------------------------

    public function test_agent_inventory_requires_authentication(): void
    {
        $this->get('/endpoint-agents')->assertRedirect('/login');
    }

    public function test_agent_inventory_returns_200_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/endpoint-agents')
            ->assertOk()
            ->assertSee('Endpoint Agent Inventory');
    }

    public function test_agent_detail_requires_authentication(): void
    {
        $this->get('/endpoint-agents/agent-fake')->assertRedirect('/login');
    }

    public function test_agent_config_policy_accessible_for_admin(): void
    {
        $agent = $this->service->enroll(
            'test-enrollment-token-for-hardening',
            'host-web-config', 'web-config-host'
        );

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/endpoint-agents/' . $agent->agent_id . '/config')
            ->assertOk()
            ->assertSee('Config Policy');
    }
}
