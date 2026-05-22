<?php

namespace Tests\Feature;

use App\Models\TenantIsolationAudit;
use App\Models\TenantContextPropagationRun;
use App\Models\TenantReplayValidationRun;
use App\Models\TenantGraphIsolationReport;
use App\Models\TenantExportValidationRun;
use App\Models\TenantNamespaceValidationReport;
use App\Models\TenantBoundaryViolationReport;
use App\Models\TenantReplayLineage;
use App\Models\TenantEvidenceIntegrityReport;
use App\Services\MultiTenantIsolationService;
use App\Services\ThreatHuntingService;
use App\Services\EntityRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private MultiTenantIsolationService $service;
    private string $tenantA = 'tenant-alpha';
    private string $tenantB = 'tenant-beta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MultiTenantIsolationService::class);
    }

    // =========================================================================
    // Hard constraint — no forbidden operations
    // =========================================================================

    public function test_no_isolate_host(): void
    {
        $this->assertFalse(method_exists($this->service, 'isolateHost'));
    }

    public function test_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists($this->service, 'quarantineHost'));
    }

    public function test_no_execute_shell(): void
    {
        $this->assertFalse(method_exists($this->service, 'executeShell'));
    }

    public function test_no_kill_process(): void
    {
        $this->assertFalse(method_exists($this->service, 'killProcess'));
    }

    public function test_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists($this->service, 'autoRemediate'));
    }

    public function test_no_cross_tenant_traversal(): void
    {
        $this->assertFalse(method_exists($this->service, 'crossTenantTraversal'));
    }

    public function test_no_unrestricted_export(): void
    {
        $this->assertFalse(method_exists($this->service, 'unrestrictedExport'));
    }

    public function test_no_tenant_bypass(): void
    {
        $this->assertFalse(method_exists($this->service, 'bypassTenant'));
    }

    public function test_no_tenant_impersonation(): void
    {
        $this->assertFalse(method_exists($this->service, 'impersonateTenant'));
    }

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(MultiTenantIsolationService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Isolation audit
    // =========================================================================

    public function test_isolation_audit_pass_when_no_failures(): void
    {
        $findings = [
            ['check' => 'query_scope', 'passed' => true],
            ['check' => 'graph_scope', 'passed' => true],
        ];
        $audit = $this->service->runIsolationAudit($this->tenantA, 'telemetry', $findings);

        $this->assertTrue($audit->isolation_ok);
        $this->assertSame('pass', $audit->verdict);
        $this->assertSame(2, $audit->checks_total);
        $this->assertSame(2, $audit->checks_passed);
        $this->assertSame(0, $audit->checks_failed);
        $this->assertTrue($audit->is_advisory);
    }

    public function test_isolation_audit_fail_when_all_fail(): void
    {
        $findings = [
            ['check' => 'query_scope', 'passed' => false],
            ['check' => 'graph_scope', 'passed' => false],
        ];
        $audit = $this->service->runIsolationAudit($this->tenantA, 'graph', $findings);

        $this->assertFalse($audit->isolation_ok);
        $this->assertSame('fail', $audit->verdict);
        $this->assertSame(2, $audit->checks_failed);
    }

    public function test_isolation_audit_partial_verdict(): void
    {
        $findings = [
            ['check' => 'query_scope', 'passed' => true],
            ['check' => 'graph_scope', 'passed' => false],
        ];
        $audit = $this->service->runIsolationAudit($this->tenantA, 'evidence', $findings);

        $this->assertSame('partial', $audit->verdict);
        $this->assertFalse($audit->isolation_ok);
    }

    public function test_isolation_audit_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runIsolationAudit($this->tenantA, 'invalid_scope');
    }

    public function test_isolation_audit_is_append_only(): void
    {
        $audit = $this->service->runIsolationAudit($this->tenantA, 'telemetry');
        $this->expectException(\LogicException::class);
        $audit->verdict = 'fail';
        $audit->save();
    }

    public function test_isolation_audit_id_prefix(): void
    {
        $audit = $this->service->runIsolationAudit($this->tenantA, 'namespace');
        $this->assertStringStartsWith('tia-', $audit->audit_id);
    }

    // =========================================================================
    // Context propagation
    // =========================================================================

    public function test_context_propagation_pass_when_all_hops_match(): void
    {
        $frames = [
            ['tenant_id' => $this->tenantA, 'service' => 'ingestion'],
            ['tenant_id' => $this->tenantA, 'service' => 'correlation'],
            ['tenant_id' => $this->tenantA, 'service' => 'alert-writer'],
        ];
        $run = $this->service->validateContextPropagation($this->tenantA, 'trace-001', $frames);

        $this->assertTrue($run->context_ok);
        $this->assertSame(3, $run->hops_total);
        $this->assertSame(3, $run->hops_validated);
        $this->assertSame(0, $run->attribution_failures);
        $this->assertNull($run->failure_reason);
        $this->assertTrue($run->is_advisory);
    }

    public function test_context_propagation_fail_on_mismatched_hop(): void
    {
        $frames = [
            ['tenant_id' => $this->tenantA, 'service' => 'ingestion'],
            ['tenant_id' => $this->tenantB, 'service' => 'correlation'], // wrong tenant
        ];
        $run = $this->service->validateContextPropagation($this->tenantA, 'trace-002', $frames);

        $this->assertFalse($run->context_ok);
        $this->assertSame(1, $run->attribution_failures);
        $this->assertNotNull($run->failure_reason);
    }

    public function test_context_propagation_is_append_only(): void
    {
        $run = $this->service->validateContextPropagation($this->tenantA, 'trace-003', []);
        $this->expectException(\LogicException::class);
        $run->context_ok = false;
        $run->save();
    }

    // =========================================================================
    // Replay validation
    // =========================================================================

    public function test_replay_validation_pass_clean_replay(): void
    {
        $run = $this->service->validateReplay($this->tenantA, 'replay-001', 500, 0, true);

        $this->assertTrue($run->replay_isolated);
        $this->assertSame('pass', $run->verdict);
        $this->assertSame(500, $run->events_replayed);
        $this->assertSame(0, $run->cross_tenant_detected);
        $this->assertTrue($run->is_advisory);
    }

    public function test_replay_validation_contaminated_verdict(): void
    {
        $run = $this->service->validateReplay($this->tenantA, 'replay-002', 500, 3);

        $this->assertFalse($run->replay_isolated);
        $this->assertSame('contaminated', $run->verdict);
        $this->assertSame(3, $run->cross_tenant_detected);
    }

    public function test_replay_validation_fail_on_non_deterministic(): void
    {
        $run = $this->service->validateReplay($this->tenantA, 'replay-003', 100, 0, false);

        $this->assertSame('fail', $run->verdict);
    }

    public function test_replay_validation_is_append_only(): void
    {
        $run = $this->service->validateReplay($this->tenantA, 'replay-004', 100);
        $this->expectException(\LogicException::class);
        $run->verdict = 'contaminated';
        $run->save();
    }

    // =========================================================================
    // Graph isolation
    // =========================================================================

    public function test_graph_isolation_pass_clean(): void
    {
        $report = $this->service->validateGraphIsolation($this->tenantA, 'graph-001', 50, 120);

        $this->assertTrue($report->isolation_ok);
        $this->assertSame('pass', $report->verdict);
        $this->assertSame(50, $report->nodes_validated);
        $this->assertSame(120, $report->edges_validated);
        $this->assertTrue($report->is_advisory);
    }

    public function test_graph_isolation_fail_on_cross_tenant_edges(): void
    {
        $report = $this->service->validateGraphIsolation($this->tenantA, 'graph-002', 50, 120, 2);

        $this->assertFalse($report->isolation_ok);
        $this->assertSame('fail', $report->verdict);
        $this->assertSame(2, $report->cross_tenant_edges_detected);
    }

    public function test_graph_isolation_rejects_excessive_depth(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->validateGraphIsolation($this->tenantA, 'graph-003', 50, 120, 0, 0, 21);
    }

    public function test_graph_isolation_bounded_at_max_depth(): void
    {
        $report = $this->service->validateGraphIsolation($this->tenantA, 'graph-004', 10, 20, 0, 0, 20);
        $this->assertSame(20, $report->traversal_depth);
    }

    public function test_graph_isolation_is_append_only(): void
    {
        $report = $this->service->validateGraphIsolation($this->tenantA, 'graph-005', 1, 1);
        $this->expectException(\LogicException::class);
        $report->isolation_ok = false;
        $report->save();
    }

    // =========================================================================
    // Export validation
    // =========================================================================

    public function test_export_validation_pass_with_valid_checksum(): void
    {
        $checksum = str_repeat('a', 64);
        $run = $this->service->validateExport($this->tenantA, 'exp-001', 'investigation', $checksum, true, 'analyst-1');

        $this->assertTrue($run->scope_ok);
        $this->assertTrue($run->integrity_ok);
        $this->assertSame('pass', $run->verdict);
        $this->assertTrue($run->approved);
        $this->assertTrue($run->is_advisory);
    }

    public function test_export_validation_fail_with_invalid_checksum(): void
    {
        $run = $this->service->validateExport($this->tenantA, 'exp-002', 'hunt', 'tooshort', false);

        $this->assertFalse($run->integrity_ok);
        $this->assertSame('fail', $run->verdict);
    }

    public function test_export_validation_expired_verdict(): void
    {
        $run = $this->service->validateExport(
            $this->tenantA, 'exp-003', 'evidence',
            str_repeat('b', 64), false, null,
            \Carbon\Carbon::now()->subHour()
        );
        $this->assertSame('expired', $run->verdict);
    }

    public function test_export_validation_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateExport($this->tenantA, 'exp-004', 'invalid_scope', str_repeat('c', 64));
    }

    public function test_export_validation_is_append_only(): void
    {
        $run = $this->service->validateExport($this->tenantA, 'exp-005', 'alerts', str_repeat('d', 64));
        $this->expectException(\LogicException::class);
        $run->verdict = 'fail';
        $run->save();
    }

    // =========================================================================
    // Namespace validation
    // =========================================================================

    public function test_namespace_validation_pass_when_all_owned(): void
    {
        $namespaces = [
            ['type' => 'cache', 'key' => "cache:{$this->tenantA}:alerts", 'owner' => $this->tenantA],
            ['type' => 'queue', 'key' => "queue:{$this->tenantA}:events", 'owner' => $this->tenantA],
        ];
        $report = $this->service->validateNamespaces($this->tenantA, $namespaces);

        $this->assertFalse($report->crossover_detected);
        $this->assertSame(2, $report->namespaces_valid);
        $this->assertSame(0, $report->namespaces_invalid);
        $this->assertTrue($report->is_advisory);
    }

    public function test_namespace_validation_detects_crossover(): void
    {
        $namespaces = [
            ['type' => 'cache', 'key' => "cache:{$this->tenantA}:alerts", 'owner' => $this->tenantA],
            ['type' => 'cache', 'key' => "cache:{$this->tenantA}:shared", 'owner' => $this->tenantB], // crossover
        ];
        $report = $this->service->validateNamespaces($this->tenantA, $namespaces);

        $this->assertTrue($report->crossover_detected);
        $this->assertSame(1, $report->namespaces_valid);
        $this->assertSame(1, $report->namespaces_invalid);
    }

    public function test_namespace_validation_is_append_only(): void
    {
        $report = $this->service->validateNamespaces($this->tenantA, []);
        $this->expectException(\LogicException::class);
        $report->crossover_detected = true;
        $report->save();
    }

    // =========================================================================
    // Boundary violation
    // =========================================================================

    public function test_boundary_violation_records_correctly(): void
    {
        $report = $this->service->recordBoundaryViolation(
            $this->tenantA,
            'graph_crossover',
            'Cross-tenant edge detected during investigation pivot',
            'high',
            $this->tenantB,
            $this->tenantA,
            ['evidence_id' => 'ev-001']
        );

        $this->assertSame('graph_crossover', $report->violation_type);
        $this->assertSame('high', $report->severity);
        $this->assertSame($this->tenantB, $report->source_tenant_id);
        $this->assertTrue($report->is_advisory);
    }

    public function test_boundary_violation_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordBoundaryViolation($this->tenantA, 'invalid_type', 'test');
    }

    public function test_boundary_violation_rejects_invalid_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordBoundaryViolation($this->tenantA, 'graph_crossover', 'test', 'ultra');
    }

    public function test_boundary_violation_is_append_only(): void
    {
        $report = $this->service->recordBoundaryViolation($this->tenantA, 'export_leakage', 'desc');
        $this->expectException(\LogicException::class);
        $report->severity = 'critical';
        $report->save();
    }

    public function test_boundary_violation_id_prefix(): void
    {
        $report = $this->service->recordBoundaryViolation($this->tenantA, 'namespace_crossover', 'test');
        $this->assertStringStartsWith('tbv-', $report->report_id);
    }

    // =========================================================================
    // Replay lineage
    // =========================================================================

    public function test_replay_lineage_clean_when_same_origin(): void
    {
        $lineage = $this->service->trackReplayLineage($this->tenantA, 'rp-001', $this->tenantA, 0);

        $this->assertTrue($lineage->lineage_clean);
        $this->assertSame(0, $lineage->replay_depth);
        $this->assertTrue($lineage->is_advisory);
    }

    public function test_replay_lineage_not_clean_when_origin_differs(): void
    {
        $lineage = $this->service->trackReplayLineage($this->tenantA, 'rp-002', $this->tenantB, 1);

        $this->assertFalse($lineage->lineage_clean);
        $this->assertSame($this->tenantB, $lineage->origin_tenant_id);
    }

    public function test_replay_lineage_bounded_depth(): void
    {
        $this->expectException(\OverflowException::class);
        $this->service->trackReplayLineage($this->tenantA, 'rp-003', $this->tenantA, 11);
    }

    public function test_replay_lineage_is_append_only(): void
    {
        $lineage = $this->service->trackReplayLineage($this->tenantA, 'rp-004', $this->tenantA);
        $this->expectException(\LogicException::class);
        $lineage->lineage_clean = false;
        $lineage->save();
    }

    // =========================================================================
    // Evidence integrity
    // =========================================================================

    public function test_evidence_integrity_pass_all_valid(): void
    {
        $refs = [
            ['tenant_id' => $this->tenantA, 'hash' => str_repeat('a', 64), 'ref_id' => 'ev-001'],
            ['tenant_id' => $this->tenantA, 'hash' => str_repeat('b', 64), 'ref_id' => 'ev-002'],
        ];
        $report = $this->service->validateEvidenceIntegrity($this->tenantA, $refs);

        $this->assertSame('pass', $report->verdict);
        $this->assertSame(2, $report->evidence_refs_checked);
        $this->assertSame(2, $report->integrity_ok);
        $this->assertSame(0, $report->integrity_failed);
        $this->assertSame(0, $report->cross_tenant_refs);
        $this->assertTrue($report->is_advisory);
    }

    public function test_evidence_integrity_fail_on_cross_tenant(): void
    {
        $refs = [
            ['tenant_id' => $this->tenantA, 'hash' => str_repeat('a', 64)],
            ['tenant_id' => $this->tenantB, 'hash' => str_repeat('b', 64)], // cross-tenant
        ];
        $report = $this->service->validateEvidenceIntegrity($this->tenantA, $refs);

        $this->assertSame('fail', $report->verdict);
        $this->assertSame(1, $report->cross_tenant_refs);
    }

    public function test_evidence_integrity_partial_on_missing_hash(): void
    {
        $refs = [
            ['tenant_id' => $this->tenantA, 'hash' => str_repeat('a', 64)],
            ['tenant_id' => $this->tenantA, 'hash' => ''],  // missing hash
        ];
        $report = $this->service->validateEvidenceIntegrity($this->tenantA, $refs);

        $this->assertSame('partial', $report->verdict);
        $this->assertSame(1, $report->integrity_failed);
    }

    public function test_evidence_integrity_is_append_only(): void
    {
        $report = $this->service->validateEvidenceIntegrity($this->tenantA, []);
        $this->expectException(\LogicException::class);
        $report->verdict = 'fail';
        $report->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_has_all_keys(): void
    {
        $stats = $this->service->dashboardStats();

        $this->assertArrayHasKey('total_isolation_audits', $stats);
        $this->assertArrayHasKey('isolation_failures', $stats);
        $this->assertArrayHasKey('boundary_violations', $stats);
        $this->assertArrayHasKey('critical_violations', $stats);
        $this->assertArrayHasKey('replay_contaminations', $stats);
        $this->assertArrayHasKey('graph_isolation_failures', $stats);
        $this->assertArrayHasKey('export_validation_runs', $stats);
        $this->assertArrayHasKey('export_failures', $stats);
        $this->assertArrayHasKey('namespace_crossovers', $stats);
        $this->assertArrayHasKey('evidence_integrity_failures', $stats);
        $this->assertArrayHasKey('context_propagation_failures', $stats);
        $this->assertArrayHasKey('replay_lineage_contaminated', $stats);
    }

    // =========================================================================
    // Threat hunting domain integration
    // =========================================================================

    public function test_tenant_isolation_audits_domain_supported(): void
    {
        $this->assertContains('tenant_isolation_audits', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_tenant_replay_validation_runs_domain_supported(): void
    {
        $this->assertContains('tenant_replay_validation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_tenant_graph_isolation_reports_domain_supported(): void
    {
        $this->assertContains('tenant_graph_isolation_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_tenant_boundary_violation_reports_domain_supported(): void
    {
        $this->assertContains('tenant_boundary_violation_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_tenant_evidence_integrity_reports_domain_supported(): void
    {
        $this->assertContains('tenant_evidence_integrity_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_total_hunt_domains_is_80(): void
    {
        $this->assertCount(110, app(ThreatHuntingService::class)->supportedDomains());
    }

    // =========================================================================
    // Entity risk factors
    // =========================================================================

    public function test_tenant_risk_factors_in_weight_table(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertArrayHasKey('tenant_isolation_failure_factor', $weights);
        $this->assertArrayHasKey('tenant_boundary_violation_factor', $weights);
        $this->assertArrayHasKey('tenant_context_propagation_factor', $weights);
        $this->assertArrayHasKey('tenant_evidence_integrity_factor', $weights);
    }

    public function test_tenant_risk_factors_are_not_zero(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertGreaterThan(0, $weights['tenant_isolation_failure_factor']);
        $this->assertGreaterThan(0, $weights['tenant_boundary_violation_factor']);
        $this->assertGreaterThan(0, $weights['tenant_context_propagation_factor']);
        $this->assertGreaterThan(0, $weights['tenant_evidence_integrity_factor']);
    }

    // =========================================================================
    // Route accessibility
    // =========================================================================

    public function test_isolation_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/multi-tenant')->assertStatus(200);
    }

    public function test_boundary_violations_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/multi-tenant/violations')->assertStatus(200);
    }

    public function test_governance_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/multi-tenant/governance')->assertStatus(200);
    }

    public function test_views_contain_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/multi-tenant')
            ->assertSee('Tenant governance workflows are replay-safe and isolation-enforced');
    }
}
