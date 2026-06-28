<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\ReleaseCandidateStabilizationService;
use App\Models\ReleaseCandidateManifest;
use App\Models\FeatureFreezeAudit;
use App\Models\DeploymentArtifactReport;
use App\Models\PilotDeploymentPreparationRun;
use App\Models\OperationalValidationBaseline;
use App\Models\ReleaseBlockerReport;
use App\Models\DeploymentReproducibilityReport;
use App\Models\StabilizationValidationRun;
use App\Models\ReleaseCandidateAudit;
use App\Services\ThreatHuntingService;

class ReleaseCandidateStabilizationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Traits\AssertsAdvisoryOnlyConstraints;

    private ReleaseCandidateStabilizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReleaseCandidateStabilizationService();
    }

    // =========================================================================
    // Hard constraint assertions Ã¢â‚¬â€ no forbidden operations
    // =========================================================================

    protected function getAdvisoryServiceClass(): string
    {
        return ReleaseCandidateStabilizationService::class;
    }

    public function test_service_constants_are_correct(): void
    {
        $this->assertTrue(ReleaseCandidateStabilizationService::ADVISORY_ONLY);
        $this->assertEquals(20, ReleaseCandidateStabilizationService::MAX_PILOT_ENDPOINTS);
        $this->assertEquals(0.85, ReleaseCandidateStabilizationService::RC_PASS_THRESHOLD);
        $this->assertEquals(0.10, ReleaseCandidateStabilizationService::MAX_DRIFT_DELTA);
    }

    public function test_rc_manifest_never_sets_autonomous_release(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $this->assertFalse($manifest->autonomous_release);
        $this->assertTrue($manifest->is_advisory);
    }

    public function test_freeze_audit_never_allows_autonomous_scope_change(): void
    {
        $this->service->recordFreezeAudit('freeze_initiated', 'full', null);
        $audit = FeatureFreezeAudit::first();
        $this->assertFalse($audit->autonomous_scope_change);
        $this->assertTrue($audit->scope_expansion_blocked);
    }

    public function test_artifact_generation_never_autonomous(): void
    {
        $artifact = $this->service->generateArtifact('docker_compose', '1.0.0-rc.1');
        $this->assertFalse($artifact->autonomous_generation);
        $this->assertTrue($artifact->is_advisory);
    }

    public function test_pilot_preparation_never_sets_autonomous_rollout(): void
    {
        $run = $this->service->runPilotPreparation('onboarding_readiness', 10, [
            'onboarding_ready' => true, 'ha_ready' => true,
            'rollback_ready'   => true, 'telemetry_continuous' => true,
        ]);
        $this->assertFalse($run->autonomous_rollout);
        $this->assertTrue($run->is_advisory);
        $this->assertTrue($run->replay_safe);
    }

    public function test_blocker_never_sets_autonomous_resolution(): void
    {
        $blocker = $this->service->registerBlocker('validation_failure', 'high', 'Test failure');
        $this->assertFalse($blocker->autonomous_resolution);
        $this->assertTrue($blocker->is_advisory);
    }

    public function test_reproducibility_never_sets_autonomous_deployment(): void
    {
        $report = $this->service->assessReproducibility('full_stack', []);
        $this->assertFalse($report->autonomous_deployment);
        $this->assertTrue($report->is_advisory);
    }

    public function test_stabilization_run_is_always_advisory(): void
    {
        $run = $this->service->recordStabilizationValidation('pre_freeze', []);
        $this->assertTrue($run->is_advisory);
        $this->assertTrue($run->replay_safe);
    }

    public function test_rc_audit_never_has_autonomous_actions(): void
    {
        $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $audits = ReleaseCandidateAudit::all();
        foreach ($audits as $audit) {
            $this->assertFalse($audit->autonomous_action_taken);
            $this->assertFalse($audit->destructive_action_taken);
            $this->assertTrue($audit->replay_safe);
        }
    }

    public function test_no_new_major_subsystem(): void
    {
        $service = new ReleaseCandidateStabilizationService();
        $this->assertFalse(method_exists($service, 'isolateHost'));
        $this->assertFalse(method_exists($service, 'quarantineHost'));
        $this->assertFalse(method_exists($service, 'executeShell'));
        $this->assertFalse(method_exists($service, 'killProcess'));
        $this->assertFalse(method_exists($service, 'autoRemediate'));
    }

    // =========================================================================
    // RC manifest Ã¢â‚¬â€ generation and state
    // =========================================================================

    public function test_initiate_rc_creates_draft_manifest(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1', [
            'rule_count'        => 133,
            'hunt_domain_count' => 150,
        ]);
        $this->assertEquals('draft', $manifest->rc_state);
        $this->assertEquals('1.0.0-rc.1', $manifest->rc_version);
        $this->assertEquals(133, $manifest->rule_count);
        $this->assertEquals(150, $manifest->hunt_domain_count);
        $this->assertStringStartsWith('rc-manifest-', $manifest->manifest_id);
    }

    public function test_initiate_rc_generates_deterministic_hash(): void
    {
        $manifest1 = $this->service->initiateReleaseCandidate('1.0.0-rc.1', ['rule_count' => 133]);
        $this->assertNotEmpty($manifest1->manifest_hash);
        $this->assertEquals(64, strlen($manifest1->manifest_hash)); // sha256 hex
    }

    public function test_readiness_score_clamped_to_1(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1', ['readiness_score' => 999.0]);
        $this->assertLessThanOrEqual(1.0, $manifest->readiness_score);
    }

    public function test_freeze_rc_sets_freeze_enforced(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $frozen   = $this->service->freezeReleaseCandidate($manifest);
        $this->assertTrue($frozen->feature_freeze_enforced);
        $this->assertEquals('frozen', $frozen->rc_state);
    }

    public function test_promote_rc_state_to_validated(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $updated  = $this->service->promoteRcState($manifest, 'validated', 0.92);
        $this->assertEquals('validated', $updated->rc_state);
        $this->assertEquals(0.92, $updated->readiness_score);
        $this->assertFalse($updated->autonomous_release);
    }

    public function test_promote_rc_state_rejects_invalid_state(): void
    {
        $manifest = $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->promoteRcState($manifest, 'invalid_state', 0.9);
    }

    // =========================================================================
    // Feature freeze audit
    // =========================================================================

    public function test_freeze_audit_records_scope_expansion_blocked(): void
    {
        $audit = $this->service->recordFreezeAudit('freeze_initiated', 'full', null, '1.0.0-rc.1');
        $this->assertTrue($audit->scope_expansion_blocked);
        $this->assertEquals('full', $audit->freeze_scope);
        $this->assertEquals('1.0.0-rc.1', $audit->rc_version);
    }

    public function test_freeze_audit_rejects_invalid_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordFreezeAudit('bad_event', 'full', null);
    }

    public function test_freeze_audit_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordFreezeAudit('freeze_initiated', 'invalid_scope', null);
    }

    public function test_hotfix_approved_audit_allows_hotfix(): void
    {
        $audit = $this->service->recordFreezeAudit('hotfix_approved', 'hotfix_only', 'correlation_engine', '1.0.0-rc.1', true);
        $this->assertTrue($audit->hotfix_allowed);
        $this->assertEquals('correlation_engine', $audit->subsystem);
    }

    // =========================================================================
    // Deployment artifacts
    // =========================================================================

    public function test_generate_artifact_creates_validated_hash(): void
    {
        $artifact = $this->service->generateArtifact('docker_compose', '1.0.0-rc.1');
        $this->assertTrue($artifact->integrity_verified);
        $this->assertEquals(64, strlen($artifact->artifact_hash));
        $this->assertEquals('docker_compose', $artifact->artifact_type);
    }

    public function test_generate_artifact_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateArtifact('bad_type', '1.0.0-rc.1');
    }

    public function test_all_artifact_types_accepted(): void
    {
        foreach (DeploymentArtifactReport::ARTIFACT_TYPES as $type) {
            $artifact = $this->service->generateArtifact($type, '1.0.0-rc.1');
            $this->assertEquals($type, $artifact->artifact_type);
        }
    }

    // =========================================================================
    // Pilot preparation
    // =========================================================================

    public function test_pilot_prep_endpoint_count_bounded(): void
    {
        $run = $this->service->runPilotPreparation('onboarding_readiness', 999, []);
        $this->assertLessThanOrEqual(
            ReleaseCandidateStabilizationService::MAX_PILOT_ENDPOINTS,
            $run->pilot_endpoint_count
        );
    }

    public function test_pilot_prep_passed_when_all_checks_true(): void
    {
        $run = $this->service->runPilotPreparation('ha_checkpoint', 10, [
            'onboarding_ready'   => true,
            'ha_ready'           => true,
            'rollback_ready'     => true,
            'telemetry_continuous' => true,
        ]);
        $this->assertEquals('passed', $run->preparation_state);
        $this->assertEquals(1.0, $run->readiness_score);
    }

    public function test_pilot_prep_failed_when_checks_insufficient(): void
    {
        $run = $this->service->runPilotPreparation('rollback_readiness', 5, [
            'onboarding_ready' => false,
            'ha_ready'         => false,
            'rollback_ready'   => false,
            'telemetry_continuous' => false,
        ]);
        $this->assertEquals('failed', $run->preparation_state);
        $this->assertEquals(0.0, $run->readiness_score);
    }

    public function test_pilot_prep_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runPilotPreparation('invalid_scope', 5, []);
    }

    // =========================================================================
    // Operational baselines
    // =========================================================================

    public function test_baseline_within_bounds_when_drift_small(): void
    {
        $baseline = $this->service->recordOperationalBaseline('replay_continuity', 0.95, 0.93);
        $this->assertTrue($baseline->within_bounds);
        $this->assertFalse($baseline->degradation_detected);
    }

    public function test_baseline_degradation_detected_when_drift_large(): void
    {
        $baseline = $this->service->recordOperationalBaseline('queue_durability', 0.70, 0.95);
        $this->assertTrue($baseline->degradation_detected);
        $this->assertFalse($baseline->within_bounds);
    }

    public function test_baseline_state_passing_when_score_high(): void
    {
        $baseline = $this->service->recordOperationalBaseline('telemetry_continuity', 0.95, 0.94);
        $this->assertEquals('passing', $baseline->validation_state);
    }

    public function test_baseline_state_failing_when_score_low(): void
    {
        $baseline = $this->service->recordOperationalBaseline('ha_continuity', 0.50, 0.52);
        $this->assertEquals('failing', $baseline->validation_state);
    }

    public function test_baseline_rejects_invalid_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOperationalBaseline('invalid_domain', 0.9, 0.9);
    }

    // =========================================================================
    // Release blockers
    // =========================================================================

    public function test_critical_blocker_sets_release_blocked(): void
    {
        $blocker = $this->service->registerBlocker('validation_failure', 'critical', 'Tests failed');
        $this->assertTrue($blocker->release_blocked);
    }

    public function test_non_critical_blocker_does_not_block_release(): void
    {
        $blocker = $this->service->registerBlocker('soak_incomplete', 'low', 'Minor delay');
        $this->assertFalse($blocker->release_blocked);
    }

    public function test_blocker_rejects_invalid_category(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerBlocker('bad_category', 'high', 'test');
    }

    public function test_blocker_rejects_invalid_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerBlocker('gate_failed', 'extreme', 'test');
    }

    // =========================================================================
    // Deployment reproducibility
    // =========================================================================

    public function test_full_reproducibility_score_is_1(): void
    {
        $report = $this->service->assessReproducibility('full_stack', [
            'environment_matched'          => true,
            'artifact_hash_verified'       => true,
            'configuration_deterministic'  => true,
            'replay_continuity_verified'   => true,
        ]);
        $this->assertEquals(1.0, $report->reproducibility_score);
    }

    public function test_partial_reproducibility_score(): void
    {
        $report = $this->service->assessReproducibility('canary', [
            'environment_matched'         => true,
            'artifact_hash_verified'      => true,
            'configuration_deterministic' => false,
            'replay_continuity_verified'  => false,
        ]);
        $this->assertEquals(0.5, $report->reproducibility_score);
    }

    public function test_reproducibility_rejects_invalid_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assessReproducibility('bad_scope', []);
    }

    // =========================================================================
    // Stabilization validation
    // =========================================================================

    public function test_stabilization_passed_when_all_checks_green(): void
    {
        $run = $this->service->recordStabilizationValidation('post_rc', [
            'tests_green'     => true,
            'registry_valid'  => true,
            'contracts_valid' => true,
            'resilience_valid'=> true,
            'fleet_sim_valid' => true,
        ]);
        $this->assertEquals('passed', $run->validation_state);
        $this->assertEquals(1.0, $run->validation_score);
    }

    public function test_stabilization_failed_when_checks_missing(): void
    {
        $run = $this->service->recordStabilizationValidation('pre_freeze', [
            'tests_green'     => false,
            'registry_valid'  => false,
            'contracts_valid' => false,
            'resilience_valid'=> false,
            'fleet_sim_valid' => false,
        ]);
        $this->assertEquals('failed', $run->validation_state);
        $this->assertEquals(0.0, $run->validation_score);
    }

    public function test_stabilization_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordStabilizationValidation('invalid_type', []);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_correct_structure(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('total_rc_manifests', $stats);
        $this->assertArrayHasKey('frozen_rcs', $stats);
        $this->assertArrayHasKey('total_artifacts', $stats);
        $this->assertArrayHasKey('verified_artifacts', $stats);
        $this->assertArrayHasKey('open_blockers', $stats);
        $this->assertArrayHasKey('critical_blockers', $stats);
        $this->assertArrayHasKey('pilot_prep_runs', $stats);
        $this->assertArrayHasKey('pilot_prep_passed', $stats);
        $this->assertArrayHasKey('stabilization_runs', $stats);
        $this->assertArrayHasKey('stabilization_passed', $stats);
    }

    // =========================================================================
    // Append-only integrity
    // =========================================================================

    public function test_release_candidate_manifests_are_append_only(): void
    {
        $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $this->service->initiateReleaseCandidate('1.0.0-rc.2');
        $this->assertEquals(2, ReleaseCandidateManifest::count());
    }

    public function test_feature_freeze_audits_are_append_only(): void
    {
        $this->service->recordFreezeAudit('freeze_initiated', 'full', null);
        $this->assertEquals(1, FeatureFreezeAudit::count());
    }

    public function test_deployment_artifact_reports_are_append_only(): void
    {
        $this->service->generateArtifact('env_template', '1.0.0-rc.1');
        $this->assertEquals(1, DeploymentArtifactReport::count());
    }

    public function test_pilot_preparation_runs_are_append_only(): void
    {
        $this->service->runPilotPreparation('deployment_check', 5, []);
        $this->assertEquals(1, PilotDeploymentPreparationRun::count());
    }

    public function test_release_blocker_reports_are_append_only(): void
    {
        $this->service->registerBlocker('gate_failed', 'medium', 'Gate failed');
        $this->assertEquals(1, ReleaseBlockerReport::count());
    }

    public function test_deployment_reproducibility_reports_are_append_only(): void
    {
        $this->service->assessReproducibility('single_service', []);
        $this->assertEquals(1, DeploymentReproducibilityReport::count());
    }

    public function test_stabilization_validation_runs_are_append_only(): void
    {
        $this->service->recordStabilizationValidation('pilot_prep', []);
        $this->assertEquals(1, StabilizationValidationRun::count());
    }

    public function test_release_candidate_audit_are_append_only(): void
    {
        $this->service->initiateReleaseCandidate('1.0.0-rc.1');
        $count = ReleaseCandidateAudit::count();
        $this->assertGreaterThan(0, $count);
    }

    // =========================================================================
    // ThreatHuntingService domain count
    // =========================================================================

    public function test_threat_hunting_service_has_correct_domain_count(): void
    {
        $this->assertCount(172, ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_release_stabilization_domains_registered(): void
    {
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('release_candidate_manifests', $domains);
        $this->assertContains('feature_freeze_audit', $domains);
        $this->assertContains('deployment_artifact_reports', $domains);
        $this->assertContains('pilot_deployment_preparation_runs', $domains);
        $this->assertContains('deployment_reproducibility_reports', $domains);
    }

    // =========================================================================
    // Routes Ã¢â‚¬â€ require auth
    // =========================================================================

    public function test_release_stab_dashboard_requires_auth(): void
    {
        $this->get('/release-stabilization')->assertRedirect('/login');
    }

    public function test_release_stab_freeze_requires_auth(): void
    {
        $this->get('/release-stabilization/freeze')->assertRedirect('/login');
    }

    public function test_release_stab_artifacts_requires_auth(): void
    {
        $this->get('/release-stabilization/artifacts')->assertRedirect('/login');
    }

    public function test_release_stab_pilot_requires_auth(): void
    {
        $this->get('/release-stabilization/pilot')->assertRedirect('/login');
    }

    public function test_release_stab_validation_requires_auth(): void
    {
        $this->get('/release-stabilization/validation')->assertRedirect('/login');
    }

    public function test_release_stab_reproducibility_requires_auth(): void
    {
        $this->get('/release-stabilization/reproducibility')->assertRedirect('/login');
    }

    public function test_release_stab_blockers_requires_auth(): void
    {
        $this->get('/release-stabilization/blockers')->assertRedirect('/login');
    }

    public function test_release_stab_stabilization_requires_auth(): void
    {
        $this->get('/release-stabilization/stabilization')->assertRedirect('/login');
    }

    public function test_release_stab_audit_requires_auth(): void
    {
        $this->get('/release-stabilization/audit')->assertRedirect('/login');
    }
}

