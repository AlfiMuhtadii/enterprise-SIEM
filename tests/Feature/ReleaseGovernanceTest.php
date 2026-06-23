<?php

namespace Tests\Feature;

use App\Models\DeploymentReadinessRun;
use App\Models\EnvironmentDriftReport;
use App\Models\GoNogoDecision;
use App\Models\OperationalRunbook;
use App\Models\OperationalRunbookVersion;
use App\Models\ReleaseApprovalRequest;
use App\Models\ReleaseAuditEvent;
use App\Models\ReleaseManifest;
use App\Models\RollbackValidationRun;
use App\Services\ReleaseGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class ReleaseGovernanceTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private ReleaseGovernanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ReleaseGovernanceService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return ReleaseGovernanceService::class;
    }

    // ─── Hard constraint: no forbidden methods ────────────────────────────────

    public function test_no_automatic_deployment_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'deploy'));
        $this->assertFalse(method_exists($this->svc, 'automaticDeploy'));
        $this->assertFalse(method_exists($this->svc, 'pushToProduction'));
    }

    public function test_no_destructive_rollback_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'executeRollback'));
        $this->assertFalse(method_exists($this->svc, 'forceRollback'));
        $this->assertFalse(method_exists($this->svc, 'destructiveRollback'));
    }

    public function test_no_hidden_environment_mutation_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'mutateEnvironment'));
        $this->assertFalse(method_exists($this->svc, 'reconcileEnvironment'));
        $this->assertFalse(method_exists($this->svc, 'autoReconcile'));
    }

    public function test_no_bypass_approval_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'bypassApproval'));
        $this->assertFalse(method_exists($this->svc, 'skipApproval'));
        $this->assertFalse(method_exists($this->svc, 'autoApprove'));
    }

    public function test_no_automatic_production_cutover_method(): void
    {
        $this->assertFalse(method_exists($this->svc, 'cutoverToProduction'));
        $this->assertFalse(method_exists($this->svc, 'promoteToProduction'));
    }

    // ─── Release Manifest ─────────────────────────────────────────────────────

    public function test_create_manifest_persists_record(): void
    {
        $manifest = $this->svc->createManifest(
            releaseVersion: 'v2.1.0',
            componentVersions: ['laravel' => '10.x', 'go-worker' => '1.4.2'],
            migrationVersions: ['800001', '900001'],
            schemaVersions: ['alerts' => '3', 'incidents' => '2'],
            createdBy: 'engineer-a',
        );

        $this->assertInstanceOf(ReleaseManifest::class, $manifest);
        $this->assertDatabaseHas('release_manifests', [
            'release_version' => 'v2.1.0',
            'status'          => 'draft',
            'created_by'      => 'engineer-a',
        ]);
        $this->assertNotEmpty($manifest->manifest_hash);
        $this->assertEquals(64, strlen($manifest->manifest_hash));
    }

    public function test_manifest_hash_is_deterministic(): void
    {
        $params = [
            'v2.1.0',
            ['laravel' => '10.x'],
            ['800001'],
            ['alerts' => '3'],
            'engineer-a',
        ];

        $m1 = $this->svc->createManifest(...$params);
        $m2 = $this->svc->createManifest(...$params);

        $this->assertEquals($m1->manifest_hash, $m2->manifest_hash);
    }

    public function test_manifest_is_append_only(): void
    {
        $manifest = $this->svc->createManifest('v1.0', [], [], [], 'eng');

        $this->expectException(\LogicException::class);
        $manifest->status = 'approved';
        $manifest->save();
    }

    public function test_manifest_auto_creates_audit_event(): void
    {
        $manifest = $this->svc->createManifest('v2.0', [], [], [], 'eng-x');

        $this->assertDatabaseHas('release_audit_events', [
            'release_id' => $manifest->release_id,
            'event_type' => 'manifest_created',
            'actor'      => 'eng-x',
        ]);
    }

    public function test_manifest_has_release_id_prefix(): void
    {
        $m = $this->svc->createManifest('v1.0', [], [], [], 'eng');
        $this->assertStringStartsWith('rel-', $m->release_id);
    }

    public function test_manifest_includes_rollback_reference(): void
    {
        $m = $this->svc->createManifest('v2.0', [], [], [], 'eng', '1.0.0', 'notes', 'rel-previous');
        $this->assertEquals('rel-previous', $m->rollback_reference);
    }

    // ─── Deployment Readiness ─────────────────────────────────────────────────

    public function test_readiness_run_go_verdict_when_all_pass(): void
    {
        $run = $this->svc->runDeploymentReadiness(
            triggeredBy: 'eng-b',
            migrationReady: true,
            schemaCompatible: true,
            replayValidatorHealthy: true,
            queueHealthy: true,
            storagePressureOk: true,
            degradedModeClear: true,
            workerHealthOk: true,
            rollbackReady: true,
        );

        $this->assertEquals(DeploymentReadinessRun::VERDICT_GO, $run->overall_verdict);
        $this->assertEquals(8, $run->validation_details['pass_count']);
        $this->assertEquals(8, $run->validation_details['total_checks']);
    }

    public function test_readiness_run_no_go_when_any_check_fails(): void
    {
        $run = $this->svc->runDeploymentReadiness(
            triggeredBy: 'eng-b',
            migrationReady: true,
            schemaCompatible: false,
        );

        $this->assertEquals(DeploymentReadinessRun::VERDICT_NO_GO, $run->overall_verdict);
    }

    public function test_readiness_run_is_append_only(): void
    {
        $run = $this->svc->runDeploymentReadiness('eng', migrationReady: true);

        $this->expectException(\LogicException::class);
        $run->overall_verdict = 'go';
        $run->save();
    }

    public function test_readiness_run_has_run_id_prefix(): void
    {
        $run = $this->svc->runDeploymentReadiness('eng');
        $this->assertStringStartsWith('drr-', $run->run_id);
    }

    public function test_readiness_run_creates_audit_event_when_release_id_provided(): void
    {
        $m = $this->svc->createManifest('v1.0', [], [], [], 'eng');
        $this->svc->runDeploymentReadiness('eng', releaseId: $m->release_id, migrationReady: true);

        $this->assertDatabaseHas('release_audit_events', [
            'release_id' => $m->release_id,
            'event_type' => 'readiness_run_completed',
        ]);
    }

    public function test_readiness_run_no_automatic_deployment(): void
    {
        $run = $this->svc->runDeploymentReadiness(
            'eng',
            migrationReady: true,
            schemaCompatible: true,
            replayValidatorHealthy: true,
            queueHealthy: true,
            storagePressureOk: true,
            degradedModeClear: true,
            workerHealthOk: true,
            rollbackReady: true,
        );

        $this->assertEquals('go', $run->overall_verdict);
        // Verdict GO does NOT trigger deployment — audit trail ends here
        $deployEvents = ReleaseAuditEvent::where('event_type', 'deployment_executed')->count();
        $this->assertEquals(0, $deployEvents);
    }

    // ─── Environment Drift ────────────────────────────────────────────────────

    public function test_detect_drift_persists_record(): void
    {
        $report = $this->svc->detectDrift(
            driftType: 'schema_mismatch',
            component: 'postgresql',
            detectedBy: 'eng-c',
            severity: EnvironmentDriftReport::SEVERITY_HIGH,
            isBlocking: true,
        );

        $this->assertDatabaseHas('environment_drift_reports', [
            'drift_type'  => 'schema_mismatch',
            'component'   => 'postgresql',
            'severity'    => 'high',
            'is_blocking' => true,
        ]);
    }

    public function test_detect_drift_rejects_unknown_drift_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->detectDrift('unknown_drift_type', 'some-component', 'eng');
    }

    public function test_drift_report_is_append_only(): void
    {
        $r = $this->svc->detectDrift('config_version', 'nginx', 'eng');

        $this->expectException(\LogicException::class);
        $r->severity = 'critical';
        $r->save();
    }

    public function test_drift_report_has_report_id_prefix(): void
    {
        $r = $this->svc->detectDrift('migration_drift', 'postgres', 'eng');
        $this->assertStringStartsWith('edr-', $r->report_id);
    }

    public function test_detect_drift_no_automatic_reconciliation(): void
    {
        // Blocking drift detected but no auto-reconcile happens
        $r = $this->svc->detectDrift('config_version', 'nginx', 'eng', isBlocking: true);
        $this->assertTrue($r->is_blocking);
        // No mutation recorded
        $this->assertEquals(0, ReleaseAuditEvent::where('event_type', 'environment_reconciled')->count());
    }

    public function test_all_drift_types_accepted(): void
    {
        foreach (ReleaseGovernanceService::DRIFT_TYPES as $type) {
            $r = $this->svc->detectDrift($type, 'comp-' . $type, 'eng');
            $this->assertEquals($type, $r->drift_type);
        }
    }

    // ─── Rollback Validation ──────────────────────────────────────────────────

    public function test_rollback_validation_ready_when_all_pass(): void
    {
        $run = $this->svc->runRollbackValidation(
            rollbackTargetVersion: 'v1.9.0',
            validatedBy: 'eng-d',
            migrationReversible: true,
            schemaRollbackSafe: true,
            replaySafe: true,
            dataIntegrityOk: true,
        );

        $this->assertEquals(RollbackValidationRun::VERDICT_READY, $run->verdict);
        $this->assertEquals(1.0, $run->readiness_score);
    }

    public function test_rollback_validation_partial_when_half_pass(): void
    {
        $run = $this->svc->runRollbackValidation(
            rollbackTargetVersion: 'v1.8.0',
            validatedBy: 'eng',
            migrationReversible: true,
            schemaRollbackSafe: true,
        );

        $this->assertEquals(RollbackValidationRun::VERDICT_PARTIAL, $run->verdict);
        $this->assertGreaterThan(0.0, $run->readiness_score);
        $this->assertLessThan(1.0, $run->readiness_score);
    }

    public function test_rollback_validation_not_ready_when_all_fail(): void
    {
        $run = $this->svc->runRollbackValidation('v1.0', 'eng');
        $this->assertEquals(RollbackValidationRun::VERDICT_NOT_READY, $run->verdict);
        $this->assertEquals(0.0, $run->readiness_score);
    }

    public function test_rollback_validation_is_append_only(): void
    {
        $run = $this->svc->runRollbackValidation('v1.0', 'eng');

        $this->expectException(\LogicException::class);
        $run->verdict = 'ready';
        $run->save();
    }

    public function test_rollback_run_has_run_id_prefix(): void
    {
        $run = $this->svc->runRollbackValidation('v1.0', 'eng');
        $this->assertStringStartsWith('rvr-', $run->run_id);
    }

    public function test_rollback_readiness_score_is_deterministic(): void
    {
        $args = ['v2.0', 'eng', '', true, true, false, false, []];
        $r1 = $this->svc->runRollbackValidation(...$args);
        $r2 = $this->svc->runRollbackValidation(...$args);
        $this->assertEquals($r1->readiness_score, $r2->readiness_score);
    }

    // ─── Go/No-Go Approval ───────────────────────────────────────────────────

    public function test_create_approval_request_persists(): void
    {
        $m = $this->svc->createManifest('v2.0', [], [], [], 'requester-x');
        $req = $this->svc->createApprovalRequest($m->release_id, 'requester-x');

        $this->assertDatabaseHas('release_approval_requests', [
            'release_id'   => $m->release_id,
            'requested_by' => 'requester-x',
            'status'       => 'pending',
        ]);
        $this->assertStringStartsWith('rar-', $req->request_id);
    }

    public function test_submit_gonogo_go_decision(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'requester-a');
        $req = $this->svc->createApprovalRequest($m->release_id, 'requester-a');
        $dec = $this->svc->submitGoNogo($req->request_id, 'approver-b', GoNogoDecision::DECISION_GO, 'All checks passed.');

        $this->assertEquals(GoNogoDecision::DECISION_GO, $dec->decision);
        $this->assertEquals('approver-b', $dec->decided_by);
    }

    public function test_creator_cannot_self_approve(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'same-person');
        $req = $this->svc->createApprovalRequest($m->release_id, 'same-person');

        $this->expectException(\LogicException::class);
        $this->svc->submitGoNogo($req->request_id, 'same-person', GoNogoDecision::DECISION_GO);
    }

    public function test_submit_gonogo_no_go_decision(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'requester-z');
        $req = $this->svc->createApprovalRequest($m->release_id, 'requester-z');
        $dec = $this->svc->submitGoNogo($req->request_id, 'approver-w', GoNogoDecision::DECISION_NO_GO, 'Drift detected.');

        $this->assertEquals(GoNogoDecision::DECISION_NO_GO, $dec->decision);
    }

    public function test_submit_gonogo_rejects_invalid_decision(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'req');
        $req = $this->svc->createApprovalRequest($m->release_id, 'req');

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->submitGoNogo($req->request_id, 'approver', 'maybe');
    }

    public function test_gonogo_decision_is_append_only(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'req');
        $req = $this->svc->createApprovalRequest($m->release_id, 'req');
        $dec = $this->svc->submitGoNogo($req->request_id, 'approver', GoNogoDecision::DECISION_GO);

        $this->expectException(\LogicException::class);
        $dec->decision = 'no_go';
        $dec->save();
    }

    public function test_gonogo_creates_audit_event(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'req');
        $req = $this->svc->createApprovalRequest($m->release_id, 'req');
        $this->svc->submitGoNogo($req->request_id, 'approver', GoNogoDecision::DECISION_GO);

        $this->assertDatabaseHas('release_audit_events', [
            'release_id' => $m->release_id,
            'event_type' => 'gonogo_decision_submitted',
            'actor'      => 'approver',
        ]);
    }

    public function test_approval_request_is_append_only(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'req');
        $req = $this->svc->createApprovalRequest($m->release_id, 'req');

        $this->expectException(\LogicException::class);
        $req->status = 'approved';
        $req->save();
    }

    public function test_no_hidden_deployment_after_go_decision(): void
    {
        $m   = $this->svc->createManifest('v2.0', [], [], [], 'req');
        $req = $this->svc->createApprovalRequest($m->release_id, 'req');
        $this->svc->submitGoNogo($req->request_id, 'approver', GoNogoDecision::DECISION_GO);

        // Go decision MUST NOT trigger automatic deployment
        $deployCount = ReleaseAuditEvent::where('event_type', 'deployment_executed')->count();
        $this->assertEquals(0, $deployCount);
    }

    // ─── Runbook Governance ───────────────────────────────────────────────────

    public function test_create_runbook_persists_record(): void
    {
        $rb = $this->svc->createRunbook(
            title: 'Deployment Runbook v1',
            runbookType: 'deployment',
            owner: 'platform-eng',
            initialContent: '# Deploy Steps\n1. Run preflight\n2. Get go decision\n3. Execute.',
            authoredBy: 'eng-lead',
        );

        $this->assertDatabaseHas('operational_runbooks', [
            'title'           => 'Deployment Runbook v1',
            'runbook_type'    => 'deployment',
            'current_version' => '1.0.0',
            'is_active'       => true,
        ]);
        $this->assertDatabaseHas('operational_runbook_versions', [
            'runbook_id'  => $rb->runbook_id,
            'version'     => '1.0.0',
            'authored_by' => 'eng-lead',
        ]);
    }

    public function test_runbook_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createRunbook('Bad Runbook', 'unknown_type', 'eng', 'content', 'author');
    }

    public function test_all_runbook_types_accepted(): void
    {
        foreach (ReleaseGovernanceService::RUNBOOK_TYPES as $type) {
            $rb = $this->svc->createRunbook("Runbook {$type}", $type, 'eng', 'content', 'author');
            $this->assertEquals($type, $rb->runbook_type);
        }
    }

    public function test_runbook_version_is_append_only(): void
    {
        $rb = $this->svc->createRunbook('RB', 'deployment', 'eng', 'content', 'author');
        $ver = $rb->versions()->first();

        $this->expectException(\LogicException::class);
        $ver->content = 'modified content';
        $ver->save();
    }

    public function test_runbook_version_has_deterministic_content_hash(): void
    {
        $content = 'Step 1: do thing. Step 2: validate.';
        $rb = $this->svc->createRunbook('RB', 'deployment', 'eng', $content, 'author');
        $ver = $rb->versions()->first();

        $expectedHash = hash('sha256', $content);
        $this->assertEquals($expectedHash, $ver->content_hash);
    }

    public function test_runbook_version_id_prefix(): void
    {
        $rb  = $this->svc->createRunbook('RB', 'rollback', 'eng', 'content', 'author');
        $ver = $rb->versions()->first();
        $this->assertStringStartsWith('rbv-', $ver->version_id);
    }

    public function test_add_new_runbook_version(): void
    {
        $rb = $this->svc->createRunbook('RB', 'deployment', 'eng', 'v1 content', 'author');
        $v2 = $this->svc->createRunbookVersion($rb->runbook_id, '2.0.0', 'v2 content', 'author-b');

        $this->assertEquals(2, $rb->versions()->count());
        $this->assertEquals('2.0.0', $v2->version);
    }

    // ─── Release Audit Integrity ──────────────────────────────────────────────

    public function test_audit_events_are_append_only(): void
    {
        $m = $this->svc->createManifest('v1.0', [], [], [], 'eng');
        $event = ReleaseAuditEvent::where('release_id', $m->release_id)->first();

        $this->expectException(\LogicException::class);
        $event->actor = 'attacker';
        $event->save();
    }

    public function test_audit_event_has_event_id_prefix(): void
    {
        $m = $this->svc->createManifest('v1.0', [], [], [], 'eng');
        $event = ReleaseAuditEvent::where('release_id', $m->release_id)->first();
        $this->assertStringStartsWith('rae-', $event->event_id);
    }

    // ─── Dashboard Stats ──────────────────────────────────────────────────────

    public function test_get_dashboard_stats_returns_advisory_flag(): void
    {
        $stats = $this->svc->getDashboardStats();
        $this->assertTrue($stats['advisory_only']);
    }

    public function test_get_dashboard_stats_all_keys_present(): void
    {
        $stats = $this->svc->getDashboardStats();
        $expected = [
            'total_manifests', 'draft_manifests', 'total_readiness_runs',
            'go_verdicts', 'no_go_verdicts', 'total_drift_reports',
            'blocking_drift_reports', 'total_rollback_runs', 'ready_rollbacks',
            'total_approval_requests', 'pending_approvals', 'total_gonogo_decisions',
            'go_decisions', 'no_go_decisions', 'total_audit_events',
            'total_runbooks', 'active_runbooks', 'total_runbook_versions',
            'advisory_only',
        ];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function test_dashboard_stats_count_accuracy(): void
    {
        $this->svc->createManifest('v1.0', [], [], [], 'eng');
        $this->svc->createManifest('v2.0', [], [], [], 'eng');

        $stats = $this->svc->getDashboardStats();
        $this->assertEquals(2, $stats['total_manifests']);
        $this->assertEquals(2, $stats['draft_manifests']);
    }

    // ─── Hunt domain integration ──────────────────────────────────────────────

    public function test_release_manifest_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('release_manifests', $hunting->supportedDomains());
    }

    public function test_deployment_readiness_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('deployment_readiness_runs', $hunting->supportedDomains());
    }

    public function test_environment_drift_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('environment_drift_reports', $hunting->supportedDomains());
    }

    public function test_rollback_validation_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('rollback_validation_runs', $hunting->supportedDomains());
    }

    public function test_gonogo_decisions_domain_supported(): void
    {
        $hunting = app(\App\Services\ThreatHuntingService::class);
        $this->assertContains('go_nogo_decisions', $hunting->supportedDomains());
    }

    // ─── View routes ──────────────────────────────────────────────────────────

    private function authUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }

    public function test_release_dashboard_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.dashboard'))->assertStatus(200);
    }

    public function test_release_manifests_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.manifests'))->assertStatus(200);
    }

    public function test_release_readiness_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.readiness'))->assertStatus(200);
    }

    public function test_release_drift_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.drift'))->assertStatus(200);
    }

    public function test_release_rollback_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.rollback'))->assertStatus(200);
    }

    public function test_release_gonogo_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.gonogo'))->assertStatus(200);
    }

    public function test_release_runbooks_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.runbooks'))->assertStatus(200);
    }

    public function test_release_audit_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.audit'))->assertStatus(200);
    }

    public function test_release_safety_route_accessible(): void
    {
        $this->actingAs($this->authUser())->get(route('release.safety'))->assertStatus(200);
    }

    public function test_views_contain_advisory_notice(): void
    {
        $user = $this->authUser();
        $this->actingAs($user)->get(route('release.dashboard'))
            ->assertSee('approval-gated and replay-safe');
    }
}
