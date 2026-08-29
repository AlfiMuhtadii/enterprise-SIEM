<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointAgentHeartbeat;
use App\Services\EndpointAgentService;
use App\Services\TenantBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EndpointHeartbeatTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('soc.agent_enrollment_token', 'heartbeat-tenant-test-token');
    }

    public function test_schema_and_boundary_classify_heartbeats_as_append_only_isolated_data(): void
    {
        $this->assertTrue(Schema::hasColumn('endpoint_agent_heartbeats', 'tenant_id'));
        $this->assertContains('endpoint_agent_heartbeats', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('endpoint_agent_heartbeats', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
        $this->assertNotContains('endpoint_agent_heartbeats', TenantBoundaryService::MUTABLE_TABLES);
        $this->assertNotContains('endpoint_agent_heartbeats', TenantBoundaryService::UNISOLATED_TABLES);
    }

    public function test_heartbeat_inherits_tenant_from_authenticated_parent_agent(): void
    {
        $agent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-a']);
        $payload = json_encode(['agent_id' => $agent->agent_id, 'metrics' => []]);
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'heartbeat-tenant-test-token');

        app(EndpointAgentService::class)->processHeartbeat($agent, $signature, $payload, []);

        $heartbeat = EndpointAgentHeartbeat::where('agent_id', $agent->id)->sole();
        $this->assertSame('tenant-a', $heartbeat->tenant_id);
    }

    public function test_invalid_signature_audit_heartbeat_keeps_parent_tenant_lineage(): void
    {
        $agent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-a']);

        app(EndpointAgentService::class)->processHeartbeat(
            $agent,
            'sha256='.str_repeat('0', 64),
            '{}',
            []
        );

        $heartbeat = EndpointAgentHeartbeat::where('agent_id', $agent->id)->sole();
        $this->assertFalse($heartbeat->signature_valid);
        $this->assertSame('tenant-a', $heartbeat->tenant_id);
    }

    public function test_tenant_scoped_query_excludes_other_tenants_and_legacy_null_rows(): void
    {
        $tenantAAgent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-a']);
        $tenantBAgent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-b']);
        $legacyAgent = EndpointAgent::factory()->create(['tenant_id' => null]);

        $tenantAHeartbeat = $this->createHeartbeat($tenantAAgent, 'tenant-a');
        $this->createHeartbeat($tenantBAgent, 'tenant-b');
        $this->createHeartbeat($legacyAgent, null);

        $results = app(TenantBoundaryService::class)
            ->scopeQuery(EndpointAgentHeartbeat::query(), 'tenant-a')
            ->orderBy('id')
            ->get();

        $this->assertSame([$tenantAHeartbeat->id], $results->pluck('id')->all());
    }

    private function createHeartbeat(EndpointAgent $agent, ?string $tenantId): EndpointAgentHeartbeat
    {
        return EndpointAgentHeartbeat::create([
            'agent_id' => $agent->id,
            'tenant_id' => $tenantId,
            'signature' => 'sha256='.str_repeat('a', 64),
            'signature_valid' => true,
            'health_state' => EndpointAgent::HEALTH_ONLINE,
            'metrics' => [],
            'heartbeat_at' => now(),
            'created_at' => now(),
        ]);
    }
}
