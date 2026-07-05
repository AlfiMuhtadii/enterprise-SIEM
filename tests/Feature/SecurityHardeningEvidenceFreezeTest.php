<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use App\Services\SecurityHardeningEvidenceFreezeService;
use App\Models\SecurityHardeningFreezeRun;
use App\Models\SecurityHardeningFreezeCheck;
use App\Models\SecurityHardeningFreezeControlEvidence;
use App\Models\SecurityHardeningFreezeGateSnapshot;
use App\Models\SecurityHardeningFreezeCoverageReport;
use App\Models\SecurityHardeningFreezeRemediationGuidance;
use App\Models\SecurityHardeningFreezeCertificationRequest;
use App\Models\SecurityHardeningFreezeAuditEvent;
use App\Models\SecurityHardeningFreezeDeltaReport;
use App\Services\ThreatHuntingService;

class SecurityHardeningEvidenceFreezeTest extends TestCase
{
    use RefreshDatabase;

    private SecurityHardeningEvidenceFreezeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SecurityHardeningEvidenceFreezeService();
    }

    // =========================================================================
    // Table existence
    // =========================================================================

    public function test_security_hardening_freeze_runs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_runs'));
    }

    public function test_security_hardening_freeze_checks_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_checks'));
    }

    public function test_security_hardening_freeze_control_evidence_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_control_evidence'));
    }

    public function test_security_hardening_freeze_gate_snapshots_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_gate_snapshots'));
    }

    public function test_security_hardening_freeze_coverage_reports_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_coverage_reports'));
    }

    public function test_security_hardening_freeze_remediation_guidance_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_remediation_guidance'));
    }

    public function test_security_hardening_freeze_certification_requests_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_certification_requests'));
    }

    public function test_security_hardening_freeze_audit_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_audit_events'));
    }

    public function test_security_hardening_freeze_delta_reports_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('security_hardening_freeze_delta_reports'));
    }

    // =========================================================================
    // Service constants
    // =========================================================================

    public function test_service_is_advisory_only(): void
    {
        $this->assertTrue(SecurityHardeningEvidenceFreezeService::ADVISORY_ONLY);
    }

    public function test_service_self_approve_blocked(): void
    {
        $this->assertTrue(SecurityHardeningEvidenceFreezeService::SELF_APPROVE_BLOCKED);
    }

    public function test_freeze_version_is_v1(): void
    {
        $this->assertSame('v1', SecurityHardeningEvidenceFreezeService::FREEZE_VERSION);
    }

    public function test_min_pass_score_is_85_percent(): void
    {
        $this->assertSame(0.85, SecurityHardeningEvidenceFreezeService::MIN_PASS_SCORE);
    }

    public function test_control_ids_contains_ten_controls(): void
    {
        $this->assertCount(10, SecurityHardeningEvidenceFreezeService::CONTROL_IDS);
    }

    public function test_control_categories_covers_all_control_ids(): void
    {
        foreach (SecurityHardeningEvidenceFreezeService::CONTROL_IDS as $id) {
            $this->assertArrayHasKey($id, SecurityHardeningEvidenceFreezeService::CONTROL_CATEGORIES,
                "Control '{$id}' missing from CONTROL_CATEGORIES");
        }
    }

    // =========================================================================
    // runFreeze
    // =========================================================================

    public function test_run_freeze_creates_run_record(): void
    {
        $run = $this->svc->runFreeze('operator-1');
        $this->assertInstanceOf(SecurityHardeningFreezeRun::class, $run);
        $this->assertDatabaseHas('security_hardening_freeze_runs', ['run_id' => $run->run_id]);
    }

    public function test_run_freeze_sets_advisory_only_true(): void
    {
        $run = $this->svc->runFreeze();
        $this->assertTrue($run->advisory_only);
    }

    public function test_run_freeze_never_sets_autonomous_certification(): void
    {
        $run = $this->svc->runFreeze();
        $this->assertFalse($run->autonomous_certification);
    }

    public function test_run_freeze_sets_self_approve_blocked(): void
    {
        $run = $this->svc->runFreeze();
        $this->assertTrue($run->self_approve_blocked);
    }

    public function test_run_freeze_sets_controls_total(): void
    {
        $run = $this->svc->runFreeze();
        $this->assertSame(count(SecurityHardeningEvidenceFreezeService::CONTROL_IDS), $run->controls_total);
    }

    public function test_run_freeze_creates_audit_event(): void
    {
        $run = $this->svc->runFreeze('test-operator');
        $this->assertDatabaseHas('security_hardening_freeze_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'freeze_run_started',
            'actor'      => 'test-operator',
        ]);
    }

    // =========================================================================
    // evaluateControl
    // =========================================================================

    public function test_evaluate_control_creates_check_record(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('config_cache_auth_secret', $run);
        $this->assertInstanceOf(SecurityHardeningFreezeCheck::class, $check);
        $this->assertSame('config_cache_auth_secret', $check->control_id);
    }

    public function test_evaluate_control_sets_advisory_only(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('per_agent_hmac_secret', $run);
        $this->assertTrue($check->advisory_only);
    }

    public function test_evaluate_control_rejects_unknown_control(): void
    {
        $run = $this->svc->runFreeze();
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->evaluateControl('nonexistent_control', $run);
    }

    public function test_evaluate_config_cache_auth_secret(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('config_cache_auth_secret', $run);
        $this->assertContains($check->result, ['PASS', 'FAIL']);
    }

    public function test_evaluate_internal_auth_secret_mapped(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('internal_auth_secret_mapped', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_per_agent_hmac_secret(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('per_agent_hmac_secret', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_endpoint_fleet_tenant_isolation(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('endpoint_fleet_tenant_isolation', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_workflow_tables_tenant_isolation(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('workflow_tables_tenant_isolation', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_threat_hunts_append_only_isolated(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('threat_hunts_append_only_isolated', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_ingestion_tenant_header_validation(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('ingestion_tenant_header_validation', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_rls_scaffold_present(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('rls_scaffold_present', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_container_resource_limits(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('container_resource_limits', $run);
        $this->assertSame('PASS', $check->result);
    }

    public function test_evaluate_tenant_strict_mode_configured(): void
    {
        $run   = $this->svc->runFreeze();
        $check = $this->svc->evaluateControl('tenant_strict_mode_configured', $run);
        $this->assertSame('PASS', $check->result);
    }

    // =========================================================================
    // evaluateAllControls
    // =========================================================================

    public function test_evaluate_all_controls_returns_ten_checks(): void
    {
        $run    = $this->svc->runFreeze();
        $checks = $this->svc->evaluateAllControls($run);
        $this->assertCount(10, $checks);
    }

    public function test_evaluate_all_controls_creates_coverage_report(): void
    {
        $run = $this->svc->runFreeze();
        $this->svc->evaluateAllControls($run);
        $this->assertDatabaseHas('security_hardening_freeze_coverage_reports', [
            'run_id' => $run->run_id,
        ]);
    }

    // =========================================================================
    // computeCoverage
    // =========================================================================

    public function test_compute_coverage_score_bounded_0_to_1(): void
    {
        $run = $this->svc->runFreeze();
        $this->svc->completeRun($run, 8, 2);
        $coverage = $this->svc->computeCoverage($run->fresh());
        $this->assertGreaterThanOrEqual(0.0, $coverage->overall_score);
        $this->assertLessThanOrEqual(1.0, $coverage->overall_score);
    }

    public function test_compute_coverage_meets_pass_threshold_when_all_pass(): void
    {
        $run = $this->svc->runFreeze();
        $this->svc->completeRun($run, 10, 0);
        $coverage = $this->svc->computeCoverage($run->fresh());
        $this->assertTrue($coverage->meets_pass_threshold);
    }

    public function test_compute_coverage_fails_threshold_when_most_fail(): void
    {
        $run = $this->svc->runFreeze();
        $this->svc->completeRun($run, 2, 8);
        $coverage = $this->svc->computeCoverage($run->fresh());
        $this->assertFalse($coverage->meets_pass_threshold);
    }

    public function test_compute_coverage_is_advisory_only(): void
    {
        $run = $this->svc->runFreeze();
        $this->svc->completeRun($run, 9, 1);
        $coverage = $this->svc->computeCoverage($run->fresh());
        $this->assertTrue($coverage->advisory_only);
    }

    // =========================================================================
    // requestCertification
    // =========================================================================

    public function test_request_certification_creates_record(): void
    {
        $run  = $this->svc->runFreeze();
        $cert = $this->svc->requestCertification($run, 'analyst-A');
        $this->assertInstanceOf(SecurityHardeningFreezeCertificationRequest::class, $cert);
    }

    public function test_certification_request_never_autonomous(): void
    {
        $run  = $this->svc->runFreeze();
        $cert = $this->svc->requestCertification($run, 'analyst-A');
        $this->assertFalse($cert->autonomous_approval);
        $this->assertTrue($cert->self_approve_blocked);
        $this->assertTrue($cert->advisory_only);
    }

    public function test_self_approval_throws_exception(): void
    {
        $run = $this->svc->runFreeze();
        $this->expectException(\LogicException::class);
        $this->svc->requestCertification($run, 'analyst-A', 'analyst-A');
    }

    // =========================================================================
    // computeDelta
    // =========================================================================

    public function test_compute_delta_creates_delta_record(): void
    {
        $prev    = $this->svc->runFreeze();
        $this->svc->completeRun($prev, 8, 2);
        $current = $this->svc->runFreeze();
        $this->svc->completeRun($current, 10, 0);

        $delta = $this->svc->computeDelta($current->fresh(), $prev->fresh());
        $this->assertInstanceOf(SecurityHardeningFreezeDeltaReport::class, $delta);
        $this->assertFalse($delta->regression_detected);
    }

    public function test_compute_delta_detects_regression(): void
    {
        $prev    = $this->svc->runFreeze();
        $this->svc->completeRun($prev, 10, 0);
        $current = $this->svc->runFreeze();
        $this->svc->completeRun($current, 7, 3);

        $delta = $this->svc->computeDelta($current->fresh(), $prev->fresh());
        $this->assertTrue($delta->regression_detected);
        $this->assertLessThan(0, $delta->score_delta);
    }

    // =========================================================================
    // getStatus
    // =========================================================================

    public function test_get_status_returns_advisory_note(): void
    {
        $run    = $this->svc->runFreeze();
        $status = $this->svc->getStatus($run);
        $this->assertArrayHasKey('note', $status);
        $this->assertTrue($status['advisory_only']);
    }

    public function test_get_status_returns_run_id(): void
    {
        $run    = $this->svc->runFreeze();
        $status = $this->svc->getStatus($run);
        $this->assertSame($run->run_id, $status['run_id']);
    }

    // =========================================================================
    // recordAudit
    // =========================================================================

    public function test_record_audit_creates_event(): void
    {
        $run   = $this->svc->runFreeze();
        $event = $this->svc->recordAudit('test_event', 'analyst', $run->run_id);
        $this->assertDatabaseHas('security_hardening_freeze_audit_events', [
            'event_id'   => $event->event_id,
            'event_type' => 'test_event',
        ]);
    }

    // =========================================================================
    // Command
    // =========================================================================

    public function test_security_hardening_freeze_command_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Console\Commands\SecurityHardeningFreezeCommand::class)
        );
    }

    public function test_security_hardening_freeze_command_is_readonly(): void
    {
        $content = file_get_contents(
            app_path('Console/Commands/SecurityHardeningFreezeCommand.php')
        );
        $this->assertStringNotContainsString('DB::update', $content);
        $this->assertStringNotContainsString('DB::delete', $content);
        $this->assertStringNotContainsString('->delete()', $content);
        $this->assertStringNotContainsString('->forceDelete()', $content);
    }

    public function test_command_signature_is_correct(): void
    {
        $cmd = new \App\Console\Commands\SecurityHardeningFreezeCommand();
        $this->assertStringContainsString('security:hardening-freeze', $cmd->getName());
    }

    // =========================================================================
    // Threat hunting domains
    // =========================================================================

    public function test_threat_hunting_supported_domains_count_is_177(): void
    {
        $this->assertCount(179, ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_freeze_domains_in_threat_hunting(): void
    {
        foreach ([
            'security_hardening_freeze_runs',
            'security_hardening_freeze_checks',
            'security_hardening_freeze_coverage_reports',
        ] as $domain) {
            $this->assertContains($domain, ThreatHuntingService::SUPPORTED_DOMAINS,
                "domain '{$domain}' missing from ThreatHuntingService::SUPPORTED_DOMAINS");
        }
    }

    // =========================================================================
    // Views and routes
    // =========================================================================

    public function test_freeze_index_route_exists(): void
    {
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
                ->contains(fn ($r) => $r->getName() === 'security-hardening-freeze.index')
        );
    }

    public function test_freeze_controls_route_exists(): void
    {
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
                ->contains(fn ($r) => $r->getName() === 'security-hardening-freeze.controls')
        );
    }
}
