<?php

namespace Tests\Feature;

use App\Models\DataErasureAuditEvent;
use App\Models\DataErasureRequest;
use App\Models\TenantRetentionPolicy;
use App\Models\User;
use App\Services\DataResidencyErasureService;
use App\Services\ThreatHuntingService;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataResidencyErasureTest extends TestCase
{
    use RefreshDatabase;

    private DataResidencyErasureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DataResidencyErasureService::class);
    }

    private function seedAlert(string $tenantId, ?\Illuminate\Support\Carbon $detectedAt = null): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-'.uniqid('', true),
            'detected_at' => $detectedAt ?? now(),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => $tenantId,
            'score' => 0.9,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIncident(string $tenantId, ?\Illuminate\Support\Carbon $createdAt = null): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => 'incident-'.uniqid('', true),
            'title' => 'Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'tenant_id' => $tenantId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => $createdAt ?? now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // Retention policy resolution
    // =========================================================================

    public function test_resolve_retention_days_returns_global_default_when_no_override(): void
    {
        $this->assertSame(90, $this->service->resolveRetentionDays('t1', 'alerts', 90));
    }

    public function test_resolve_retention_days_returns_tenant_override(): void
    {
        $this->service->setRetentionPolicy('t1', null, 45, null, 'admin@example.com');
        $this->assertSame(45, $this->service->resolveRetentionDays('t1', 'alerts', 90));
    }

    public function test_set_retention_policy_rejects_zero_days(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setRetentionPolicy('t1', null, 0, null, 'admin@example.com');
    }

    public function test_set_retention_policy_is_idempotent_upsert(): void
    {
        $this->service->setRetentionPolicy('t1', null, 45, null, 'admin@example.com');
        $this->service->setRetentionPolicy('t1', null, 60, null, 'admin@example.com');
        $this->assertSame(1, TenantRetentionPolicy::where('tenant_id', 't1')->count());
        $this->assertSame(60, TenantRetentionPolicy::where('tenant_id', 't1')->value('alerts_days'));
    }

    // =========================================================================
    // Erasure request lifecycle
    // =========================================================================

    public function test_request_erasure_creates_pending_request_and_audit_event(): void
    {
        $request = $this->service->requestErasure('t1', 'GDPR request from data subject', 'analyst@example.com');

        $this->assertSame(DataErasureRequest::STATUS_PENDING, $request->status);
        $this->assertTrue($request->dry_run);
        $this->assertDatabaseHas('data_erasure_audit_events', [
            'request_id' => $request->id,
            'event_type' => DataErasureAuditEvent::EVENT_REQUESTED,
        ]);
    }

    public function test_request_erasure_rejects_empty_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->requestErasure('t1', '   ', 'analyst@example.com');
    }

    public function test_approve_erasure_blocks_self_approval(): void
    {
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');
        $this->expectException(\RuntimeException::class);
        $this->service->approveErasure($request->id, 'analyst@example.com');
    }

    public function test_approve_erasure_succeeds_with_different_approver(): void
    {
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');
        $approved = $this->service->approveErasure($request->id, 'admin@example.com');

        $this->assertSame(DataErasureRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame('admin@example.com', $approved->approved_by);
        $this->assertDatabaseHas('data_erasure_audit_events', [
            'request_id' => $request->id,
            'event_type' => DataErasureAuditEvent::EVENT_APPROVED,
        ]);
    }

    public function test_approve_erasure_fails_when_not_pending(): void
    {
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');
        $this->service->approveErasure($request->id, 'admin@example.com');

        $this->expectException(\RuntimeException::class);
        $this->service->approveErasure($request->id, 'other-admin@example.com');
    }

    public function test_reject_erasure_transitions_status(): void
    {
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');
        $rejected = $this->service->rejectErasure($request->id, 'admin@example.com', 'not valid');

        $this->assertSame(DataErasureRequest::STATUS_REJECTED, $rejected->status);
        $this->assertDatabaseHas('data_erasure_audit_events', [
            'request_id' => $request->id,
            'event_type' => DataErasureAuditEvent::EVENT_REJECTED,
        ]);
    }

    // =========================================================================
    // Erasure execution
    // =========================================================================

    public function test_execute_dry_run_counts_without_deleting(): void
    {
        $this->seedAlert('t1');
        $this->seedIncident('t1');
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com', dryRun: true);

        $summary = $this->service->executeErasure($request->id, 'analyst@example.com');

        $this->assertSame(1, $summary['security_alerts']);
        $this->assertSame(1, $summary['security_incidents']);
        $this->assertDatabaseHas('security_alerts', ['tenant_id' => 't1']);
        $this->assertDatabaseHas('security_incidents', ['tenant_id' => 't1']);
    }

    public function test_execute_dry_run_does_not_require_approval(): void
    {
        $this->seedAlert('t1');
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com', dryRun: true);

        // Still pending — no approval — but dry-run execution must succeed.
        $summary = $this->service->executeErasure($request->id, 'analyst@example.com');
        $this->assertSame(1, $summary['security_alerts']);
    }

    public function test_execute_real_deletion_requires_approval(): void
    {
        $this->seedAlert('t1');
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com', dryRun: false);

        $this->expectException(\RuntimeException::class);
        $this->service->executeErasure($request->id, 'admin@example.com');
    }

    public function test_execute_real_deletion_after_approval_deletes_rows(): void
    {
        $this->seedAlert('t1');
        $this->seedIncident('t1');
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com', dryRun: false);
        $this->service->approveErasure($request->id, 'admin@example.com');

        $summary = $this->service->executeErasure($request->id, 'admin@example.com');

        $this->assertSame(1, $summary['security_alerts']);
        $this->assertSame(1, $summary['security_incidents']);
        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
        $this->assertDatabaseMissing('security_incidents', ['tenant_id' => 't1']);
        $this->assertSame(DataErasureRequest::STATUS_EXECUTED, $request->fresh()->status);
        $this->assertDatabaseHas('data_erasure_audit_events', [
            'request_id' => $request->id,
            'event_type' => DataErasureAuditEvent::EVENT_EXECUTED,
        ]);
    }

    public function test_execute_real_deletion_is_tenant_scoped(): void
    {
        $this->seedAlert('tenant-a');
        $this->seedAlert('tenant-b');
        $request = $this->service->requestErasure('tenant-a', 'reason', 'analyst@example.com', dryRun: false);
        $this->service->approveErasure($request->id, 'admin@example.com');

        $this->service->executeErasure($request->id, 'admin@example.com');

        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 'tenant-a']);
        $this->assertDatabaseHas('security_alerts', ['tenant_id' => 'tenant-b']);
    }

    // =========================================================================
    // SecurityRetentionCommand — tenant-aware pruning
    // =========================================================================

    public function test_retention_command_uses_tenant_override(): void
    {
        $this->seedAlert('t1', now()->subDays(50));
        $this->service->setRetentionPolicy('t1', null, 30, null, 'admin@example.com');

        $this->artisan('security:retention --alerts-days=90')->assertSuccessful();

        $this->assertDatabaseMissing('security_alerts', ['tenant_id' => 't1']);
    }

    public function test_retention_command_uses_global_default_without_override(): void
    {
        $this->seedAlert('t1', now()->subDays(50));

        $this->artisan('security:retention --alerts-days=90')->assertSuccessful();

        $this->assertDatabaseHas('security_alerts', ['tenant_id' => 't1']);
    }

    public function test_retention_command_prunes_incidents(): void
    {
        $this->seedIncident('t1', now()->subDays(200));

        $this->artisan('security:retention --incidents-days=180')->assertSuccessful();

        $this->assertDatabaseMissing('security_incidents', ['tenant_id' => 't1']);
    }

    public function test_retention_command_keeps_recent_incidents(): void
    {
        $this->seedIncident('t1', now()->subDays(10));

        $this->artisan('security:retention --incidents-days=180')->assertSuccessful();

        $this->assertDatabaseHas('security_incidents', ['tenant_id' => 't1']);
    }

    public function test_retention_command_prunes_legacy_null_tenant_rows_with_default(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'legacy-alert',
            'detected_at' => now()->subDays(100),
            'alert_type' => 'TEST',
            'detector_name' => 'TEST',
            'detector_version' => 'v1',
            'severity' => 'high',
            'tenant_id' => null,
            'score' => 0.9,
            'evidence' => json_encode([]),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('security:retention --alerts-days=90')->assertSuccessful();

        $this->assertDatabaseMissing('security_alerts', ['alert_id' => 'legacy-alert']);
    }

    // =========================================================================
    // DataErasureExecuteCommand
    // =========================================================================

    public function test_erasure_execute_command_reports_summary(): void
    {
        $this->seedAlert('t1');
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com', dryRun: true);

        $this->artisan("data-erasure:execute {$request->request_id}")
            ->expectsOutputToContain('security_alerts: 1 rows')
            ->assertSuccessful();
    }

    public function test_erasure_execute_command_fails_for_unknown_request(): void
    {
        $this->artisan('data-erasure:execute nonexistent-id')->assertFailed();
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    public function test_admin_has_all_erasure_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(Rbac::can($admin, 'retention.manage'));
        $this->assertTrue(Rbac::can($admin, 'erasure.request'));
        $this->assertTrue(Rbac::can($admin, 'erasure.approve'));
    }

    public function test_analyst_has_request_but_not_manage_permission(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->assertTrue(Rbac::can($analyst, 'erasure.request'));
        $this->assertFalse(Rbac::can($analyst, 'retention.manage'));
    }

    public function test_viewer_has_no_erasure_permissions(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertFalse(Rbac::can($viewer, 'erasure.request'));
        $this->assertFalse(Rbac::can($viewer, 'retention.manage'));
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_index_route_requires_auth(): void
    {
        $this->get('/data-residency')->assertRedirect('/login');
    }

    public function test_index_route_accessible_to_analyst(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->get('/data-residency')->assertOk();
    }

    public function test_index_route_forbidden_for_viewer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/data-residency')->assertForbidden();
    }

    public function test_analyst_cannot_approve(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');

        $this->actingAs($analyst)
            ->post(route('data-residency.erasure.approve', $request->id))
            ->assertForbidden();
    }

    public function test_admin_can_approve_via_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $request = $this->service->requestErasure('t1', 'reason', 'analyst@example.com');

        $this->actingAs($admin)
            ->post(route('data-residency.erasure.approve', $request->id))
            ->assertRedirect();

        $this->assertSame(DataErasureRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    // =========================================================================
    // ThreatHuntingService domain registration
    // =========================================================================

    public function test_hunt_domains_include_data_residency_tables(): void
    {
        $svc = app(ThreatHuntingService::class);
        $this->assertContains('tenant_retention_policies', $svc->supportedDomains());
        $this->assertContains('data_erasure_requests', $svc->supportedDomains());
    }

    public function test_total_hunt_domains_is_181(): void
    {
        $this->assertCount(181, app(ThreatHuntingService::class)->supportedDomains());
    }
}
