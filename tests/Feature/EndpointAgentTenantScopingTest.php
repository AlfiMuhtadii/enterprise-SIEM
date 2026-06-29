<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Services\TenantBoundaryService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class EndpointAgentTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    /** AGENT-TENANCY-GAP: endpoint_agents has tenant_id column */
    public function test_endpoint_agents_has_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('endpoint_agents', 'tenant_id'));
    }

    /** tenant_id is nullable by default */
    public function test_tenant_id_is_nullable(): void
    {
        $agent = EndpointAgent::factory()->create(['tenant_id' => null]);
        $this->assertNull($agent->fresh()->tenant_id);
    }

    /** tenant_id stores and retrieves correctly */
    public function test_tenant_id_is_stored(): void
    {
        $agent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-abc']);
        $this->assertSame('tenant-abc', $agent->fresh()->tenant_id);
    }

    /** TenantBoundaryService recognizes endpoint_agents as isolated */
    public function test_tenant_boundary_service_knows_endpoint_agents_is_isolated(): void
    {
        $svc = app(TenantBoundaryService::class);
        $this->assertTrue($svc->tableHasIsolation('endpoint_agents'));
    }

    /** endpoint_agents is in ISOLATED_TABLES */
    public function test_endpoint_agents_in_isolated_tables(): void
    {
        $this->assertContains('endpoint_agents', TenantBoundaryService::ISOLATED_TABLES);
    }

    /** endpoint_agents is in MUTABLE_TABLES */
    public function test_endpoint_agents_in_mutable_tables(): void
    {
        $this->assertContains('endpoint_agents', TenantBoundaryService::MUTABLE_TABLES);
    }

    /** endpoint_agents is NOT in UNISOLATED_TABLES after migration */
    public function test_endpoint_agents_removed_from_unisolated_tables(): void
    {
        $this->assertNotContains('endpoint_agents', TenantBoundaryService::UNISOLATED_TABLES);
    }

    /** TenantBoundaryService can scope a query to a tenant */
    public function test_scope_query_filters_by_tenant(): void
    {
        EndpointAgent::factory()->create(['tenant_id' => 'tenant-a']);
        EndpointAgent::factory()->create(['tenant_id' => 'tenant-b']);

        $svc    = app(TenantBoundaryService::class);
        $query  = EndpointAgent::query();
        $scoped = $svc->scopeQuery($query, 'tenant-a');

        $results = $scoped->get();
        $this->assertCount(1, $results);
        $this->assertSame('tenant-a', $results->first()->tenant_id);
    }

    /** Agents without tenant_id are returned when no tenant context is set */
    public function test_null_tenant_id_agents_accessible_without_context(): void
    {
        EndpointAgent::factory()->create(['tenant_id' => null]);
        $svc    = app(TenantBoundaryService::class);
        $query  = EndpointAgent::query();
        $scoped = $svc->scopeQuery($query, null);

        $this->assertGreaterThan(0, $scoped->count());
    }

    /** TenantBoundaryService assertAccess allows matching tenant_ids */
    public function test_assert_access_allows_matching_tenant(): void
    {
        $svc = app(TenantBoundaryService::class);
        $this->expectNotToPerformAssertions();
        $svc->assertAccess('tenant-x', 'tenant-x');
    }

    /** TenantBoundaryService assertAccess throws on mismatched tenant_ids */
    public function test_assert_access_rejects_mismatched_tenant(): void
    {
        $svc = app(TenantBoundaryService::class);
        $this->expectException(\App\Exceptions\TenantBoundaryViolationException::class);
        $svc->assertAccess('tenant-a', 'tenant-b');
    }
}
