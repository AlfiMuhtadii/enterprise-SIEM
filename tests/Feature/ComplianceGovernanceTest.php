<?php

namespace Tests\Feature;

use App\Models\AuditExportAccessLog;
use App\Models\AuditExportRequest;
use App\Models\EvidenceIntegrityFailure;
use App\Models\EvidenceIntegrityRun;
use App\Models\GovernanceAccessReview;
use App\Models\GovernanceReviewFinding;
use App\Models\PiiAccessAudit;
use App\Models\RetentionPolicy;
use App\Models\RetentionPolicyVersion;
use App\Models\TenantIsolationValidationRun;
use App\Models\User;
use App\Services\ComplianceGovernanceService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Compliance / Governance / Evidence Integrity Phase 1 ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No destructive evidence deletion
 *   - No hidden audit mutation
 *   - No unrestricted export
 *   - No hidden tenant crossover
 *   - No hidden PII access
 *   - No autonomous compliance enforcement
 *   - All findings are advisory
 *   - Append-only tables enforce immutability
 *   - PII always logged when accessed
 *   - Export requester cannot self-approve
 */
class ComplianceGovernanceTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Traits\AssertsAdvisoryOnlyConstraints;

    private ComplianceGovernanceService $svc;
    private ThreatHuntingService        $hunting;
    private User                        $user;
    private User                        $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = app(ComplianceGovernanceService::class);
        $this->hunting  = app(ThreatHuntingService::class);
        $this->user     = User::factory()->create();
        $this->approver = User::factory()->create();
    }

    // =========================================================================
    // Schema ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â new tables exist
    // =========================================================================

    protected function getAdvisoryServiceClass(): string
    {
        return ComplianceGovernanceService::class;
    }

    public function test_evidence_integrity_runs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('evidence_integrity_runs'));
    }

    public function test_evidence_integrity_failures_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('evidence_integrity_failures'));
    }

    public function test_retention_policy_versions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('retention_policy_versions'));
    }

    public function test_audit_export_requests_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('audit_export_requests'));
    }

    public function test_audit_export_access_logs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('audit_export_access_logs'));
    }

    public function test_tenant_isolation_validation_runs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('tenant_isolation_validation_runs'));
    }

    public function test_pii_access_audit_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('pii_access_audit'));
    }

    public function test_governance_access_reviews_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('governance_access_reviews'));
    }

    public function test_governance_review_findings_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('governance_review_findings'));
    }

    // =========================================================================
    // Evidence integrity
    // =========================================================================

    public function test_run_integrity_check_creates_run_record(): void
    {
        $run = $this->svc->runIntegrityCheck('investigation', null, 'test-suite');

        $this->assertInstanceOf(EvidenceIntegrityRun::class, $run);
        $this->assertStringStartsWith('eir-', $run->run_id);
        $this->assertSame('investigation', $run->scope);
        $this->assertNotNull($run->integrity_hash);
        $this->assertNotNull($run->completed_at);
    }

    public function test_integrity_hash_is_deterministic(): void
    {
        $records = [['id' => 1, 'key' => 'a'], ['id' => 2, 'key' => 'b']];
        $h1 = EvidenceIntegrityRun::computeIntegrityHash($records);
        $h2 = EvidenceIntegrityRun::computeIntegrityHash($records);
        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1));
    }

    public function test_integrity_run_is_append_only(): void
    {
        $run = $this->svc->runIntegrityCheck('trace');

        $this->expectException(\LogicException::class);
        $run->status = 'failed';
        $run->save();
    }

    public function test_detection_rule_integrity_check_detects_missing_hashes(): void
    {
        // Insert a version record without a hash to trigger failure detection
        DB::table('detection_rule_versions')->insert([
            'version_id'  => 'drv-test-integrity',
            'rule_id'     => 'TEST_RULE',
            'version'     => '1.0',
            'rule_hash'   => '', // empty hash ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â should be flagged
            'status'      => 'active',
            'stage'       => 'draft',
            'shadow_only' => true,
            'created_at'  => now(),
        ]);

        $run = $this->svc->runIntegrityCheck('detection_rule', 'TEST_RULE');
        $this->assertGreaterThan(0, $run->integrity_failures);
    }

    public function test_integrity_failure_is_append_only(): void
    {
        $run = $this->svc->runIntegrityCheck('trace');
        // Manually create a failure to test
        $failure = EvidenceIntegrityFailure::create([
            'failure_id'    => 'eif-test-001',
            'run_id'        => $run->run_id,
            'evidence_type' => 'test',
            'evidence_id'   => 'ev-1',
            'failure_type'  => 'hash_mismatch',
            'created_at'    => now(),
        ]);

        $this->expectException(\LogicException::class);
        $failure->evidence_id = 'tampered';
        $failure->save();
    }

    public function test_passed_run_has_trace_continuity_ok(): void
    {
        $run = $this->svc->runIntegrityCheck('investigation');
        // With empty DB (RefreshDatabase), should pass with no failures
        if ($run->integrity_failures === 0 && $run->linkage_failures === 0) {
            $this->assertTrue($run->trace_continuity_ok);
        }
    }

    // =========================================================================
    // Retention policy versioning
    // =========================================================================

    public function test_snapshot_retention_policy_creates_version(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id'    => 'ret-test-001',
            'domain'       => 'endpoint_stream_events',
            'target_table' => 'endpoint_stream_events',
            'time_column'  => 'occurred_at',
            'retention_days'=> 90,
            'status'       => 'dry_run_only',
            'safe_archive_mode'     => true,
            'append_only_protected' => false,
        ]);

        $version = $this->svc->snapshotRetentionPolicy($policy, 'Initial governance snapshot');

        $this->assertInstanceOf(RetentionPolicyVersion::class, $version);
        $this->assertStringStartsWith('rpv-', $version->version_id);
        $this->assertSame('pending', $version->review_status);
        $this->assertNotNull($version->version_hash);
    }

    public function test_retention_version_hash_is_deterministic(): void
    {
        $snapshot = ['domain' => 'test', 'target_table' => 't', 'retention_days' => 30, 'max_rows' => null, 'status' => 'active'];
        $h1 = RetentionPolicyVersion::hashPolicy($snapshot);
        $h2 = RetentionPolicyVersion::hashPolicy($snapshot);
        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1));
    }

    public function test_review_retention_policy_version_updates_status(): void
    {
        $policy = RetentionPolicy::create([
            'policy_id' => 'ret-test-002', 'domain' => 'queue_lag_metrics',
            'target_table' => 'queue_lag_metrics', 'time_column' => 'created_at',
            'retention_days' => 30, 'status' => 'dry_run_only',
            'safe_archive_mode' => true, 'append_only_protected' => false,
        ]);
        $version = $this->svc->snapshotRetentionPolicy($policy);

        $this->svc->reviewRetentionPolicyVersion($version, 'approved', 'Looks good', $this->approver->id);
        $version->refresh();

        $this->assertSame('approved', $version->review_status);
        $this->assertNotNull($version->reviewed_at);
    }

    // =========================================================================
    // Audit export governance
    // =========================================================================

    public function test_create_export_request_creates_draft(): void
    {
        $req = $this->svc->createExportRequest(
            'investigation', 'json', scopeId: 'inv-001',
            exportReason: 'Annual audit review', requestedBy: $this->user->id
        );

        $this->assertInstanceOf(AuditExportRequest::class, $req);
        $this->assertStringStartsWith('aex-', $req->export_id);
        $this->assertSame('draft', $req->status);
        $this->assertTrue($req->pii_masked);
    }

    public function test_submit_export_transitions_to_pending(): void
    {
        $req = $this->svc->createExportRequest('trace', requestedBy: $this->user->id);
        $this->svc->submitExportForApproval($req);
        $req->refresh();

        $this->assertSame('pending_approval', $req->status);
    }

    public function test_export_requester_cannot_self_approve(): void
    {
        $req = $this->svc->createExportRequest('trace', requestedBy: $this->user->id);
        DB::table('audit_export_requests')->where('export_id', $req->export_id)->update(['requested_by' => $this->user->id]);
        $req->refresh();

        $this->expectException(\LogicException::class);
        $this->svc->approveExport($req, $this->user->id);
    }

    public function test_approve_export_sets_status_and_expiry(): void
    {
        $req = $this->svc->createExportRequest('incident', requestedBy: $this->user->id);
        $this->svc->approveExport($req, $this->approver->id);
        $req->refresh();

        $this->assertSame('approved', $req->status);
        $this->assertNotNull($req->approved_at);
        $this->assertNotNull($req->expires_at);
    }

    public function test_package_export_generates_checksum(): void
    {
        $req = $this->svc->createExportRequest('hunt_results', requestedBy: $this->user->id);
        $this->svc->approveExport($req, $this->approver->id);
        $this->svc->packageExport($req);
        $req->refresh();

        $this->assertSame('packaged', $req->status);
        $this->assertNotNull($req->integrity_checksum);
        $this->assertSame(64, strlen($req->integrity_checksum));
    }

    public function test_export_checksum_is_deterministic(): void
    {
        $req = $this->svc->createExportRequest('trace', requestedBy: $this->user->id);
        $this->svc->approveExport($req, $this->approver->id);
        $this->svc->packageExport($req);
        $req->refresh();

        // Re-compute checksum with same data
        $expected = hash('sha256', json_encode([
            'export_id'   => $req->export_id,
            'scope'       => $req->scope,
            'scope_id'    => $req->scope_id,
            'pii_masked'  => $req->pii_masked,
            'approved_by' => $req->approved_by,
            'approved_at' => $req->approved_at?->toIso8601String(),
        ]));

        $this->assertSame($expected, $req->integrity_checksum);
    }

    public function test_log_export_access_creates_append_only_record(): void
    {
        $req = $this->svc->createExportRequest('investigation');
        $log = $this->svc->logExportAccess($req->export_id, 'view', $this->user->id);

        $this->assertInstanceOf(AuditExportAccessLog::class, $log);
        $this->assertStringStartsWith('eal-', $log->log_id);
    }

    public function test_export_access_log_is_append_only(): void
    {
        $req = $this->svc->createExportRequest('investigation');
        $log = $this->svc->logExportAccess($req->export_id, 'view', $this->user->id);

        $this->expectException(\LogicException::class);
        $log->access_type = 'download';
        $log->save();
    }

    // =========================================================================
    // Tenant isolation validation
    // =========================================================================

    public function test_run_tenant_isolation_check_creates_run(): void
    {
        $run = $this->svc->runTenantIsolationCheck('org-abc', 'query_scope', 'test-suite');

        $this->assertInstanceOf(TenantIsolationValidationRun::class, $run);
        $this->assertStringStartsWith('tiv-', $run->run_id);
        $this->assertFalse($run->cross_tenant_detected);
    }

    public function test_tenant_isolation_run_is_append_only(): void
    {
        $run = $this->svc->runTenantIsolationCheck('org-test', 'evidence_bound');

        $this->expectException(\LogicException::class);
        $run->status = 'failed';
        $run->save();
    }

    public function test_unscoped_export_triggers_isolation_violation(): void
    {
        // Create an export without tenant_scope
        $this->svc->createExportRequest('investigation', tenantScope: null);

        $run = $this->svc->runTenantIsolationCheck('org-abc', 'export_scope');
        $this->assertGreaterThan(0, $run->violations_found);
        $this->assertSame('warning', $run->status);
    }

    // =========================================================================
    // PII access audit ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â append-only
    // =========================================================================

    public function test_log_pii_access_creates_audit_record(): void
    {
        $audit = $this->svc->logPiiAccess(
            'email', 'email', 'investigation',
            wasMasked: true,
            accessedByUser: 'analyst@soc.local',
            traceId: 'trace-001'
        );

        $this->assertInstanceOf(PiiAccessAudit::class, $audit);
        $this->assertStringStartsWith('pia-', $audit->audit_id);
        $this->assertTrue($audit->was_masked);
        $this->assertTrue($audit->masking_policy_applied);
    }

    public function test_unmasked_pii_access_is_recorded(): void
    {
        $audit = $this->svc->logPiiAccess('session_id', 'session_id', 'api_view', wasMasked: false);
        $this->assertFalse($audit->was_masked);
    }

    public function test_pii_access_audit_is_append_only(): void
    {
        $audit = $this->svc->logPiiAccess('email', 'email', 'export', wasMasked: true);

        $this->expectException(\LogicException::class);
        $audit->was_masked = false;
        $audit->save();
    }

    public function test_pii_access_audit_has_no_updated_at(): void
    {
        $audit = $this->svc->logPiiAccess('token', 'token', 'report', wasMasked: true);
        $row = DB::table('pii_access_audit')->where('id', $audit->id)->first();
        $this->assertNull($row->updated_at ?? null);
    }

    public function test_get_pii_access_history_returns_ordered_records(): void
    {
        $this->svc->logPiiAccess('email', 'email', 'investigation');
        $this->svc->logPiiAccess('email', 'email', 'export');

        $history = $this->svc->getPiiAccessHistory('email');
        $this->assertCount(2, $history);
    }

    // =========================================================================
    // Access review governance
    // =========================================================================

    public function test_open_access_review_creates_record(): void
    {
        $review = $this->svc->openAccessReview('admin@soc.local', 'admin', 30);

        $this->assertInstanceOf(GovernanceAccessReview::class, $review);
        $this->assertStringStartsWith('gar-', $review->review_id);
        $this->assertSame('open', $review->review_status);
        $this->assertFalse($review->is_stale);
    }

    public function test_stale_access_review_generates_finding(): void
    {
        $review = $this->svc->openAccessReview('privileged@soc.local', 'admin', 100);

        $this->assertTrue($review->is_stale);
        $this->assertGreaterThan(0, GovernanceReviewFinding::where('review_id', $review->review_id)->count());

        $finding = GovernanceReviewFinding::where('review_id', $review->review_id)->first();
        $this->assertSame('stale_privilege', $finding->finding_type);
        $this->assertTrue($finding->is_advisory);
    }

    public function test_close_access_review_sets_approved_status(): void
    {
        $review = $this->svc->openAccessReview('user@soc.local', 'analyst', 10);
        $this->svc->closeAccessReview($review, 'approved', $this->approver->id, 'Verified active SOC analyst');
        $review->refresh();

        $this->assertSame('approved', $review->review_status);
        $this->assertNotNull($review->reviewed_at);
    }

    public function test_close_access_review_revoked_status(): void
    {
        $review = $this->svc->openAccessReview('old-user@soc.local', 'admin', 200);
        $this->svc->closeAccessReview($review, 'revoked', $this->approver->id, 'Account no longer active');
        $review->refresh();

        $this->assertSame('revoked', $review->review_status);
    }

    public function test_add_finding_creates_advisory_record(): void
    {
        $finding = $this->svc->addFinding(
            null, 'policy_violation',
            'Export without PII masking detected',
            severity: 'warning',
            remediationRequired: true
        );

        $this->assertStringStartsWith('grf-', $finding->finding_id);
        $this->assertTrue($finding->is_advisory);
        $this->assertTrue($finding->remediation_required);
    }

    public function test_finding_is_append_only(): void
    {
        $finding = $this->svc->addFinding(null, 'excess_access', 'Test', 'informational');

        $this->expectException(\LogicException::class);
        $finding->severity = 'critical';
        $finding->save();
    }

    public function test_get_stale_reviews_returns_only_stale_open(): void
    {
        $this->svc->openAccessReview('stale@soc.local', 'admin', 120);   // stale
        $this->svc->openAccessReview('fresh@soc.local', 'analyst', 5);   // not stale

        $stale = $this->svc->getStaleReviews();
        $this->assertCount(1, $stale);
        $this->assertSame('stale@soc.local', $stale->first()->subject_user);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->svc->getDashboardStats();

        $this->assertArrayHasKey('total_integrity_runs', $stats);
        $this->assertArrayHasKey('failed_integrity_runs', $stats);
        $this->assertArrayHasKey('total_integrity_failures', $stats);
        $this->assertArrayHasKey('total_export_requests', $stats);
        $this->assertArrayHasKey('pending_exports', $stats);
        $this->assertArrayHasKey('total_pii_access_events', $stats);
        $this->assertArrayHasKey('unmasked_pii_accesses', $stats);
        $this->assertArrayHasKey('stale_reviews', $stats);
        $this->assertArrayHasKey('open_reviews', $stats);
        $this->assertArrayHasKey('total_review_findings', $stats);
        $this->assertArrayHasKey('critical_findings', $stats);
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_remediation']);
    }

    // =========================================================================
    // Threat hunting domain support
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(172, $this->hunting->supportedDomains());
    }

    public function test_hunt_evidence_integrity_runs_domain(): void
    {
        $this->svc->runIntegrityCheck('investigation');
        $results = $this->hunting->hunt('evidence_integrity_runs', ['scope' => 'investigation']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_audit_export_requests_domain(): void
    {
        $this->svc->createExportRequest('trace', requestedBy: $this->user->id);
        $results = $this->hunting->hunt('audit_export_requests', ['scope' => 'trace']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_tenant_isolation_validation_runs_domain(): void
    {
        $this->svc->runTenantIsolationCheck('org-hunt', 'query_scope');
        $results = $this->hunting->hunt('tenant_isolation_validation_runs', ['tenant_scope' => 'org-hunt']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_pii_access_audit_domain(): void
    {
        $this->svc->logPiiAccess('email', 'email', 'investigation');
        $results = $this->hunting->hunt('pii_access_audit', ['pii_category' => 'email']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_hunt_governance_access_reviews_domain(): void
    {
        $this->svc->openAccessReview('hunt-user@soc.local', 'analyst', 10);
        $results = $this->hunting->hunt('governance_access_reviews', ['privileged_role' => 'analyst']);
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_governance_dashboard_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.integrity-dashboard'))
            ->assertStatus(200);
    }

    public function test_governance_dashboard_contains_governance_notice(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.integrity-dashboard'))
            ->assertSee('audit-visible and replay-safe');
    }

    public function test_retention_governance_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.retention'))
            ->assertStatus(200);
    }

    public function test_export_workflow_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.export-workflow'))
            ->assertStatus(200);
    }

    public function test_tenant_isolation_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.tenant-isolation'))
            ->assertStatus(200);
    }

    public function test_pii_audit_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.pii-audit'))
            ->assertStatus(200);
    }

    public function test_access_review_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.access-review'))
            ->assertStatus(200);
    }

    public function test_compliance_report_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.compliance-report'))
            ->assertStatus(200);
    }

    public function test_integrity_failures_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.integrity-failures'))
            ->assertStatus(200);
    }

    public function test_findings_route_is_accessible(): void
    {
        $this->actingAs($this->user)
            ->get(route('governance.findings'))
            ->assertStatus(200);
    }

    // =========================================================================
    // Safety invariants
    // =========================================================================

    public function test_governance_service_has_no_isolate_host(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'isolateHost'));
    }

    public function test_governance_service_has_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'quarantineHost'));
    }

    public function test_governance_service_has_no_execute_shell(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'executeShell'));
    }

    public function test_governance_service_has_no_kill_process(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'killProcess'));
    }

    public function test_governance_service_has_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'autoRemediate'));
    }

    public function test_governance_service_has_no_delete_evidence(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'deleteEvidence'));
    }

    public function test_governance_service_has_no_purge_retention(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'purgeRetention'));
    }

    public function test_governance_service_has_no_bypass_export_approval(): void
    {
        $this->assertFalse(method_exists(ComplianceGovernanceService::class, 'bypassExportApproval'));
    }

    public function test_all_review_findings_are_advisory(): void
    {
        $review = $this->svc->openAccessReview('safety@soc.local', 'admin', 120);
        $findings = GovernanceReviewFinding::where('review_id', $review->review_id)->get();

        foreach ($findings as $f) {
            $this->assertTrue($f->is_advisory, "Finding {$f->finding_type} must be advisory");
        }
    }

    public function test_pii_masking_is_always_applied_by_default(): void
    {
        $audit = $this->svc->logPiiAccess('email', 'email', 'export');
        $this->assertTrue($audit->masking_policy_applied);
        $this->assertTrue($audit->was_masked);
    }

    public function test_export_pii_masked_is_true_by_default(): void
    {
        $req = $this->svc->createExportRequest('investigation');
        $this->assertTrue($req->pii_masked);
    }
}


