<?php

namespace Tests\Feature;

use App\Exceptions\TenantBoundaryViolationException;
use App\Models\AdvisoryFinding;
use App\Models\DlqRecord;
use App\Models\ShadowSoakRun;
use App\Models\User;
use App\Services\AdvisoryFindingService;
use App\Services\DlqReviewService;
use App\Services\TenantBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BACKLOG-TENANCY-019 — Tenant Isolation Hardening & Cross-Tenant Test Matrix
 *
 * Verifies:
 * - TenantBoundaryService constants and enforcement logic
 * - Cross-tenant access returns HTTP 403 for AdvisoryFindings, DLQ, ShadowSoak
 * - Same-tenant access is permitted
 * - Null tenant_id (legacy mode) is accessible from any tenant context
 * - No-header requests remain backward compatible (no scoping)
 * - Tenant-scoped service queries (getSummary, getFindings)
 * - Schema: tenant_id columns added to security_alerts and security_incidents
 * - PostgreSQL RLS is NOT enabled (documented posture)
 */
class TenantIsolationHardeningTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // TenantBoundaryService — constants and posture
    // =========================================================================

    public function test_rls_enabled_constant_is_false(): void
    {
        $this->assertFalse(TenantBoundaryService::RLS_ENABLED);
    }

    public function test_user_model_has_no_tenant_id(): void
    {
        $this->assertFalse(TenantBoundaryService::USER_MODEL_HAS_TENANT_ID);
    }

    public function test_isolated_tables_includes_advisory_findings(): void
    {
        $this->assertContains('advisory_findings', TenantBoundaryService::ISOLATED_TABLES);
    }

    public function test_isolated_tables_includes_dlq_records(): void
    {
        $this->assertContains('dlq_records', TenantBoundaryService::ISOLATED_TABLES);
    }

    public function test_isolated_tables_includes_shadow_soak_runs(): void
    {
        $this->assertContains('shadow_soak_runs', TenantBoundaryService::ISOLATED_TABLES);
    }

    public function test_isolated_tables_includes_security_alerts(): void
    {
        $this->assertContains('security_alerts', TenantBoundaryService::ISOLATED_TABLES);
    }

    public function test_isolated_tables_includes_security_incidents(): void
    {
        $this->assertContains('security_incidents', TenantBoundaryService::ISOLATED_TABLES);
    }

    // =========================================================================
    // TenantBoundaryService — assertAccess logic
    // =========================================================================

    public function test_assert_access_passes_when_tenants_match(): void
    {
        $svc = new TenantBoundaryService;
        $svc->assertAccess('tenant-A', 'tenant-A');
        $this->assertTrue(true);
    }

    public function test_assert_access_throws_on_tenant_mismatch(): void
    {
        $svc = new TenantBoundaryService;
        $this->expectException(TenantBoundaryViolationException::class);
        $svc->assertAccess('tenant-A', 'tenant-B');
    }

    public function test_assert_access_allows_null_record_tenant(): void
    {
        $svc = new TenantBoundaryService;
        $svc->assertAccess(null, 'tenant-B');
        $this->assertTrue(true);
    }

    public function test_assert_access_allows_null_request_tenant(): void
    {
        $svc = new TenantBoundaryService;
        $svc->assertAccess('tenant-A', null);
        $this->assertTrue(true);
    }

    public function test_assert_access_allows_both_null(): void
    {
        $svc = new TenantBoundaryService;
        $svc->assertAccess(null, null);
        $this->assertTrue(true);
    }

    public function test_can_access_returns_true_on_match(): void
    {
        $svc = new TenantBoundaryService;
        $this->assertTrue($svc->canAccess('tenant-A', 'tenant-A'));
    }

    public function test_can_access_returns_false_on_mismatch(): void
    {
        $svc = new TenantBoundaryService;
        $this->assertFalse($svc->canAccess('tenant-A', 'tenant-B'));
    }

    public function test_scope_query_adds_where_clause(): void
    {
        $svc = new TenantBoundaryService;
        $query = AdvisoryFinding::query();
        $scoped = $svc->scopeQuery($query, 'tenant-A');

        $sql = $scoped->toSql();
        $this->assertStringContainsString('tenant_id', $sql);
    }

    public function test_scope_query_is_noop_when_tenant_null(): void
    {
        $svc = new TenantBoundaryService;
        $baseline = AdvisoryFinding::query()->toSql();
        $scoped = $svc->scopeQuery(AdvisoryFinding::query(), null)->toSql();

        $this->assertSame($baseline, $scoped);
    }

    public function test_table_has_isolation_for_isolated_table(): void
    {
        $svc = new TenantBoundaryService;
        $this->assertTrue($svc->tableHasIsolation('advisory_findings'));
    }

    public function test_table_has_no_isolation_for_unisolated_table(): void
    {
        $svc = new TenantBoundaryService;
        $this->assertFalse($svc->tableHasIsolation('users'));
    }

    public function test_get_posture_summary_returns_expected_keys(): void
    {
        $svc = new TenantBoundaryService;
        $posture = $svc->getPostureSummary();

        $this->assertArrayHasKey('rls_enabled', $posture);
        $this->assertArrayHasKey('enforcement_layer', $posture);
        $this->assertFalse($posture['rls_enabled']);
        $this->assertSame('application', $posture['enforcement_layer']);
    }

    // =========================================================================
    // TenantBoundaryViolationException
    // =========================================================================

    public function test_exception_message_identifies_both_tenants(): void
    {
        $e = new TenantBoundaryViolationException('tenant-A', 'tenant-B');
        $this->assertStringContainsString('tenant-A', $e->getMessage());
        $this->assertStringContainsString('tenant-B', $e->getMessage());
    }

    public function test_exception_accepts_custom_message(): void
    {
        $e = new TenantBoundaryViolationException('tenant-A', 'tenant-B', 'custom error');
        $this->assertSame('custom error', $e->getMessage());
    }

    // =========================================================================
    // Schema — tenant_id columns added to security_alerts and security_incidents
    // =========================================================================

    public function test_security_alerts_has_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('security_alerts', 'tenant_id'));
    }

    public function test_security_incidents_has_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('security_incidents', 'tenant_id'));
    }

    // =========================================================================
    // AdvisoryFindingsController — cross-tenant denial matrix
    // =========================================================================

    public function test_advisory_show_returns_200_for_matching_tenant(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_advisory_show_returns_403_for_mismatched_tenant(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertForbidden();
    }

    public function test_advisory_show_allows_null_tenant_record_from_any_context(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload(null));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-X'])
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_advisory_show_allows_access_without_tenant_header(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/advisory/findings/'.$finding->finding_id)
            ->assertOk();
    }

    public function test_advisory_review_returns_403_for_mismatched_tenant(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->post('/advisory/findings/'.$finding->finding_id.'/review', ['action' => 'review'])
            ->assertForbidden();
    }

    public function test_advisory_review_succeeds_for_matching_tenant(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A'));
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->post('/advisory/findings/'.$finding->finding_id.'/review', ['action' => 'review'])
            ->assertRedirect('/advisory/findings/'.$finding->finding_id);
    }

    // =========================================================================
    // AdvisoryFindingService — tenant-scoped queries
    // =========================================================================

    public function test_advisory_get_findings_filters_by_tenant_id(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A', 'fp-ta'));
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-B', 'fp-tb'));

        $results = $svc->getFindings(['tenant_id' => 'tenant-A']);
        $this->assertSame(1, $results->total());
        $this->assertSame('tenant-A', $results->first()->tenant_id);
    }

    public function test_advisory_get_summary_scoped_to_tenant(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-A', 'fp-ts1'));
        $svc->upsertFromShadow($this->makeAdvisoryPayload('tenant-B', 'fp-ts2'));

        $summaryA = $svc->getSummary('tenant-A');
        $summaryB = $svc->getSummary('tenant-B');
        $summaryAll = $svc->getSummary();

        $this->assertSame(1, $summaryA['total']);
        $this->assertSame(1, $summaryB['total']);
        $this->assertSame(2, $summaryAll['total']);
    }

    // =========================================================================
    // DlqController — cross-tenant denial matrix
    // =========================================================================

    public function test_dlq_show_returns_200_for_matching_tenant(): void
    {
        $record = $this->createDlqRecord('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/dlq/records/'.$record->record_id)
            ->assertOk();
    }

    public function test_dlq_show_returns_403_for_mismatched_tenant(): void
    {
        $record = $this->createDlqRecord('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/dlq/records/'.$record->record_id)
            ->assertForbidden();
    }

    public function test_dlq_show_allows_null_tenant_record_from_any_context(): void
    {
        $record = $this->createDlqRecord(null);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-X'])
            ->get('/dlq/records/'.$record->record_id)
            ->assertOk();
    }

    public function test_dlq_show_allows_access_without_tenant_header(): void
    {
        $record = $this->createDlqRecord('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/dlq/records/'.$record->record_id)
            ->assertOk();
    }

    public function test_dlq_review_returns_403_for_mismatched_tenant(): void
    {
        $record = $this->createDlqRecord('tenant-A');
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->post('/dlq/records/'.$record->record_id.'/review', ['action' => 'reviewed'])
            ->assertForbidden();
    }

    public function test_dlq_review_succeeds_for_matching_tenant(): void
    {
        $record = $this->createDlqRecord('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->post('/dlq/records/'.$record->record_id.'/review', ['action' => 'reviewed'])
            ->assertRedirect('/dlq/records/'.$record->record_id);
    }

    // =========================================================================
    // DlqReviewService — tenant-scoped summary
    // =========================================================================

    public function test_dlq_get_summary_scoped_to_tenant(): void
    {
        $this->createDlqRecord('tenant-A');
        $this->createDlqRecord('tenant-B');

        $svc = app(DlqReviewService::class);
        $this->assertSame(1, $svc->getSummary('tenant-A')['total']);
        $this->assertSame(1, $svc->getSummary('tenant-B')['total']);
        $this->assertSame(2, $svc->getSummary()['total']);
    }

    // =========================================================================
    // ShadowSoakController — cross-tenant denial matrix
    // =========================================================================

    public function test_shadow_soak_show_returns_200_for_matching_tenant(): void
    {
        $run = $this->createSoakRun('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-A'])
            ->get('/shadow-soak/'.$run->run_id)
            ->assertOk();
    }

    public function test_shadow_soak_show_returns_403_for_mismatched_tenant(): void
    {
        $run = $this->createSoakRun('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-B'])
            ->get('/shadow-soak/'.$run->run_id)
            ->assertForbidden();
    }

    public function test_shadow_soak_show_allows_null_tenant_from_any_context(): void
    {
        $run = $this->createSoakRun(null);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-X'])
            ->get('/shadow-soak/'.$run->run_id)
            ->assertOk();
    }

    public function test_shadow_soak_show_allows_access_without_tenant_header(): void
    {
        $run = $this->createSoakRun('tenant-A');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/shadow-soak/'.$run->run_id)
            ->assertOk();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAdvisoryPayload(?string $tenantId, string $fingerprint = 'fp-tenant-test'): array
    {
        return [
            'finding_id' => Str::uuid()->toString(),
            'rule_id' => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain' => 'endpoint',
            'source_topic' => 'xdr.alerts.shadow.endpoint',
            'alert_type' => 'process_injection',
            'severity' => 'high',
            'confidence' => 0.80,
            'actor_key' => 'host-tenant-test',
            'tenant_id' => $tenantId,
            'evidence' => ['pid' => 1234],
            'source_event_ids' => ['evt-001'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function createDlqRecord(?string $tenantId): DlqRecord
    {
        $svc = app(DlqReviewService::class);
        $fingerprint = 'dlq-tenant-'.Str::uuid();

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

        return DlqRecord::where('fingerprint', $fingerprint)->firstOrFail();
    }

    private function createSoakRun(?string $tenantId): ShadowSoakRun
    {
        return ShadowSoakRun::create([
            'run_id' => 'soak-'.Str::uuid(),
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
