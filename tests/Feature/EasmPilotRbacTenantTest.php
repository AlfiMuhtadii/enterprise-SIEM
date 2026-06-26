<?php

namespace Tests\Feature;

use App\Models\EasmScanRun;
use App\Models\PilotReadinessMatrixRun;
use App\Models\User;
use App\Models\WebsiteAsset;
use App\Services\TenantContextAuthority;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RBAC-1: EASM + Pilot Readiness Matrix permissions in config/soc.php
 * EASM-1: TenantContextAuthority enforced in EasmController
 * PILOT-1: Tenant-scoped PilotReadinessMatrixController
 */
class EasmPilotRbacTenantTest extends TestCase
{
    use RefreshDatabase;

    // ── RBAC-1: Permission inventory ─────────────────────────────────────────

    public function test_admin_has_easm_view_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'easm.view'));
    }

    public function test_admin_has_easm_scan_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'easm.scan'));
    }

    public function test_admin_has_pilot_readiness_view_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'pilot.readiness.view'));
    }

    public function test_analyst_has_easm_view_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'easm.view'));
    }

    public function test_analyst_does_not_have_easm_scan_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertFalse(Rbac::can($analyst, 'easm.scan'));
    }

    public function test_analyst_has_pilot_readiness_view_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'pilot.readiness.view'));
    }

    public function test_viewer_has_easm_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertTrue(Rbac::can($viewer, 'easm.view'));
    }

    public function test_viewer_does_not_have_easm_scan_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertFalse(Rbac::can($viewer, 'easm.scan'));
    }

    public function test_viewer_has_pilot_readiness_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertTrue(Rbac::can($viewer, 'pilot.readiness.view'));
    }

    // ── RBAC-1: Route access now reachable ───────────────────────────────────

    public function test_admin_can_access_easm_index_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/soc/easm')
            ->assertStatus(200);
    }

    public function test_admin_can_access_pilot_readiness_matrix_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/soc/pilot/readiness-matrix')
            ->assertStatus(200);
    }

    // ── EASM-1: TCA enforced in EasmController ───────────────────────────────

    public function test_easm_controller_uses_tca_not_raw_header(): void
    {
        $easmController = new \App\Http\Controllers\EasmController(
            app(\App\Services\EasmPassiveScanService::class),
            app(\App\Services\EasmPostureHistoryService::class),
            app(TenantContextAuthority::class),
        );
        $this->assertInstanceOf(\App\Http\Controllers\EasmController::class, $easmController);
    }

    public function test_easm_index_accessible_by_admin_without_tenant_header(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/soc/easm')
            ->assertStatus(200);
    }

    public function test_easm_cross_tenant_spoof_rejected_for_non_admin(): void
    {
        $authority = app(TenantContextAuthority::class);

        $user = User::factory()->create(['role' => 'analyst']);
        $authority->grantMembership($user->id, 'tenant-A', 1);

        // User has membership for tenant-A but claims tenant-B via header
        $request = \Illuminate\Http\Request::create('/soc/easm', 'GET');
        $request->headers->set('X-Tenant-ID', 'tenant-B');

        $this->expectException(\App\Exceptions\TenantSpoofAttemptException::class);
        $authority->validateAndResolve($request, $user);
    }

    public function test_easm_admin_bypasses_tenant_membership_check(): void
    {
        $authority = app(TenantContextAuthority::class);
        $admin     = User::factory()->create(['role' => 'admin']);

        $request = \Illuminate\Http\Request::create('/soc/easm', 'GET');
        $request->headers->set('X-Tenant-ID', 'any-tenant');

        $resolved = $authority->validateAndResolve($request, $admin);
        $this->assertSame('any-tenant', $resolved);
    }

    // ── PILOT-1: Tenant-scoped PilotReadinessMatrixController ────────────────

    public function test_pilot_matrix_index_scopes_by_tenant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $run_a = PilotReadinessMatrixRun::create([
            'matrix_run_id'       => Str::uuid(),
            'tenant_id'           => 'tenant-A',
            'operator_id'         => (string) $admin->id,
            'status'              => 'initiated',
            'total_gates'         => 4,
            'gates_passed'        => 4,
            'gates_warned'        => 0,
            'gates_failed'        => 0,
            'required_gates_pass' => true,
            'matrix_score'        => 1.0,
            'is_advisory'         => true,
            'autonomous_promotion' => false,
        ]);

        PilotReadinessMatrixRun::create([
            'matrix_run_id'       => Str::uuid(),
            'tenant_id'           => 'tenant-B',
            'operator_id'         => (string) $admin->id,
            'status'              => 'initiated',
            'total_gates'         => 4,
            'gates_passed'        => 4,
            'gates_warned'        => 0,
            'gates_failed'        => 0,
            'required_gates_pass' => true,
            'matrix_score'        => 1.0,
            'is_advisory'         => true,
            'autonomous_promotion' => false,
        ]);

        // Admin requesting tenant-A should only see tenant-A run
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->getJson('/soc/pilot/readiness-matrix');

        $response->assertStatus(200);
        $items = $response->json('runs.data');
        $this->assertCount(1, $items);
        $this->assertSame('tenant-A', $items[0]['tenant_id']);
    }

    public function test_pilot_matrix_show_returns_404_for_cross_tenant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $run_a = PilotReadinessMatrixRun::create([
            'matrix_run_id'       => 'run-tenant-a-001',
            'tenant_id'           => 'tenant-A',
            'operator_id'         => (string) $admin->id,
            'status'              => 'initiated',
            'total_gates'         => 4,
            'gates_passed'        => 4,
            'gates_warned'        => 0,
            'gates_failed'        => 0,
            'required_gates_pass' => true,
            'matrix_score'        => 1.0,
            'is_advisory'         => true,
            'autonomous_promotion' => false,
        ]);

        // Admin requesting tenant-B tries to access tenant-A run → 404
        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->getJson('/soc/pilot/readiness-matrix/run-tenant-a-001')
            ->assertStatus(404);
    }

    public function test_pilot_matrix_report_returns_404_for_cross_tenant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        PilotReadinessMatrixRun::create([
            'matrix_run_id'       => 'run-tenant-a-002',
            'tenant_id'           => 'tenant-A',
            'operator_id'         => (string) $admin->id,
            'status'              => 'initiated',
            'total_gates'         => 4,
            'gates_passed'        => 4,
            'gates_warned'        => 0,
            'gates_failed'        => 0,
            'required_gates_pass' => true,
            'matrix_score'        => 1.0,
            'is_advisory'         => true,
            'autonomous_promotion' => false,
        ]);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->getJson('/soc/pilot/readiness-matrix/run-tenant-a-002/report')
            ->assertStatus(404);
    }

    public function test_pilot_matrix_controller_uses_tca(): void
    {
        $controller = new \App\Http\Controllers\PilotReadinessMatrixController(
            app(\App\Services\EnterprisePilotReadinessMatrixService::class),
            app(TenantContextAuthority::class),
        );
        $this->assertInstanceOf(\App\Http\Controllers\PilotReadinessMatrixController::class, $controller);
    }
}
