<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EndpointController::index() listed every tenant's enrolled endpoint agents
 * and shadow alert counts with zero tenant scoping, reachable by any role
 * holding the broadly-granted soc:dashboard.view permission -- the read-leak
 * twin of the SocAgentController bug fixed alongside this.
 */
class EndpointInventoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function insertAgent(string $agentId, ?string $tenantId): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id' => $agentId,
            'host_fingerprint' => 'fp-'.$agentId,
            'host_id' => 'host-'.$agentId,
            'agent_version' => '0.1.0',
            'status' => 'online',
            'tenant_id' => $tenantId,
            'last_seen_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEndpointAlert(string $alertId, ?string $tenantId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => 'g|'.$alertId,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'suspicious_dns_query',
            'detector_name' => 'suspicious_dns_query',
            'detector_version' => 'v1',
            'severity' => 'critical',
            'actor_key' => 'host-'.$alertId,
            'score' => 0.5,
            'tenant_id' => $tenantId,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_only_lists_agents_for_the_requesting_tenant(): void
    {
        $this->insertAgent('endpoint-tenant-a', 'tenant-a');
        $this->insertAgent('endpoint-tenant-b', 'tenant-b');

        $viewer = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('endpoint.index'));

        $response->assertOk();
        $response->assertSee('endpoint-tenant-a');
        $response->assertDontSee('endpoint-tenant-b');
    }

    public function test_index_stats_only_count_the_requesting_tenants_alerts(): void
    {
        $this->insertAgent('endpoint-stats-a', 'tenant-a');
        $this->insertEndpointAlert('alert-stats-a', 'tenant-a');
        $this->insertEndpointAlert('alert-stats-b', 'tenant-b');

        $viewer = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('endpoint.index'));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return $stats['shadow_alerts'] === 1 && $stats['critical_hosts'] === 1;
        });
    }

    public function test_index_without_tenant_header_shows_everything_legacy_pass_through(): void
    {
        $this->insertAgent('endpoint-legacy-a', 'tenant-a');
        $this->insertAgent('endpoint-legacy-b', 'tenant-b');

        $viewer = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($viewer)->get(route('endpoint.index'));

        $response->assertOk();
        $response->assertSee('endpoint-legacy-a');
        $response->assertSee('endpoint-legacy-b');
    }
}
