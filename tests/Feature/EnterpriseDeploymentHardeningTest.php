<?php

namespace Tests\Feature;

use App\Models\DeploymentPackageManifest;
use App\Models\DeploymentIntegrityReport;
use App\Models\RolloutValidationRun;
use App\Models\DeploymentUpgradeHistory;
use App\Models\EnvironmentValidationReport;
use App\Models\DeploymentDriftReport;
use App\Models\DeploymentObservabilitySnapshot;
use App\Models\RolloutCheckpointHistory;
use App\Models\EnterpriseDeploymentAudit;
use App\Services\EnterpriseDeploymentHardeningService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Traits\AssertsAdvisoryOnlyConstraints;
use Tests\TestCase;

class EnterpriseDeploymentHardeningTest extends TestCase
{
    use RefreshDatabase, AssertsAdvisoryOnlyConstraints;

    private EnterpriseDeploymentHardeningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseDeploymentHardeningService::class);
    }

    protected function getAdvisoryServiceClass(): string
    {
        return EnterpriseDeploymentHardeningService::class;
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_hidden_deployment_mutation_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseDeploymentHardeningService::class, 'mutateDeploymentState'));
    }

    public function test_no_uncontrolled_auto_upgrade_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseDeploymentHardeningService::class, 'autoUpgrade'));
    }

    public function test_no_destructive_rollout_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseDeploymentHardeningService::class, 'destructiveRollout'));
    }

    public function test_no_unsafe_environment_override_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseDeploymentHardeningService::class, 'overrideEnvironment'));
    }

    public function test_no_hidden_config_injection_method(): void
    {
        $this->assertFalse(method_exists(EnterpriseDeploymentHardeningService::class, 'injectConfig'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EnterpriseDeploymentHardeningService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Package manifest
    // =========================================================================

    public function test_package_manifest_creates_record(): void
    {
        $r = $this->service->recordPackageManifest('pkg-001', '1.2.3', 'abc123hash');
        $this->assertEquals('pkg-001', $r->package_id);
        $this->assertEquals('1.2.3', $r->version);
        $this->assertTrue($r->is_advisory);
    }

    public function test_unsigned_package_flagged(): void
    {
        $r = $this->service->recordPackageManifest('pkg-001', '1.0', 'hash1', ['is_signed' => false]);
        $this->assertFalse($r->is_signed);
        $this->assertFalse($r->signature_valid);
    }

    public function test_signed_package_with_valid_signature(): void
    {
        $r = $this->service->recordPackageManifest('pkg-001', '2.0', 'hash2', [
            'is_signed'       => true,
            'signature_valid' => true,
        ]);
        $this->assertTrue($r->is_signed);
        $this->assertTrue($r->signature_valid);
    }

    public function test_package_manifest_is_append_only(): void
    {
        $r = $this->service->recordPackageManifest('pkg-001', '1.0', 'hash1');
        $model = DeploymentPackageManifest::where('manifest_id', $r->manifest_id)->first();
        $this->expectException(LogicException::class);
        $model->version = '2.0';
        $model->save();
    }

    // =========================================================================
    // Integrity report
    // =========================================================================

    public function test_integrity_report_passes_when_hash_matches(): void
    {
        $r = $this->service->runIntegrityReport(
            'pkg-001', '1.0', 'deadbeef', 'deadbeef',
            ['signature_valid' => true]
        );
        $this->assertTrue($r->hash_match);
        $this->assertTrue($r->validation_passed);
    }

    public function test_integrity_report_fails_on_hash_mismatch(): void
    {
        $r = $this->service->runIntegrityReport('pkg-001', '1.0', 'aaa', 'bbb');
        $this->assertFalse($r->hash_match);
        $this->assertFalse($r->validation_passed);
    }

    public function test_integrity_report_fails_when_signature_invalid(): void
    {
        $r = $this->service->runIntegrityReport(
            'pkg-001', '1.0', 'same', 'same',
            ['signature_valid' => false]
        );
        $this->assertFalse($r->validation_passed);
    }

    public function test_dependency_drift_detected_flag(): void
    {
        $r = $this->service->runIntegrityReport(
            'pkg-001', '1.0', 'same', 'same',
            ['signature_valid' => true, 'dependency_drift_detected' => true]
        );
        $this->assertTrue($r->dependency_drift_detected);
        $this->assertFalse($r->validation_passed);
    }

    public function test_integrity_report_is_append_only(): void
    {
        $r = $this->service->runIntegrityReport('pkg-001', '1.0', 'h', 'h');
        $model = DeploymentIntegrityReport::where('report_id', $r->report_id)->first();
        $this->expectException(LogicException::class);
        $model->validation_passed = true;
        $model->save();
    }

    // =========================================================================
    // Rollout validation
    // =========================================================================

    public function test_rollout_validation_creates_canary_record(): void
    {
        $r = $this->service->runRolloutValidation('ro-001', 'canary', 3);
        $this->assertEquals('canary', $r->stage);
        $this->assertEquals(3, $r->canary_endpoints);
        $this->assertTrue($r->bounded_concurrency);
    }

    public function test_rollout_rejects_invalid_stage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runRolloutValidation('ro-001', 'hotfix', 2);
    }

    public function test_rollout_canary_count_bounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runRolloutValidation(
            'ro-001', 'canary',
            EnterpriseDeploymentHardeningService::MAX_CANARY_ENDPOINTS + 1
        );
    }

    public function test_rollout_duration_clamped_to_max(): void
    {
        $r = $this->service->runRolloutValidation('ro-001', 'staged', 0, [
            'rollout_duration_s' => EnterpriseDeploymentHardeningService::MAX_ROLLOUT_DURATION_S + 9999,
        ]);
        $this->assertLessThanOrEqual(EnterpriseDeploymentHardeningService::MAX_ROLLOUT_DURATION_S, $r->rollout_duration_s);
    }

    public function test_rollout_success_reflected(): void
    {
        $r = $this->service->runRolloutValidation('ro-001', 'full', 0, ['rollout_success' => true]);
        $this->assertTrue($r->rollout_success);
    }

    public function test_rollout_validation_is_append_only(): void
    {
        $r = $this->service->runRolloutValidation('ro-001', 'canary', 1);
        $model = RolloutValidationRun::where('run_id', $r->run_id)->first();
        $this->expectException(LogicException::class);
        $model->rollout_success = true;
        $model->save();
    }

    // =========================================================================
    // Upgrade history
    // =========================================================================

    public function test_upgrade_history_creates_record(): void
    {
        $r = $this->service->recordUpgradeHistory('1.0.0', '2.0.0');
        $this->assertEquals('1.0.0', $r->from_version);
        $this->assertEquals('2.0.0', $r->to_version);
        $this->assertTrue($r->is_advisory);
    }

    public function test_compatibility_verified_when_both_pass(): void
    {
        $r = $this->service->recordUpgradeHistory('1.0', '2.0', [
            'migration_passed'    => true,
            'rollback_compatible' => true,
        ]);
        $this->assertTrue($r->compatibility_verified);
    }

    public function test_compatibility_not_verified_when_migration_fails(): void
    {
        $r = $this->service->recordUpgradeHistory('1.0', '2.0', [
            'migration_passed'    => false,
            'rollback_compatible' => true,
        ]);
        $this->assertFalse($r->compatibility_verified);
    }

    public function test_dependency_drift_in_upgrade(): void
    {
        $r = $this->service->recordUpgradeHistory('1.0', '2.0', ['dependency_drift_detected' => true]);
        $this->assertTrue($r->dependency_drift_detected);
    }

    public function test_upgrade_history_is_append_only(): void
    {
        $r = $this->service->recordUpgradeHistory('1.0', '2.0');
        $model = DeploymentUpgradeHistory::where('upgrade_id', $r->upgrade_id)->first();
        $this->expectException(LogicException::class);
        $model->migration_passed = true;
        $model->save();
    }

    // =========================================================================
    // Environment validation
    // =========================================================================

    public function test_environment_validation_creates_record(): void
    {
        $r = $this->service->runEnvironmentValidation('staging', [
            'services_healthy'  => true,
            'queue_available'   => true,
            'storage_available' => true,
            'replay_continuous' => true,
        ]);
        $this->assertEquals('staging', $r->environment);
        $this->assertTrue($r->overall_valid);
    }

    public function test_environment_validation_rejects_unknown_environment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->runEnvironmentValidation('kubernetes_prod', []);
    }

    public function test_overall_valid_false_when_any_check_fails(): void
    {
        $r = $this->service->runEnvironmentValidation('production', [
            'services_healthy'  => true,
            'queue_available'   => false,
            'storage_available' => true,
            'replay_continuous' => true,
        ]);
        $this->assertFalse($r->overall_valid);
    }

    public function test_environment_validation_uses_explicit_table(): void
    {
        $r = $this->service->runEnvironmentValidation('local', []);
        $this->assertDatabaseHas('environment_validation_reports', ['validation_id' => $r->validation_id]);
    }

    public function test_environment_validation_is_append_only(): void
    {
        $r = $this->service->runEnvironmentValidation('local', []);
        $model = EnvironmentValidationReport::where('validation_id', $r->validation_id)->first();
        $this->expectException(LogicException::class);
        $model->overall_valid = true;
        $model->save();
    }

    // =========================================================================
    // Deployment drift
    // =========================================================================

    public function test_no_drift_when_versions_match(): void
    {
        $r = $this->service->recordDeploymentDrift('api-gateway', '1.0.0', '1.0.0');
        $this->assertFalse($r->drift_detected);
        $this->assertEquals(0.0, $r->drift_score);
        $this->assertEquals('low', $r->drift_severity);
    }

    public function test_drift_severity_tiers(): void
    {
        $low    = $this->service->recordDeploymentDrift('svc', '1.0', '1.1', ['drift_score' => 0.05]);
        $medium = $this->service->recordDeploymentDrift('svc', '1.0', '1.2', ['drift_score' => 0.15]);
        $high   = $this->service->recordDeploymentDrift('svc', '1.0', '1.5', ['drift_score' => 0.25]);
        $crit   = $this->service->recordDeploymentDrift('svc', '1.0', '2.0', ['drift_score' => 0.40]);

        $this->assertEquals('low',      $low->drift_severity);
        $this->assertEquals('medium',   $medium->drift_severity);
        $this->assertEquals('high',     $high->drift_severity);
        $this->assertEquals('critical', $crit->drift_severity);
    }

    public function test_drift_detected_true_for_version_mismatch(): void
    {
        $r = $this->service->recordDeploymentDrift('svc', '1.0', '2.0', ['drift_score' => 0.10]);
        $this->assertTrue($r->drift_detected);
    }

    public function test_drift_report_uses_explicit_table(): void
    {
        $r = $this->service->recordDeploymentDrift('svc', '1.0', '1.0');
        $this->assertDatabaseHas('deployment_drift_reports', ['drift_id' => $r->drift_id]);
    }

    public function test_drift_report_is_append_only(): void
    {
        $r = $this->service->recordDeploymentDrift('svc', '1.0', '2.0');
        $model = DeploymentDriftReport::where('drift_id', $r->drift_id)->first();
        $this->expectException(LogicException::class);
        $model->drift_severity = 'critical';
        $model->save();
    }

    // =========================================================================
    // Observability snapshot
    // =========================================================================

    public function test_observability_snapshot_creates_record(): void
    {
        $r = $this->service->recordObservabilitySnapshot('dep-001', 0.98, 120.5);
        $this->assertEquals('dep-001', $r->deployment_id);
        $this->assertEqualsWithDelta(0.98, $r->success_rate, 0.001);
        $this->assertTrue($r->is_advisory);
    }

    public function test_success_rate_clamped_to_zero_one(): void
    {
        $r = $this->service->recordObservabilitySnapshot('dep-001', 1.5, 60.0);
        $this->assertLessThanOrEqual(1.0, $r->success_rate);
    }

    public function test_rollout_duration_clamped_in_snapshot(): void
    {
        $r = $this->service->recordObservabilitySnapshot('dep-001', 0.95, 99999.0);
        $this->assertLessThanOrEqual(EnterpriseDeploymentHardeningService::MAX_ROLLOUT_DURATION_S, $r->rollout_duration_s);
    }

    public function test_observability_snapshot_is_append_only(): void
    {
        $r = $this->service->recordObservabilitySnapshot('dep-001', 0.99, 60.0);
        $model = DeploymentObservabilitySnapshot::where('snapshot_id', $r->snapshot_id)->first();
        $this->expectException(LogicException::class);
        $model->success_rate = 0.0;
        $model->save();
    }

    // =========================================================================
    // Rollout checkpoint
    // =========================================================================

    public function test_rollout_checkpoint_creates_record(): void
    {
        $r = $this->service->recordRolloutCheckpoint('ro-001', 'pre_rollout', true);
        $this->assertEquals('pre_rollout', $r->checkpoint_type);
        $this->assertTrue($r->passed);
        $this->assertTrue($r->is_advisory);
    }

    public function test_rollout_checkpoint_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRolloutCheckpoint('ro-001', 'mid_canary', false);
    }

    public function test_rollout_checkpoint_failure_recorded(): void
    {
        $r = $this->service->recordRolloutCheckpoint('ro-001', 'post_canary', false);
        $this->assertFalse($r->passed);
    }

    public function test_rollout_checkpoint_is_append_only(): void
    {
        $r = $this->service->recordRolloutCheckpoint('ro-001', 'post_full', true);
        $model = RolloutCheckpointHistory::where('checkpoint_id', $r->checkpoint_id)->first();
        $this->expectException(LogicException::class);
        $model->passed = false;
        $model->save();
    }

    // =========================================================================
    // Deployment audit
    // =========================================================================

    public function test_deployment_audit_creates_record(): void
    {
        $r = $this->service->recordDeploymentAudit('dep-001', 'rollout_start', 'ops-team', 'success');
        $this->assertEquals('rollout_start', $r->action_type);
        $this->assertEquals('success', $r->outcome);
        $this->assertTrue($r->is_advisory);
    }

    public function test_deployment_audit_rejects_invalid_action_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDeploymentAudit('dep-001', 'force_redeploy', 'ops', 'success');
    }

    public function test_deployment_audit_rejects_invalid_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordDeploymentAudit('dep-001', 'rollout_start', 'ops', 'unknown_outcome');
    }

    public function test_deployment_audit_is_append_only(): void
    {
        $r = $this->service->recordDeploymentAudit('dep-001', 'environment_check', 'ops', 'advisory');
        $model = EnterpriseDeploymentAudit::where('audit_id', $r->audit_id)->first();
        $this->expectException(LogicException::class);
        $model->outcome = 'failure';
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('total_manifests', $stats);
        $this->assertArrayHasKey('unsigned_packages', $stats);
        $this->assertArrayHasKey('integrity_failures', $stats);
        $this->assertArrayHasKey('rollout_runs', $stats);
        $this->assertArrayHasKey('rollout_failures', $stats);
        $this->assertArrayHasKey('upgrade_history', $stats);
        $this->assertArrayHasKey('env_validation_runs', $stats);
        $this->assertArrayHasKey('critical_drift', $stats);
        $this->assertArrayHasKey('checkpoint_failures', $stats);
        $this->assertArrayHasKey('audit_events', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_supported_domains_count(): void
    {
        $this->assertCount(181, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_deployment_integrity_reports_domain_supported(): void
    {
        $this->assertContains('deployment_integrity_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_rollout_validation_runs_domain_supported(): void
    {
        $this->assertContains('rollout_validation_runs', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_deployment_upgrade_history_domain_supported(): void
    {
        $this->assertContains('deployment_upgrade_history', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_deployment_drift_reports_domain_supported(): void
    {
        $this->assertContains('deployment_drift_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_environment_validation_reports_domain_supported(): void
    {
        $this->assertContains('environment_validation_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_enterprise_deploy_dashboard_requires_auth(): void
    {
        $this->get(route('enterprise-deploy.dashboard'))->assertRedirect();
    }

    public function test_enterprise_deploy_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-deploy.dashboard'))->assertOk();
    }

    public function test_enterprise_deploy_packages_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-deploy.packages'))->assertOk();
    }

    public function test_enterprise_deploy_upgrade_governance_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-deploy.upgrade-governance'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('enterprise-deploy.dashboard'))
            ->assertSee('advisory-only');
    }
}

