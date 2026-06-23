<?php

namespace Tests\Feature;

use App\Models\ShadowSoakRun;
use App\Models\User;
use App\Services\AdvisoryFindingService;
use App\Services\DlqReviewService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BACKLOG-TENANCY-022 — Tenant-Scoped Index & Creation Safety
 *
 * Verifies:
 * - Strict mode: member-users must supply X-Tenant-ID on ALL routes (index + show + review + store)
 * - Strict mode: index routes return only the requesting tenant's records when header is supplied
 * - Strict mode: store routes assign tenant_id from the header (no null creation for member-users)
 * - Strict mode: admin bypass applies to all routes including index and store
 * - Legacy mode: index routes return unscoped results when no header is supplied (backward compat)
 * - Legacy mode: store routes create null tenant_id when no header is supplied (backward compat)
 * - Zero-membership users in strict mode are blocked on index/store (TenantContextMissing or Spoof)
 */
class TenantIndexCreationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextAuthority $authority;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authority = app(TenantContextAuthority::class);
    }

    // =========================================================================
    // Advisory index — strict mode
    // =========================================================================

    public function test_strict_advisory_index_member_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/advisory/findings')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_with_valid_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_scopes_to_requesting_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A', 'fp-022-a1'));
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-B', 'fp-022-b1'));

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings')
            ->assertOk();

        // The view receives scoped findings — tenant-B record not visible
        $response->assertDontSee('tenant-B');

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_admin_no_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/advisory/findings')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_zero_membership_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->get('/advisory/findings')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_advisory_index_zero_membership_with_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);

        // Zero memberships + header → TenantSpoofAttemptException
        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Advisory index — legacy mode
    // =========================================================================

    public function test_legacy_advisory_index_no_header_returns_200(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->get('/advisory/findings')
            ->assertOk();
    }

    public function test_legacy_advisory_index_zero_membership_no_header_returns_200(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        // Legacy: requireTenantContext has no effect — null returned, unscoped listing
        $this->actingAs($analyst)
            ->get('/advisory/findings')
            ->assertOk();
    }

    // =========================================================================
    // DLQ index — strict mode
    // =========================================================================

    public function test_strict_dlq_index_member_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/dlq/records')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_dlq_index_with_valid_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/dlq/records')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_dlq_index_scopes_to_requesting_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $svc = app(DlqReviewService::class);
        $svc->upsertFromConsumer($this->makeDlqPayload('tenant-A', 'fp-dlq-022-a'));
        $svc->upsertFromConsumer($this->makeDlqPayload('tenant-B', 'fp-dlq-022-b'));

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/dlq/records')
            ->assertOk();

        $response->assertDontSee('tenant-B');

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_dlq_index_admin_no_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/dlq/records')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // DLQ index — legacy mode
    // =========================================================================

    public function test_legacy_dlq_index_no_header_returns_200(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->get('/dlq/records')
            ->assertOk();
    }

    // =========================================================================
    // Shadow-soak index — strict mode
    // =========================================================================

    public function test_strict_shadow_soak_index_member_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->get('/shadow-soak')
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_shadow_soak_index_with_valid_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/shadow-soak')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_shadow_soak_index_scopes_to_requesting_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $analyst = User::factory()->create(['role' => 'analyst']);
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($analyst->id, 'tenant-A', $admin->id);

        $runA = $this->createSoakRun('tenant-A', 'soak-022-tenant-a');
        $runB = $this->createSoakRun('tenant-B', 'soak-022-tenant-b');

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/shadow-soak')
            ->assertOk();

        // Tenant-B run is not visible in scoped listing
        $response->assertDontSee($runB->run_id);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_shadow_soak_index_admin_no_header_returns_200(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/shadow-soak')
            ->assertOk();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Shadow-soak index — legacy mode
    // =========================================================================

    public function test_legacy_shadow_soak_index_no_header_returns_200(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->get('/shadow-soak')
            ->assertOk();
    }

    // =========================================================================
    // Shadow-soak store (creation safety) — strict mode
    // =========================================================================

    public function test_strict_store_member_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $engineer = User::factory()->create(['role' => 'detection_engineer']);
        $admin    = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($engineer->id, 'tenant-A', $admin->id);

        $this->actingAs($engineer)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_store_with_valid_header_assigns_tenant_id(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $engineer = User::factory()->create(['role' => 'detection_engineer']);
        $admin    = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($engineer->id, 'tenant-A', $admin->id);

        $this->actingAs($engineer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        // Run was created with the correct tenant_id
        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => 'tenant-A',
        ]);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_store_admin_no_header_creates_null_tenant(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        // Admin bypass: no header → null tenant_id (intentional unscoped run)
        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => null,
        ]);

        config(['xdr.tenancy.strict_mode' => false]);
    }

    public function test_strict_store_zero_membership_no_header_returns_403(): void
    {
        config(['xdr.tenancy.strict_mode' => true]);

        $engineer = User::factory()->create(['role' => 'detection_engineer']);

        $this->actingAs($engineer)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertForbidden();

        config(['xdr.tenancy.strict_mode' => false]);
    }

    // =========================================================================
    // Shadow-soak store — legacy mode (creation safety backward compat)
    // =========================================================================

    public function test_legacy_store_no_header_creates_null_tenant_id(): void
    {
        // Legacy mode: store without header creates null tenant_id (migration backward compat)
        $engineer = User::factory()->create(['role' => 'detection_engineer']);
        $admin    = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($engineer->id, 'tenant-A', $admin->id);

        $this->actingAs($engineer)
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        // Legacy: no header → null → record created with null tenant_id
        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => null,
        ]);
    }

    public function test_legacy_store_with_header_assigns_tenant_id(): void
    {
        // Legacy mode: if header is provided and valid, tenant_id is still set
        $engineer = User::factory()->create(['role' => 'detection_engineer']);
        $admin    = User::factory()->create(['role' => 'admin']);
        $this->authority->grantMembership($engineer->id, 'tenant-A', $admin->id);

        $this->actingAs($engineer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->post('/shadow-soak', ['domain' => 'endpoint', 'hours' => 1, 'dry_run' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('shadow_soak_runs', [
            'domain'    => 'endpoint',
            'dry_run'   => true,
            'tenant_id' => 'tenant-A',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAdvisoryPayload(?string $tenantId, string $fingerprint = 'fp-022-default'): array
    {
        return [
            'finding_id'       => Str::uuid()->toString(),
            'rule_id'          => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain'           => 'endpoint',
            'source_topic'     => 'xdr.alerts.shadow.endpoint',
            'alert_type'       => 'process_injection',
            'severity'         => 'high',
            'confidence'       => 0.80,
            'actor_key'        => 'host-022-test',
            'tenant_id'        => $tenantId,
            'evidence'         => ['pid' => 1234],
            'source_event_ids' => ['evt-022'],
            'fingerprint'      => $fingerprint,
        ];
    }

    private function makeDlqPayload(?string $tenantId, string $fingerprint = 'fp-dlq-022'): array
    {
        return [
            'fingerprint'      => $fingerprint,
            'dlq_event_type'   => 'normalization_failure',
            'source_topic'     => 'telemetry.raw',
            'raw_payload'      => '{"test":true}',
            'error_message'    => 'parse error',
            'occurrence_count' => 1,
            'tenant_id'        => $tenantId,
            'replayable'       => true,
            'error_reason'     => null,
        ];
    }

    private function createSoakRun(?string $tenantId, string $runId = null): ShadowSoakRun
    {
        return ShadowSoakRun::create([
            'run_id'        => $runId ?? 'soak-022-' . Str::uuid(),
            'domain'        => 'endpoint',
            'window_hours'  => 24,
            'status'        => 'completed',
            'dry_run'       => false,
            'started_at'    => now()->subHours(24),
            'completed_at'  => now(),
            'initiated_by'  => '1',
            'evidence_pass' => true,
            'gate_summary'  => ['pass_count' => 3, 'fail_count' => 0],
            'tenant_id'     => $tenantId,
        ]);
    }
}
