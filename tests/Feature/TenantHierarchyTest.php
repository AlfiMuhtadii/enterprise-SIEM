<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantContextAuthority;
use App\Services\TenantHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CAP-MSSP-TENANCY — parent/child tenant hierarchy + advisory rollups.
 * Rollups must be driven exclusively by the explicit, enumerated
 * tenant_hierarchy whitelist — never an unscoped cross-tenant query.
 */
class TenantHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function seedAlert(string $tenantId, string $alertId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => 'g|'.$alertId,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'IDENTITY_ANOMALY',
            'detector_name' => 'IDENTITY_ANOMALY',
            'detector_version' => 'v1',
            'severity' => 'medium',
            'ip' => '203.0.113.5',
            'actor_key' => '203.0.113.5',
            'score' => 0.5,
            'tenant_id' => $tenantId,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIncident(string $tenantId, string $incidentId, string $status = 'open'): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'Test incident',
            'status' => $status,
            'severity' => 'medium',
            'tenant_id' => $tenantId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_link_child_registers_both_tenants_and_creates_active_link(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');

        $this->assertDatabaseHas('tenants', ['tenant_id' => 'mssp-parent']);
        $this->assertDatabaseHas('tenants', ['tenant_id' => 'customer-a']);
        $this->assertDatabaseHas('tenant_hierarchy', [
            'parent_tenant_id' => 'mssp-parent',
            'child_tenant_id' => 'customer-a',
            'is_active' => true,
        ]);
    }

    public function test_tenant_cannot_be_its_own_parent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TenantHierarchyService::class)->linkChild('tenant-x', 'tenant-x');
    }

    public function test_children_of_excludes_unlinked_tenants(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');
        $service->linkChild('mssp-parent', 'customer-b');
        $service->unlinkChild('mssp-parent', 'customer-b');

        $children = $service->childrenOf('mssp-parent');

        $this->assertContains('customer-a', $children);
        $this->assertNotContains('customer-b', $children);
    }

    public function test_unlink_preserves_history_row_but_deactivates(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');
        $service->unlinkChild('mssp-parent', 'customer-a');

        $this->assertDatabaseHas('tenant_hierarchy', [
            'parent_tenant_id' => 'mssp-parent',
            'child_tenant_id' => 'customer-a',
            'is_active' => false,
        ]);
        $this->assertSame(1, DB::table('tenant_hierarchy')->count());
    }

    public function test_rollup_summary_counts_alerts_and_incidents_per_child(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');
        $service->linkChild('mssp-parent', 'customer-b');

        $this->seedAlert('customer-a', 'alert-a1');
        $this->seedAlert('customer-a', 'alert-a2');
        $this->seedIncident('customer-a', 'inc-a1', 'open');
        $this->seedAlert('customer-b', 'alert-b1');
        $this->seedIncident('customer-b', 'inc-b1', 'resolved');

        $summary = collect($service->rollupSummary('mssp-parent'))->keyBy('tenant_id');

        $this->assertSame(2, $summary['customer-a']['alert_count']);
        $this->assertSame(1, $summary['customer-a']['incident_count']);
        $this->assertSame(1, $summary['customer-a']['open_incident_count']);
        $this->assertSame(1, $summary['customer-b']['alert_count']);
        $this->assertSame(0, $summary['customer-b']['open_incident_count']);
    }

    public function test_rollup_summary_never_includes_an_unrelated_tenant(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');

        // an alert exists for a tenant that was never linked to this parent
        $this->seedAlert('unrelated-tenant', 'alert-x');

        $summary = collect($service->rollupSummary('mssp-parent'))->pluck('tenant_id')->all();

        $this->assertNotContains('unrelated-tenant', $summary);
    }

    public function test_rollup_summary_excludes_unlinked_child_even_with_alerts(): void
    {
        $service = app(TenantHierarchyService::class);
        $service->linkChild('mssp-parent', 'customer-a');
        $service->unlinkChild('mssp-parent', 'customer-a');
        $this->seedAlert('customer-a', 'alert-a1');

        $summary = $service->rollupSummary('mssp-parent');

        $this->assertSame([], $summary);
    }

    public function test_rollup_summary_returns_empty_for_parent_with_no_children(): void
    {
        $service = app(TenantHierarchyService::class);
        $this->assertSame([], $service->rollupSummary('lonely-parent'));
    }

    public function test_mssp_analyst_role_is_recognized_by_rbac(): void
    {
        $user = User::factory()->create(['role' => 'mssp_analyst']);
        $this->assertSame('mssp_analyst', \App\Support\Rbac::role($user));
        $this->assertTrue(\App\Support\Rbac::can($user, 'mssp.rollup.view'));
        $this->assertFalse(\App\Support\Rbac::can($user, 'mssp.hierarchy.manage'));
    }

    public function test_rollup_route_denied_without_permission(): void
    {
        // scenario_operator has no mssp.rollup.view permission at all
        $user = User::factory()->create(['role' => 'scenario_operator']);
        $this->actingAs($user)->get(route('mssp.rollup'))->assertForbidden();
    }

    public function test_mssp_analyst_without_membership_sees_empty_rollup_for_unrelated_parent(): void
    {
        app(TenantHierarchyService::class)->linkChild('mssp-parent', 'customer-a');
        $this->seedAlert('customer-a', 'alert-a1');

        $analyst = User::factory()->create(['role' => 'mssp_analyst']);
        // analyst has NO membership in mssp-parent
        $response = $this->actingAs($analyst)->get(route('mssp.rollup', ['parent_tenant_id' => 'mssp-parent']));

        $response->assertOk();
        $response->assertViewHas('summary', []);
    }

    public function test_mssp_analyst_with_membership_sees_rollup(): void
    {
        app(TenantHierarchyService::class)->linkChild('mssp-parent', 'customer-a');
        $this->seedAlert('customer-a', 'alert-a1');

        $analyst = User::factory()->create(['role' => 'mssp_analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'mssp-parent', $analyst->id);

        $response = $this->actingAs($analyst)->get(route('mssp.rollup', ['parent_tenant_id' => 'mssp-parent']));

        $response->assertOk();
        $summary = collect($response->viewData('summary'))->keyBy('tenant_id');
        $this->assertSame(1, $summary['customer-a']['alert_count']);
    }

    public function test_admin_can_view_any_parent_rollup_without_membership(): void
    {
        app(TenantHierarchyService::class)->linkChild('mssp-parent', 'customer-a');
        $this->seedAlert('customer-a', 'alert-a1');

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('mssp.rollup', ['parent_tenant_id' => 'mssp-parent']));

        $response->assertOk();
        $summary = collect($response->viewData('summary'))->keyBy('tenant_id');
        $this->assertSame(1, $summary['customer-a']['alert_count']);
    }

    public function test_link_and_unlink_routes_require_manage_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'mssp_analyst']); // view only, no manage

        $this->actingAs($analyst)->post(route('mssp.link'), [
            'parent_tenant_id' => 'mssp-parent',
            'child_tenant_id' => 'customer-a',
        ])->assertForbidden();
    }

    public function test_admin_can_link_via_http(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('mssp.link'), [
            'parent_tenant_id' => 'mssp-parent',
            'child_tenant_id' => 'customer-a',
        ])->assertRedirect();

        $this->assertDatabaseHas('tenant_hierarchy', [
            'parent_tenant_id' => 'mssp-parent',
            'child_tenant_id' => 'customer-a',
            'is_active' => true,
        ]);
    }
}
