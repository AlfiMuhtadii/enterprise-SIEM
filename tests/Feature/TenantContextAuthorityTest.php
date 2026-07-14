<?php

namespace Tests\Feature;

use App\Exceptions\TenantSpoofAttemptException;
use App\Models\TenantMembershipAuditEvent;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BACKLOG-TENANCY-020 — Tenant Context Authority & Spoofing Denial
 *
 * Verifies:
 * - X-Tenant-ID is a selector validated against user memberships, not an authority
 * - Admin role bypasses membership check
 * - Users with no memberships pass through (backward compat)
 * - Users with memberships are rejected for tenants they don't belong to
 * - TenantSpoofAttemptException is thrown and maps to HTTP 403
 * - grantMembership / revokeMembership lifecycle with audit trail
 * - resolveSystemContext distinguishes system from user context
 * - USER_TENANT_MEMBERSHIPS_SUPPORTED constant is true
 * - Membership audit events are append-only
 * - HTTP spoofing-denial matrix for advisory, DLQ, shadow-soak controllers
 */
class TenantContextAuthorityTest extends TestCase
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

    public function test_source_user_constant(): void
    {
        $this->assertSame('user', TenantContextAuthority::SOURCE_USER);
    }

    public function test_source_system_constant(): void
    {
        $this->assertSame('system', TenantContextAuthority::SOURCE_SYSTEM);
    }

    public function test_admin_role_constant(): void
    {
        $this->assertSame('admin', TenantContextAuthority::ADMIN_ROLE);
    }

    public function test_user_tenant_memberships_supported_constant_is_true(): void
    {
        $this->assertTrue(TenantBoundaryService::USER_TENANT_MEMBERSHIPS_SUPPORTED);
    }

    // =========================================================================
    // TenantSpoofAttemptException
    // =========================================================================

    public function test_spoof_exception_message_contains_user_id(): void
    {
        $e = new TenantSpoofAttemptException(42, 'evil-tenant', ['tenant-A']);
        $this->assertStringContainsString('42', $e->getMessage());
    }

    public function test_spoof_exception_message_contains_claimed_tenant(): void
    {
        $e = new TenantSpoofAttemptException(42, 'evil-tenant', ['tenant-A']);
        $this->assertStringContainsString('evil-tenant', $e->getMessage());
    }

    public function test_spoof_exception_message_contains_allowed_tenants(): void
    {
        $e = new TenantSpoofAttemptException(42, 'evil-tenant', ['tenant-A', 'tenant-B']);
        $this->assertStringContainsString('tenant-A', $e->getMessage());
    }

    public function test_spoof_exception_accepts_custom_message(): void
    {
        $e = new TenantSpoofAttemptException(1, 'x', [], 'custom');
        $this->assertSame('custom', $e->getMessage());
    }

    // =========================================================================
    // isAdminBypass
    // =========================================================================

    public function test_is_admin_bypass_returns_true_for_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($this->authority->isAdminBypass($admin));
    }

    public function test_is_admin_bypass_returns_false_for_analyst_role(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertFalse($this->authority->isAdminBypass($analyst));
    }

    public function test_is_admin_bypass_returns_false_for_viewer_role(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertFalse($this->authority->isAdminBypass($viewer));
    }

    // =========================================================================
    // grantMembership
    // =========================================================================

    public function test_grant_membership_creates_active_record(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $membership = $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $this->assertTrue($membership->is_active);
        $this->assertSame('tenant-A', $membership->tenant_id);
        $this->assertSame($user->id, $membership->user_id);
    }

    public function test_grant_membership_appends_audit_event(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $membership = $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $this->assertDatabaseHas('tenant_membership_audit_events', [
            'membership_id' => $membership->membership_id,
            'event_type' => 'granted',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_grant_membership_is_idempotent_for_active_membership(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $first = $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $second = $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, UserTenantMembership::count());
        $this->assertSame(1, TenantMembershipAuditEvent::count()); // no duplicate event
    }

    public function test_grant_membership_reactivates_revoked_membership(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-A', $admin->id);

        $reactivated = $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $this->assertTrue($reactivated->is_active);
        $this->assertSame(1, UserTenantMembership::count()); // same row, not a new one
    }

    public function test_re_grant_appends_re_granted_audit_event(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        $this->assertDatabaseHas('tenant_membership_audit_events', [
            'event_type' => 're_granted',
            'user_id' => $user->id,
        ]);
    }

    // =========================================================================
    // revokeMembership
    // =========================================================================

    public function test_revoke_membership_deactivates_record(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-A', $admin->id);

        $this->assertFalse(
            UserTenantMembership::where('user_id', $user->id)->where('tenant_id', 'tenant-A')->first()->is_active
        );
    }

    public function test_revoke_membership_appends_revoked_audit_event(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-A', $admin->id);

        $this->assertDatabaseHas('tenant_membership_audit_events', [
            'event_type' => 'revoked',
            'user_id' => $user->id,
        ]);
    }

    public function test_revoke_nonexistent_membership_throws(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->authority->revokeMembership($user->id, 'tenant-X', $admin->id);
    }

    // =========================================================================
    // getUserTenants
    // =========================================================================

    public function test_get_user_tenants_returns_active_tenants(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($user->id, 'tenant-B', $admin->id);

        $tenants = $this->authority->getUserTenants($user);

        $this->assertContains('tenant-A', $tenants);
        $this->assertContains('tenant-B', $tenants);
        $this->assertCount(2, $tenants);
    }

    public function test_get_user_tenants_excludes_revoked(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($user->id, 'tenant-B', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-B', $admin->id);

        $tenants = $this->authority->getUserTenants($user);

        $this->assertContains('tenant-A', $tenants);
        $this->assertNotContains('tenant-B', $tenants);
    }

    public function test_get_user_tenants_returns_empty_for_no_memberships(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->assertSame([], $this->authority->getUserTenants($user));
    }

    // =========================================================================
    // validateAndResolve — core authority logic
    // =========================================================================

    public function test_validate_and_resolve_returns_null_when_no_header(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test');

        $this->assertNull($this->authority->validateAndResolve($request, $user));
    }

    public function test_validate_and_resolve_admin_bypasses_membership_check(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'any-tenant']);

        $this->assertSame('any-tenant', $this->authority->validateAndResolve($request, $admin));
    }

    public function test_validate_and_resolve_user_with_no_memberships_passes_through(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);

        // No memberships → backward compat pass-through
        $this->assertSame('tenant-A', $this->authority->validateAndResolve($request, $analyst));
    }

    public function test_validate_and_resolve_accepts_valid_membership(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);

        $this->assertSame('tenant-A', $this->authority->validateAndResolve($request, $analyst));
    }

    public function test_validate_and_resolve_throws_for_non_member_tenant(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-B']);

        $this->expectException(TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $analyst);
    }

    public function test_validate_and_resolve_user_with_multiple_tenants_can_switch(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($analyst->id, 'tenant-B', $admin->id);

        $reqA = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-A']);
        $reqB = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-B']);

        $this->assertSame('tenant-A', $this->authority->validateAndResolve($reqA, $analyst));
        $this->assertSame('tenant-B', $this->authority->validateAndResolve($reqB, $analyst));
    }

    public function test_validate_and_resolve_revoked_membership_triggers_spoof_exception(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($analyst->id, 'tenant-B', $admin->id);
        $this->authority->revokeMembership($analyst->id, 'tenant-B', $admin->id);

        // Now user has only tenant-A; claiming tenant-B is a spoof
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X-TENANT-ID' => 'tenant-B']);

        $this->expectException(TenantSpoofAttemptException::class);
        $this->authority->validateAndResolve($request, $analyst);
    }

    // =========================================================================
    // resolveSystemContext
    // =========================================================================

    public function test_resolve_system_context_returns_source_system(): void
    {
        $ctx = $this->authority->resolveSystemContext('tenant-A');
        $this->assertSame(TenantContextAuthority::SOURCE_SYSTEM, $ctx['source']);
    }

    public function test_resolve_system_context_carries_tenant_id(): void
    {
        $ctx = $this->authority->resolveSystemContext('tenant-A');
        $this->assertSame('tenant-A', $ctx['tenant_id']);
    }

    public function test_resolve_system_context_accepts_null_tenant(): void
    {
        $ctx = $this->authority->resolveSystemContext(null);
        $this->assertNull($ctx['tenant_id']);
        $this->assertSame(TenantContextAuthority::SOURCE_SYSTEM, $ctx['source']);
    }

    public function test_resolve_user_context_returns_source_user(): void
    {
        $ctx = $this->authority->resolveUserContext('tenant-A', 42);
        $this->assertSame(TenantContextAuthority::SOURCE_USER, $ctx['source']);
        $this->assertSame('tenant-A', $ctx['tenant_id']);
        $this->assertSame(42, $ctx['user_id']);
    }

    // =========================================================================
    // Membership audit events are append-only
    // =========================================================================

    public function test_membership_audit_event_model_has_no_timestamps(): void
    {
        $model = new TenantMembershipAuditEvent;
        $this->assertFalse($model->timestamps);
    }

    public function test_membership_audit_events_accumulate_across_lifecycle(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->revokeMembership($user->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($user->id, 'tenant-A', $admin->id);

        // granted + revoked + re_granted = 3 events
        $this->assertSame(3, TenantMembershipAuditEvent::count());
    }

    // =========================================================================
    // HTTP — spoofing-denial matrix
    // =========================================================================

    public function test_advisory_show_403_when_user_claims_unjoined_tenant(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Grant tenant-A only; finding is also on tenant-A
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(\App\Services\AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        // Analyst claims tenant-B (not a member) — spoof attempt
        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertForbidden();
    }

    public function test_advisory_show_200_when_user_claims_joined_tenant(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(\App\Services\AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_dlq_show_403_when_user_claims_unjoined_tenant(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $record = $this->createDlqRecord('tenant-A');

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/dlq/records/'.$record->record_id)
            ->assertForbidden();
    }

    public function test_shadow_soak_show_403_when_user_claims_unjoined_tenant(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $run = $this->createSoakRun('tenant-A');

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/shadow-soak/'.$run->run_id)
            ->assertForbidden();
    }

    public function test_advisory_show_403_when_revoked_membership_is_claimed(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);
        $this->authority->grantMembership($analyst->id, 'tenant-B', $admin->id);
        $this->authority->revokeMembership($analyst->id, 'tenant-B', $admin->id);

        $svc = app(\App\Services\AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-B', 'fp-revoked'));

        // User has tenant-A active, tenant-B revoked — claiming tenant-B is a spoof
        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertForbidden();
    }

    public function test_admin_bypasses_spoofing_check_and_can_use_any_tenant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $analyst = User::factory()->create(['role' => 'analyst']);

        // Grant analyst only tenant-A; admin has no explicit memberships
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(\App\Services\AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-Z'));

        // Admin can access any tenant without a membership
        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-Z'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_no_header_bypasses_authority_check_even_with_memberships(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(\App\Services\AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));

        // No header → validateAndResolve returns null → assertAccess('tenant-A', null) → passes
        $this->actingAs($analyst)
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAdvisoryPayload(?string $tenantId, string $fingerprint = 'fp-authority-test'): array
    {
        return [
            'finding_id' => Str::uuid()->toString(),
            'rule_id' => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain' => 'endpoint',
            'source_topic' => 'xdr.alerts.shadow.endpoint',
            'alert_type' => 'process_injection',
            'severity' => 'high',
            'confidence' => 0.80,
            'actor_key' => 'host-authority-test',
            'tenant_id' => $tenantId,
            'evidence' => ['pid' => 9999],
            'source_event_ids' => ['evt-999'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function createDlqRecord(?string $tenantId): \App\Models\DlqRecord
    {
        $svc = app(\App\Services\DlqReviewService::class);
        $fingerprint = 'dlq-auth-'.Str::uuid();

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

    private function createSoakRun(?string $tenantId): \App\Models\ShadowSoakRun
    {
        return \App\Models\ShadowSoakRun::create([
            'run_id' => 'soak-auth-'.Str::uuid(),
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
