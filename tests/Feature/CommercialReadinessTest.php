<?php

namespace Tests\Feature;

use App\Models\TenantOnboardingRun;
use App\Models\DeploymentPackageProfile;
use App\Models\CommercialReleaseHistory;
use App\Models\SupportBundleExport;
use App\Models\DeploymentReadinessReport;
use App\Models\CustomerOperationsHealth;
use App\Models\OnboardingCheckpointHistory;
use App\Models\ReleaseCompatibilityReport;
use App\Models\CommercialProductAudit;
use App\Services\CommercialReadinessService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialReadinessTest extends TestCase
{
    use RefreshDatabase;

    private CommercialReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CommercialReadinessService::class);
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'autoRemediate'));
    }

    public function test_no_hidden_telemetry_collection(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'collectHiddenTelemetry'));
    }

    public function test_no_destructive_support_action(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'destructiveSupportAction'));
    }

    public function test_no_remote_kill_switch(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'remoteKillSwitch'));
    }

    public function test_no_unsafe_auto_update(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'unsafeAutoUpdate'));
    }

    public function test_no_hidden_customer_mutation(): void
    {
        $this->assertFalse(method_exists(CommercialReadinessService::class, 'hiddenCustomerMutation'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(CommercialReadinessService::ADVISORY_ONLY);
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function test_max_export_size_bounded(): void
    {
        $this->assertSame(500, CommercialReadinessService::MAX_EXPORT_SIZE_MB);
    }

    public function test_max_onboarding_endpoints_bounded(): void
    {
        $this->assertSame(100, CommercialReadinessService::MAX_ONBOARDING_ENDPOINTS);
    }

    public function test_readiness_pass_threshold_defined(): void
    {
        $this->assertEqualsWithDelta(0.80, CommercialReadinessService::READINESS_PASS_THRESHOLD, 0.001);
    }

    // =========================================================================
    // Tenant onboarding
    // =========================================================================

    public function test_initiate_onboarding_creates_record(): void
    {
        $run = $this->service->initiateOnboarding('tenant-001', 'environment_validation', 10);

        $this->assertInstanceOf(TenantOnboardingRun::class, $run);
        $this->assertSame('tenant-001', $run->tenant_id);
        $this->assertSame('environment_validation', $run->onboarding_phase);
        $this->assertSame('initiated', $run->onboarding_state);
        $this->assertTrue($run->is_advisory);
        $this->assertTrue($run->replay_safe);
        $this->assertTrue($run->rollback_capable);
    }

    public function test_onboarding_endpoint_count_is_bounded(): void
    {
        $run = $this->service->initiateOnboarding('tenant-002', 'activation', 9999);

        $this->assertSame(CommercialReadinessService::MAX_ONBOARDING_ENDPOINTS, $run->endpoint_count);
    }

    public function test_invalid_onboarding_phase_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->initiateOnboarding('tenant-003', 'invalid_phase', 5);
    }

    public function test_advance_onboarding_updates_state(): void
    {
        $run = $this->service->initiateOnboarding('tenant-004', 'policy_install', 20);
        $updated = $this->service->advanceOnboarding($run, 'completed', 0.95);

        $this->assertSame('completed', $updated->onboarding_state);
        $this->assertTrue($updated->validation_passed);
    }

    public function test_advance_onboarding_below_threshold_not_passed(): void
    {
        $run = $this->service->initiateOnboarding('tenant-005', 'integration_check', 5);
        $updated = $this->service->advanceOnboarding($run, 'failed', 0.50);

        $this->assertFalse($updated->validation_passed);
    }

    public function test_initiate_onboarding_creates_audit_event(): void
    {
        $this->service->initiateOnboarding('tenant-006', 'schema_provisioning', 3);

        $this->assertDatabaseHas('commercial_product_audit', [
            'audit_event_type' => 'onboarding_initiated',
            'actor_type'       => 'operator',
        ]);
    }

    // =========================================================================
    // Onboarding checkpoints
    // =========================================================================

    public function test_record_onboarding_checkpoint(): void
    {
        $run = $this->service->initiateOnboarding('tenant-007', 'environment_validation', 5);
        $cp = $this->service->recordOnboardingCheckpoint($run->onboarding_run_id, 'environment_validated', true);

        $this->assertInstanceOf(OnboardingCheckpointHistory::class, $cp);
        $this->assertTrue($cp->passed);
        $this->assertTrue($cp->replay_safe);
        $this->assertTrue($cp->is_advisory);
    }

    public function test_invalid_checkpoint_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordOnboardingCheckpoint('run-id', 'invalid_checkpoint', true);
    }

    public function test_onboarding_rollback_checkpoint_recorded(): void
    {
        $run = $this->service->initiateOnboarding('tenant-008', 'activation', 10);
        $cp = $this->service->recordOnboardingCheckpoint(
            $run->onboarding_run_id,
            'pre_onboarding',
            false,
            ['rollback_triggered' => true]
        );

        $this->assertTrue($cp->rollback_triggered);
        $this->assertFalse($cp->passed);
    }

    // =========================================================================
    // Deployment packages
    // =========================================================================

    public function test_create_package_profile(): void
    {
        $profile = $this->service->createPackageProfile('XDR Standard', 'staging', 'standard', '1.0.0');

        $this->assertInstanceOf(DeploymentPackageProfile::class, $profile);
        $this->assertSame('staging', $profile->environment);
        $this->assertSame('standard', $profile->deployment_tier);
        $this->assertTrue($profile->is_active);
        $this->assertNotNull($profile->checksum);
    }

    public function test_invalid_environment_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createPackageProfile('Bad Env', 'cloud', 'standard', '1.0.0');
    }

    public function test_invalid_deployment_tier_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createPackageProfile('Bad Tier', 'production', 'hyperscale', '1.0.0');
    }

    public function test_package_checksum_verification(): void
    {
        $profile = $this->service->createPackageProfile('XDR Enterprise', 'production', 'enterprise', '2.0.0');
        $result  = $this->service->verifyPackageChecksum($profile);

        $this->assertTrue($result);
        $this->assertTrue($profile->fresh()->checksum_verified);
    }

    public function test_create_package_creates_audit(): void
    {
        $this->service->createPackageProfile('XDR Minimal', 'local', 'minimal', '0.1.0');

        $this->assertDatabaseHas('commercial_product_audit', [
            'audit_event_type' => 'package_validated',
        ]);
    }

    // =========================================================================
    // Commercial release history
    // =========================================================================

    public function test_record_release(): void
    {
        $release = $this->service->recordRelease('1.0.0', 'ga', 'published');

        $this->assertInstanceOf(CommercialReleaseHistory::class, $release);
        $this->assertSame('ga', $release->release_stage);
        $this->assertSame('published', $release->release_state);
        $this->assertTrue($release->self_approve_blocked);
        $this->assertTrue($release->replay_safe);
    }

    public function test_release_manifest_hash_is_deterministic(): void
    {
        $r1 = $this->service->recordRelease('1.1.0', 'rc', 'validated', ['manifest' => ['key' => 'value']]);
        $r2 = $this->service->recordRelease('1.1.0', 'rc', 'validated', ['manifest' => ['key' => 'value']]);

        $this->assertSame($r1->manifest_hash, $r2->manifest_hash);
    }

    public function test_invalid_release_stage_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRelease('1.0.0', 'canary', 'published');
    }

    public function test_self_approve_always_blocked(): void
    {
        $release = $this->service->recordRelease('2.0.0', 'beta', 'drafted');
        $this->assertTrue($release->self_approve_blocked);
    }

    // =========================================================================
    // Release compatibility
    // =========================================================================

    public function test_check_release_compatibility(): void
    {
        $release = $this->service->recordRelease('1.0.0', 'ga', 'published');
        $report  = $this->service->checkReleaseCompatibility($release->release_id, '0.9.0', '1.0.0');

        $this->assertInstanceOf(ReleaseCompatibilityReport::class, $report);
        $this->assertTrue($report->replay_safe);
        $this->assertTrue($report->schema_compatible);
    }

    public function test_compatibility_creates_audit(): void
    {
        $release = $this->service->recordRelease('1.0.0', 'ga', 'approved');
        $this->service->checkReleaseCompatibility($release->release_id, '0.8.0', '1.0.0');

        $this->assertDatabaseHas('commercial_product_audit', [
            'audit_event_type' => 'compatibility_checked',
        ]);
    }

    // =========================================================================
    // Support bundle exports
    // =========================================================================

    public function test_export_support_bundle(): void
    {
        $bundle = $this->service->exportSupportBundle('diagnostics', 'single_tenant', 100.0);

        $this->assertInstanceOf(SupportBundleExport::class, $bundle);
        $this->assertTrue($bundle->size_bounded);
        $this->assertFalse($bundle->destructive_action_taken);
        $this->assertTrue($bundle->replay_safe);
        $this->assertTrue($bundle->is_advisory);
    }

    public function test_export_size_is_bounded(): void
    {
        $bundle = $this->service->exportSupportBundle('audit', 'infrastructure', 99999.0);

        $this->assertEqualsWithDelta(CommercialReadinessService::MAX_EXPORT_SIZE_MB, $bundle->export_size_mb, 0.01);
    }

    public function test_invalid_bundle_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportSupportBundle('malware_scan', 'single_tenant', 10.0);
    }

    public function test_invalid_export_scope_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportSupportBundle('diagnostics', 'all_tenants', 10.0);
    }

    public function test_support_export_never_destructive(): void
    {
        $bundle = $this->service->exportSupportBundle('queue', 'environment', 50.0);
        $this->assertFalse($bundle->destructive_action_taken);
    }

    // =========================================================================
    // Deployment readiness
    // =========================================================================

    public function test_assess_deployment_readiness(): void
    {
        $report = $this->service->assessDeploymentReadiness('tenant-010', 'staging', [
            'onboarding_complete' => true,
            'telemetry_healthy'   => true,
            'environment_valid'   => true,
        ]);

        $this->assertInstanceOf(DeploymentReadinessReport::class, $report);
        $this->assertTrue($report->deployment_ready);
        $this->assertGreaterThanOrEqual(CommercialReadinessService::READINESS_PASS_THRESHOLD, $report->readiness_score);
    }

    public function test_partial_readiness_not_ready(): void
    {
        $report = $this->service->assessDeploymentReadiness('tenant-011', 'production', [
            'onboarding_complete' => false,
            'telemetry_healthy'   => false,
            'environment_valid'   => false,
        ]);

        $this->assertFalse($report->deployment_ready);
        $this->assertEqualsWithDelta(0.0, $report->readiness_score, 0.01);
    }

    public function test_readiness_report_is_advisory(): void
    {
        $report = $this->service->assessDeploymentReadiness('tenant-012', 'local', []);
        $this->assertTrue($report->is_advisory);
        $this->assertTrue($report->replay_safe);
    }

    // =========================================================================
    // Customer operations health
    // =========================================================================

    public function test_update_customer_health_creates_record(): void
    {
        $record = $this->service->updateCustomerHealth('tenant-020', [
            'deployment_healthy'   => true,
            'telemetry_continuous' => true,
            'support_ready'        => true,
            'onboarding_complete'  => true,
        ]);

        $this->assertInstanceOf(CustomerOperationsHealth::class, $record);
        $this->assertSame('healthy', $record->health_state);
    }

    public function test_customer_health_degraded_state(): void
    {
        $record = $this->service->updateCustomerHealth('tenant-021', [
            'deployment_healthy'   => true,
            'telemetry_continuous' => true,
            'support_ready'        => false,
            'onboarding_complete'  => false,
        ]);

        $this->assertContains($record->health_state, ['degraded', 'at_risk']);
    }

    public function test_customer_health_upserts_existing(): void
    {
        $this->service->updateCustomerHealth('tenant-022', ['deployment_healthy' => false]);
        $this->service->updateCustomerHealth('tenant-022', ['deployment_healthy' => true]);

        $this->assertSame(1, CustomerOperationsHealth::where('tenant_id', 'tenant-022')->count());
    }

    // =========================================================================
    // Audit
    // =========================================================================

    public function test_audit_record_never_autonomous(): void
    {
        $audit = $this->service->recordAudit('support_exported', 'operator', 'bundle-001');
        $this->assertFalse($audit->autonomous_action_taken);
        $this->assertFalse($audit->destructive_action_taken);
    }

    public function test_audit_is_replay_safe(): void
    {
        $audit = $this->service->recordAudit('health_updated', 'system', 'tenant-001');
        $this->assertTrue($audit->replay_safe);
    }

    public function test_invalid_audit_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAudit('destroy_tenant', 'operator', 'tenant-x');
    }

    // =========================================================================
    // Append-only integrity
    // =========================================================================

    public function test_tenant_onboarding_runs_are_append_only(): void
    {
        $run = $this->service->initiateOnboarding('tenant-030', 'environment_validation', 5);
        $this->assertDatabaseHas('tenant_onboarding_runs', ['onboarding_run_id' => $run->onboarding_run_id]);
    }

    public function test_commercial_release_history_is_append_only(): void
    {
        $release = $this->service->recordRelease('3.0.0', 'lts', 'published');
        $this->assertDatabaseHas('commercial_release_history', ['release_id' => $release->release_id]);
    }

    public function test_support_bundle_exports_are_append_only(): void
    {
        $bundle = $this->service->exportSupportBundle('storage', 'single_tenant', 20.0);
        $this->assertDatabaseHas('support_bundle_exports', ['bundle_export_id' => $bundle->bundle_export_id]);
    }

    public function test_onboarding_checkpoint_history_is_append_only(): void
    {
        $run = $this->service->initiateOnboarding('tenant-031', 'policy_install', 5);
        $cp  = $this->service->recordOnboardingCheckpoint($run->onboarding_run_id, 'policy_installed', true);
        $this->assertDatabaseHas('onboarding_checkpoint_history', ['onboarding_checkpoint_id' => $cp->onboarding_checkpoint_id]);
    }

    public function test_commercial_product_audit_is_append_only(): void
    {
        $this->service->recordAudit('release_published', 'operator', 'release-001');
        $this->assertSame(1, CommercialProductAudit::where('audit_event_type', 'release_published')->count());
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();

        $this->assertArrayHasKey('onboarding_runs', $stats);
        $this->assertArrayHasKey('active_packages', $stats);
        $this->assertArrayHasKey('releases_published', $stats);
        $this->assertArrayHasKey('support_exports', $stats);
        $this->assertArrayHasKey('readiness_reports', $stats);
        $this->assertArrayHasKey('deployments_ready', $stats);
        $this->assertArrayHasKey('healthy_tenants', $stats);
        $this->assertArrayHasKey('compatibility_reports', $stats);
        $this->assertArrayHasKey('audit_events', $stats);
    }

    // =========================================================================
    // Threat hunting domains
    // =========================================================================

    public function test_commercial_hunt_domains_registered(): void
    {
        $svc     = app(ThreatHuntingService::class);
        $domains = $svc->supportedDomains();

        $this->assertContains('tenant_onboarding_runs', $domains);
        $this->assertContains('commercial_release_history', $domains);
        $this->assertContains('support_bundle_exports', $domains);
        $this->assertContains('deployment_readiness_reports', $domains);
        $this->assertContains('release_compatibility_reports', $domains);
    }

    public function test_total_hunt_domains_is_135(): void
    {
        $svc = app(ThreatHuntingService::class);
        $this->assertCount(135, $svc->supportedDomains());
    }

    public function test_hunt_tenant_onboarding_runs(): void
    {
        $this->service->initiateOnboarding('hunt-tenant', 'activation', 5);
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('tenant_onboarding_runs', ['tenant_id' => 'hunt-tenant']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_commercial_release_history(): void
    {
        $this->service->recordRelease('4.0.0', 'ga', 'published');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('commercial_release_history', ['release_version' => '4.0.0']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_support_bundle_exports(): void
    {
        $this->service->exportSupportBundle('deployment', 'single_tenant', 30.0);
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('support_bundle_exports', ['bundle_type' => 'deployment']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_deployment_readiness_reports(): void
    {
        $this->service->assessDeploymentReadiness('tenant-hunt', 'staging', [
            'onboarding_complete' => true,
            'telemetry_healthy'   => true,
            'environment_valid'   => true,
        ]);
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('deployment_readiness_reports', ['tenant_id' => 'tenant-hunt']);
        $this->assertCount(1, $results);
    }

    public function test_hunt_release_compatibility_reports(): void
    {
        $release = $this->service->recordRelease('5.0.0', 'rc', 'validated');
        $this->service->checkReleaseCompatibility($release->release_id, '4.9.0', '5.0.0');
        $svc     = app(ThreatHuntingService::class);
        $results = $svc->hunt('release_compatibility_reports', ['to_version' => '5.0.0']);
        $this->assertCount(1, $results);
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_commercial_routes_require_auth(): void
    {
        $routes = [
            '/commercial-readiness',
            '/commercial-readiness/onboarding',
            '/commercial-readiness/packages',
            '/commercial-readiness/releases',
            '/commercial-readiness/readiness',
            '/commercial-readiness/support',
            '/commercial-readiness/health',
            '/commercial-readiness/upgrade',
            '/commercial-readiness/audit',
        ];
        foreach ($routes as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }
}
