<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TENANT-AUDIT-LEAK — security_audit_trails was registered in
 * TenantBoundaryService::UNISOLATED_TABLES and /soc/api/audit returned
 * every tenant's audit entries globally with zero scoping. Fixed by adding
 * a nullable tenant_id column, registering the table as ISOLATED +
 * APPEND_ONLY_ISOLATED, threading an optional tenantId through
 * AuditLogger::log(), and scoping SocApiController::audit()'s query the
 * same way SocApiController::stats() already scopes security_incidents/
 * security_alerts.
 */
class TenantAuditLeakTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_audit_logger_persists_tenant_id_when_given(): void
    {
        AuditLogger::log('analyst@test.local', 'test.action', 'test_target', 'target-1', null, null, null, 'tenant-a');

        $this->assertDatabaseHas('security_audit_trails', [
            'action' => 'test.action',
            'target_id' => 'target-1',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_audit_logger_persists_null_tenant_id_when_not_given(): void
    {
        AuditLogger::log('analyst@test.local', 'test.action.legacy', 'test_target', 'target-2');

        $row = DB::table('security_audit_trails')->where('action', 'test.action.legacy')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->tenant_id);
    }

    public function test_audit_api_scopes_to_requested_tenant_and_excludes_other_tenants(): void
    {
        AuditLogger::log('analyst@test.local', 'tenant.a.action', 'test_target', 'a-1', null, null, null, 'tenant-a');
        AuditLogger::log('analyst@test.local', 'tenant.b.action', 'test_target', 'b-1', null, null, null, 'tenant-b');

        $viewer = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($viewer->id, 'tenant-a', $viewer->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/soc/api/audit');

        $response->assertOk();
        $actions = collect($response->json())->pluck('action');
        $this->assertTrue($actions->contains('tenant.a.action'));
        $this->assertFalse($actions->contains('tenant.b.action'));
    }

    public function test_audit_api_without_tenant_header_sees_all_tenants(): void
    {
        AuditLogger::log('analyst@test.local', 'tenant.a.action.2', 'test_target', 'a-2', null, null, null, 'tenant-a');
        AuditLogger::log('analyst@test.local', 'tenant.b.action.2', 'test_target', 'b-2', null, null, null, 'tenant-b');

        $response = $this->actingAs($this->admin())->getJson('/soc/api/audit');

        $response->assertOk();
        $actions = collect($response->json())->pluck('action');
        $this->assertTrue($actions->contains('tenant.a.action.2'));
        $this->assertTrue($actions->contains('tenant.b.action.2'));
    }

    public function test_asset_inventory_register_persists_tenant_scoped_audit_entry(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-audit-asset'])
            ->post('/asset-inventory', [
                'hostname' => 'host-audit-test',
                'environment' => 'production',
                'asset_type' => 'server',
            ])->assertRedirect();

        $this->assertDatabaseHas('security_audit_trails', [
            'action' => 'asset.register',
            'tenant_id' => 'tenant-audit-asset',
        ]);
    }
}
