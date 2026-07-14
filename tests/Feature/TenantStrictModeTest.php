<?php

namespace Tests\Feature;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantSpoofAttemptException;
use App\Models\ShadowSoakRun;
use App\Models\User;
use App\Services\AdvisoryFindingService;
use App\Services\DlqReviewService;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BACKLOG-TENANCY-021 — Tenant Strict Mode & Legacy Compatibility Shutdown
 *
 * Verifies:
 * - STRICT_MODE_DEFAULT constant is false
 * - isStrictMode() reads from config
 * - Legacy mode behaviour: zero-membership pass-through, missing header accepted
 * - Strict mode: missing header + requireTenantContext=true → TenantContextMissingException
 * - Strict mode: zero-membership user with any header → TenantSpoofAttemptException
 * - Strict mode: admin role bypasses both strict gates
 * - requireTenantContext=false on listing routes never throws for missing header
 * - HTTP matrix for advisory, DLQ, shadow-soak in both modes
 * - TenantContextMissingException maps to HTTP 403
 * - System context (resolveSystemContext) is unaffected by strict mode
 */
class TenantStrictModeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextAuthority $authority;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authority = app(TenantContextAuthority::class);
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function test_strict_mode_default_constant_is_false(): void
    {
        $this->assertFalse(TenantContextAuthority::STRICT_MODE_DEFAULT);
    }

    public function test_tenant_boundary_service_strict_mode_default_is_false(): void
    {
        $this->assertFalse(TenantBoundaryService::STRICT_MODE_DEFAULT);
    }

    // =========================================================================
    // TenantContextMissingException
    // =========================================================================

    public function test_missing_exception_message_contains_user_id(): void
    {
        $e = new TenantContextMissingException(99);
        $this->assertStringContainsString('99', $e->getMessage());
    }

    public function test_missing_exception_accepts_custom_message(): void
    {
        $e = new TenantContextMissingException(1, 'custom msg');
        $this->assertSame('custom msg', $e->getMessage());
    }

    // =========================================================================
    // isStrictMode
    // =========================================================================

    public function test_is_strict_mode_returns_false_by_default(): void
    {
        $this->assertFalse($this->authority->isStrictMode());
    }

    public function test_is_strict_mode_returns_true_when_config_set(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);
        $this->assertTrue($this->authority->isStrictMode());
        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Legacy mode — validateAndResolve
    // =========================================================================

    public function test_legacy_no_header_returns_null(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test');

        $this->assertNull($this->authority->validateAndResolve($request, $user));
    }

    public function test_legacy_no_header_with_require_context_returns_null(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test');

        // Legacy: requireTenantContext has no effect — no exception thrown
        $this->assertNull($this->authority->validateAndResolve($request, $user, requireTenantContext: true));
    }

    public function test_legacy_zero_membership_user_with_header_passes_through(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);

        $this->assertSame('tenant-A', $this->authority->validateAndResolve($request, $user));
    }

    // =========================================================================
    // Strict mode — validateAndResolve
    // =========================================================================

    public function test_strict_no_header_with_require_context_false_returns_null(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test');

        // requireTenantContext=false (default): listing routes — no exception
        $result = $this->authority->validateAndResolve($request, $user, requireTenantContext: false);
        $this->assertNull($result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_no_header_with_require_context_true_throws_missing(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test');

        $this->expectException(TenantContextMissingException::class);
        $this->authority->validateAndResolve($request, $user, requireTenantContext: true);
    }

    public function test_strict_zero_membership_user_with_header_throws_spoof(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);

        $this->expectException(TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $user);
    }

    public function test_strict_valid_membership_with_matching_header_accepted(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);
        $result = $this->authority->validateAndResolve($request, $user, requireTenantContext: true);

        $this->assertSame('tenant-A', $result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_valid_membership_with_nonmatching_header_throws_spoof(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-B']);

        $this->expectException(TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $user, requireTenantContext: true);
    }

    // =========================================================================
    // Strict mode — admin bypass
    // =========================================================================

    public function test_strict_admin_no_header_require_context_returns_null(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test');

        // Admin bypass: no header → null (no scoping); TenantContextMissing never thrown
        $this->assertNull($this->authority->validateAndResolve($request, $admin, requireTenantContext: true));

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_with_header_accepted_without_membership(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'any-tenant']);

        // Admin has no memberships but is never blocked
        $this->assertSame('any-tenant', $this->authority->validateAndResolve($request, $admin));

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_zero_memberships_not_treated_as_spoof(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-X']);

        // Admin bypass fires before zero-membership check
        $result = $this->authority->validateAndResolve($request, $admin);
        $this->assertSame('tenant-X', $result);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // System context is unaffected
    // =========================================================================

    public function test_system_context_ignores_strict_mode(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $ctx = $this->authority->resolveSystemContext('tenant-A');

        $this->assertSame('tenant-A', $ctx['tenant_id']);
        $this->assertSame(TenantContextAuthority::SOURCE_SYSTEM, $ctx['source']);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_system_context_null_tenant_in_strict_mode(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $ctx = $this->authority->resolveSystemContext(null);
        $this->assertNull($ctx['tenant_id']);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Legacy mode: membership check still applies when memberships exist
    // =========================================================================

    public function test_legacy_membership_check_still_active_for_nonmember_tenant(): void
    {
        // Membership check (Rule 4) always applies regardless of strict mode
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-B']);

        $this->expectException(TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $user);
    }

    // =========================================================================
    // HTTP — strict mode matrix (advisory findings)
    // =========================================================================

    public function test_strict_advisory_show_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_review_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->post('/advisory/findings/'.$finding->finding_id.'/review', ['action' => 'review'])
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_no_header_returns_403(): void
    {
        // BACKLOG-022: index now uses requireTenantContext=true — member must supply header
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/advisory/findings')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_zero_membership_advisory_show_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        // Zero memberships + header in strict mode → TenantSpoofAttemptException
        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_valid_membership_advisory_show_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_admin_no_header_advisory_show_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload(null));

        // Admin bypass: no header → null → assertAccess(null, null) → passes
        $this->actingAs($admin)
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // HTTP — strict mode matrix (DLQ)
    // =========================================================================

    public function test_strict_dlq_show_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $record = $this->createDlqRecord('tenant-A');

        $this->actingAs($analyst)
            ->get('/dlq/records/'.$record->record_id)
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_dlq_index_no_header_returns_403(): void
    {
        // BACKLOG-022: index now uses requireTenantContext=true — member must supply header
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/dlq/records')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_zero_membership_dlq_show_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $record = $this->createDlqRecord('tenant-A');

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/dlq/records/'.$record->record_id)
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // HTTP — strict mode matrix (shadow-soak)
    // =========================================================================

    public function test_strict_shadow_soak_show_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $run = $this->createSoakRun('tenant-A');

        $this->actingAs($analyst)
            ->get('/shadow-soak/'.$run->run_id)
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_shadow_soak_index_no_header_returns_403(): void
    {
        // BACKLOG-022: index now uses requireTenantContext=true — member must supply header
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/shadow-soak')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // HTTP — legacy mode still works
    // =========================================================================

    public function test_legacy_advisory_show_no_header_returns_200(): void
    {
        // Legacy (default): no header on show → null → assertAccess(tenant_id, null) → passes
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_legacy_zero_membership_advisory_show_with_header_returns_200(): void
    {
        // Legacy: zero-membership user with matching header → pass-through, then assertAccess OK
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAdvisoryPayload(?string $tenantId, string $fingerprint = 'fp-strict-test'): array
    {
        return [
            'finding_id' => Str::uuid()->toString(),
            'rule_id' => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain' => 'endpoint',
            'source_topic' => 'xdr.alerts.shadow.endpoint',
            'alert_type' => 'process_injection',
            'severity' => 'high',
            'confidence' => 0.80,
            'actor_key' => 'host-strict-test',
            'tenant_id' => $tenantId,
            'evidence' => ['pid' => 5555],
            'source_event_ids' => ['evt-strict'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function createDlqRecord(?string $tenantId): \App\Models\DlqRecord
    {
        $svc = app(DlqReviewService::class);
        $fingerprint = 'dlq-strict-'.Str::uuid();

        $svc->upsertFromConsumer([
            'fingerprint' => $fingerprint,
            'dlq_event_type' => 'normalization_failure',
            'source_topic' => 'telemetry.raw',
            'raw_payload' => '{"test":true}',
            'error_message' => 'parse error',
            'occurrence_count' => 1,
            'tenant_id' => $tenantId,
            'replayable' => true,
            'error_reason' => null,
        ]);

        return \App\Models\DlqRecord::where('fingerprint', $fingerprint)->firstOrFail();
    }

    private function createSoakRun(?string $tenantId): ShadowSoakRun
    {
        return ShadowSoakRun::create([
            'run_id' => 'soak-strict-'.Str::uuid(),
            'domain' => 'endpoint',
            'window_hours' => 24,
            'status' => 'completed',
            'dry_run' => false,
            'started_at' => now()->subHours(24),
            'completed_at' => now(),
            'initiated_by' => '1',
            'evidence_pass' => true,
            'gate_summary' => ['pass_count' => 3, 'fail_count' => 0],
            'tenant_id' => $tenantId,
        ]);
    }
}
