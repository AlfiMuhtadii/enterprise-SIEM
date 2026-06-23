<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BACKLOG-TENANCY-023 — Tenant Null Creation Guard & Backfill Phase 1
 *
 * Verifies:
 * - GLOBAL_SCOPE constant exists and equals '_global'
 * - requireExplicitScope parameter prevents accidental admin null creation
 * - Admin + no header + store in strict mode → 403 (requireExplicitScope=true)
 * - Admin + _global header + store in strict mode → 200 with null tenant_id
 * - Admin + tenant header + store in strict mode → 200 with correct tenant_id
 * - Non-admin using _global sentinel → TenantSpoofAttemptException
 * - System context (resolveSystemContext) unaffected by requireExplicitScope
 * - Legacy mode: admin + no header + store → still creates null tenant_id
 * - tenant:null-audit command reports correctly, never mutates
 */
class TenantNullCreationGuardTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextAuthority $authority;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authority = app(TenantContextAuthority::class);
    }

    // =========================================================================
    // GLOBAL_SCOPE constant
    // =========================================================================

    public function test_global_scope_constant_is_underscore_global(): void
    {
        $this->assertSame('_global', TenantContextAuthority::GLOBAL_SCOPE);
    }

    // =========================================================================
    // requireExplicitScope unit tests
    // =========================================================================

    public function test_strict_admin_no_header_require_explicit_scope_throws_missing(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin   = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test');

        $this->expectException(\App\Exceptions\TenantContextMissingException::class);
        $this->authority->validateAndResolve(
            $request, $admin,
            requireTenantContext: true,
            requireExplicitScope: true,
        );
    }

    public function test_strict_admin_global_scope_header_returns_null(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin   = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X-TENANT-ID' => TenantContextAuthority::GLOBAL_SCOPE,
        ]);

        $result = $this->authority->validateAndResolve(
            $request, $admin,
            requireTenantContext: true,
            requireExplicitScope: true,
        );

        $this->assertNull($result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_explicit_tenant_header_returns_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin   = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X-TENANT-ID' => 'tenant-A',
        ]);

        $result = $this->authority->validateAndResolve(
            $request, $admin,
            requireTenantContext: true,
            requireExplicitScope: true,
        );

        $this->assertSame('tenant-A', $result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_non_admin_global_scope_throws_spoof(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X-TENANT-ID' => TenantContextAuthority::GLOBAL_SCOPE,
        ]);

        $this->expectException(\App\Exceptions\TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $analyst);
    }

    public function test_legacy_non_admin_global_scope_throws_spoof(): void
    {
        // Even in legacy mode, _global is admin-only
        $analyst = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X-TENANT-ID' => TenantContextAuthority::GLOBAL_SCOPE,
        ]);

        $this->expectException(\App\Exceptions\TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $analyst);
    }

    public function test_require_explicit_scope_false_admin_no_header_returns_null(): void
    {
        // requireExplicitScope=false (default): admin no header still returns null (reads unaffected)
        config(['xdr.tenancy.strict_mode' => true]);

        $admin   = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test');

        $result = $this->authority->validateAndResolve(
            $request, $admin,
            requireTenantContext: true,
            requireExplicitScope: false,
        );

        $this->assertNull($result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_legacy_admin_no_header_require_explicit_scope_still_returns_null(): void
    {
        // Legacy mode: requireExplicitScope has no effect — admin no header → null
        $admin   = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test');

        $result = $this->authority->validateAndResolve(
            $request, $admin,
            requireTenantContext: true,
            requireExplicitScope: true,
        );

        $this->assertNull($result);
    }

    // =========================================================================
    // HTTP — strict mode store (ShadowSoak)
    // =========================================================================

    public function test_strict_store_admin_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_store_admin_global_scope_creates_null_tenant_id(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => TenantContextAuthority::GLOBAL_SCOPE])
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => null,
        ]);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_store_admin_explicit_tenant_creates_scoped_record(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-X'])
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => 'tenant-X',
        ]);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // HTTP — admin read routes still unscoped (requireExplicitScope=false on reads)
    // =========================================================================

    public function test_strict_admin_no_header_advisory_index_still_returns_200(): void
    {
        // Reads use requireExplicitScope=false — admin without header can still list
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/advisory/findings')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_no_header_dlq_index_still_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/dlq/records')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_no_header_shadow_soak_index_still_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/shadow-soak')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // System context — unaffected by requireExplicitScope
    // =========================================================================

    public function test_system_context_always_allowed_with_null_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $ctx = $this->authority->resolveSystemContext(null);

        $this->assertNull($ctx['tenant_id']);
        $this->assertSame(TenantContextAuthority::SOURCE_SYSTEM, $ctx['source']);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_system_context_always_allowed_with_explicit_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $ctx = $this->authority->resolveSystemContext('tenant-pipeline');

        $this->assertSame('tenant-pipeline', $ctx['tenant_id']);
        $this->assertSame(TenantContextAuthority::SOURCE_SYSTEM, $ctx['source']);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Legacy mode — backward compat preserved
    // =========================================================================

    public function test_legacy_store_admin_no_header_creates_null_tenant_id(): void
    {
        // Legacy mode: requireExplicitScope has no effect → null tenant_id still created
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => null,
        ]);
    }

    public function test_legacy_store_member_no_header_creates_null_tenant_id(): void
    {
        $engineer = User::factory()->create(['role' => 'detection_engineer']);
        $admin    = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($engineer->id, 'tenant-A', $admin->id);

        $this->actingAs($engineer)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        // Legacy: no header → null tenant_id (backward compat)
        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => null,
        ]);
    }

    // =========================================================================
    // tenant:null-audit command
    // =========================================================================

    public function test_null_audit_command_exits_zero_when_all_clean(): void
    {
        // Fresh DB from RefreshDatabase — all tenant-scoped tables have 0 records
        $this->artisan('tenant:null-audit')
            ->assertExitCode(0);
    }

    public function test_null_audit_command_exits_one_when_nulls_exist(): void
    {
        // Create a soak run with null tenant_id
        \App\Models\ShadowSoakRun::create([
            'run_id'        => 'audit-test-' . Str::uuid(),
            'domain'        => 'endpoint',
            'window_hours'  => 1,
            'status'        => 'completed',
            'dry_run'       => false,
            'started_at'    => now()->subHour(),
            'completed_at'  => now(),
            'initiated_by'  => '1',
            'evidence_pass' => false,
            'gate_summary'  => [],
            'tenant_id'     => null,
        ]);

        $this->artisan('tenant:null-audit')
            ->assertExitCode(1);
    }

    public function test_null_audit_command_single_table_option(): void
    {
        $this->artisan('tenant:null-audit', ['--table' => 'shadow_soak_runs'])
            ->assertExitCode(0);
    }

    public function test_null_audit_command_output_option_writes_json(): void
    {
        $path = storage_path('test_tenant_null_audit_' . Str::uuid() . '.json');

        $this->artisan('tenant:null-audit', ['--output' => $path])
            ->assertExitCode(0);

        $this->assertFileExists($path);
        $report = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('tables', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertFalse($report['has_null_records']);

        unlink($path);
    }

    public function test_null_audit_command_does_not_mutate_records(): void
    {
        $runId = 'audit-mutation-check-' . Str::uuid();
        \App\Models\ShadowSoakRun::create([
            'run_id'        => $runId,
            'domain'        => 'endpoint',
            'window_hours'  => 1,
            'status'        => 'completed',
            'dry_run'       => false,
            'started_at'    => now()->subHour(),
            'completed_at'  => now(),
            'initiated_by'  => '1',
            'evidence_pass' => false,
            'gate_summary'  => [],
            'tenant_id'     => null,
        ]);

        $this->artisan('tenant:null-audit')->assertExitCode(1);

        // Record unchanged after audit
        $this->assertDatabaseHas('shadow_soak_runs', [
            'run_id'    => $runId,
            'tenant_id' => null,
        ]);
    }

    public function test_null_audit_command_missing_table_reports_not_fails(): void
    {
        // A non-existent table is also not in ISOLATED_TABLES → rejected before schema check
        $this->artisan('tenant:null-audit', ['--table' => 'nonexistent_table_xyz'])
            ->assertExitCode(1);
    }

    public function test_null_audit_command_unisolated_table_option_fails(): void
    {
        // 'users' exists in DB but is not tenant-isolated → must be rejected
        $this->artisan('tenant:null-audit', ['--table' => 'users'])
            ->assertExitCode(1);
    }
}
