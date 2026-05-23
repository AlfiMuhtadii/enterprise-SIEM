<?php

namespace Tests\Feature;

use App\Models\EndpointFileHashLineage;
use App\Models\EndpointModuleLoad;
use App\Models\EndpointRegistryTimeline;
use App\Models\EndpointSocketLifecycle;
use App\Models\EndpointProcessAncestryValidation;
use App\Models\EndpointAntiEvasionIndicator;
use App\Models\EndpointRuntimeVisibility;
use App\Models\EndpointLineageConfidence;
use App\Models\EndpointSocketAnomaly;
use App\Services\EndpointSensorAdvancedTelemetryService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EndpointSensorAdvancedTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private EndpointSensorAdvancedTelemetryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EndpointSensorAdvancedTelemetryService::class);
    }

    // =========================================================================
    // Hard constraints
    // =========================================================================

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'autoRemediate'));
    }

    public function test_no_kernel_driver_injection_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'injectKernelDriver'));
    }

    public function test_no_process_injection_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'injectProcess'));
    }

    public function test_no_memory_dumping_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'dumpMemory'));
    }

    public function test_no_hidden_persistence_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'installPersistence'));
    }

    public function test_no_destructive_endpoint_mutation_method(): void
    {
        $this->assertFalse(method_exists(EndpointSensorAdvancedTelemetryService::class, 'mutateEndpointState'));
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(EndpointSensorAdvancedTelemetryService::ADVISORY_ONLY);
    }

    // =========================================================================
    // File hash lineage
    // =========================================================================

    public function test_record_file_hash_lineage_creates_record(): void
    {
        $r = $this->service->recordFileHashLineage(
            'ep-001', 't1', 'abc123def456', 'C:\\Windows\\notepad.exe', 'notepad.exe'
        );
        $this->assertEquals('abc123def456', $r->file_hash_sha256);
        $this->assertFalse($r->suspicious_propagation);
        $this->assertTrue($r->is_advisory);
    }

    public function test_suspicious_propagation_flagged_at_reuse_threshold(): void
    {
        $r = $this->service->recordFileHashLineage(
            'ep-001', 't1', 'abc123', 'C:\\temp\\evil.exe', 'evil.exe',
            ['reuse_count' => EndpointSensorAdvancedTelemetryService::MAX_REUSE_COUNT_SUSPICIOUS]
        );
        $this->assertTrue($r->suspicious_propagation);
    }

    public function test_renamed_executable_flag(): void
    {
        $r = $this->service->recordFileHashLineage(
            'ep-001', 't1', 'abc123', 'C:\\temp\\svchost.exe', 'svchost.exe',
            ['renamed_executable' => true]
        );
        $this->assertTrue($r->renamed_executable);
    }

    public function test_file_hash_lineage_uses_explicit_table(): void
    {
        $r = $this->service->recordFileHashLineage('ep-001', 't1', 'hash1', '/bin/bash', 'bash');
        $this->assertDatabaseHas('endpoint_file_hash_lineage', ['lineage_id' => $r->lineage_id]);
    }

    public function test_file_hash_lineage_is_append_only(): void
    {
        $r = $this->service->recordFileHashLineage('ep-001', 't1', 'hash1', '/bin/ls', 'ls');
        $model = EndpointFileHashLineage::where('lineage_id', $r->lineage_id)->first();
        $this->expectException(LogicException::class);
        $model->suspicious_propagation = true;
        $model->save();
    }

    // =========================================================================
    // Module/DLL loads
    // =========================================================================

    public function test_unsigned_module_flagged_as_suspicious(): void
    {
        $r = $this->service->recordModuleLoad(
            'ep-001', 't1', 'evil.dll', 'C:\\temp\\evil.dll', 'explorer.exe',
            ['is_signed' => false]
        );
        $this->assertFalse($r->is_signed);
        $this->assertTrue($r->suspicious_lineage);
    }

    public function test_signed_module_not_suspicious_by_default(): void
    {
        $r = $this->service->recordModuleLoad(
            'ep-001', 't1', 'ntdll.dll', 'C:\\Windows\\System32\\ntdll.dll', 'svchost.exe',
            ['is_signed' => true]
        );
        $this->assertTrue($r->is_signed);
        $this->assertFalse($r->suspicious_lineage);
    }

    public function test_module_load_is_advisory(): void
    {
        $r = $this->service->recordModuleLoad('ep-001', 't1', 'mod.dll', 'C:\\mod.dll', 'proc.exe');
        $this->assertTrue($r->is_advisory);
    }

    public function test_module_load_is_append_only(): void
    {
        $r = $this->service->recordModuleLoad('ep-001', 't1', 'mod.dll', 'C:\\mod.dll', 'proc.exe');
        $model = EndpointModuleLoad::where('load_id', $r->load_id)->first();
        $this->expectException(LogicException::class);
        $model->suspicious_lineage = true;
        $model->save();
    }

    // =========================================================================
    // Registry timelines
    // =========================================================================

    public function test_registry_timeline_creates_record(): void
    {
        $r = $this->service->recordRegistryTimeline(
            'ep-001', 't1',
            'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run',
            'create', 'startup', 'powershell.exe'
        );
        $this->assertEquals('startup', $r->key_category);
        $this->assertEquals('create', $r->modification_type);
        $this->assertTrue($r->is_advisory);
    }

    public function test_registry_timeline_rejects_invalid_modification_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRegistryTimeline(
            'ep-001', 't1', 'HKCU\\Run', 'mutate', 'startup', 'proc.exe'
        );
    }

    public function test_registry_timeline_rejects_invalid_key_category(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRegistryTimeline(
            'ep-001', 't1', 'HKCU\\Run', 'create', 'unknown_category', 'proc.exe'
        );
    }

    public function test_registry_timeline_is_append_only(): void
    {
        $r = $this->service->recordRegistryTimeline(
            'ep-001', 't1', 'HKCU\\Run', 'create', 'persistence', 'proc.exe'
        );
        $model = EndpointRegistryTimeline::where('timeline_id', $r->timeline_id)->first();
        $this->expectException(LogicException::class);
        $model->modification_type = 'delete';
        $model->save();
    }

    // =========================================================================
    // Socket lifecycle
    // =========================================================================

    public function test_socket_lifecycle_creates_record(): void
    {
        $r = $this->service->recordSocketLifecycle(
            'ep-001', 't1', 'chrome.exe', '192.168.1.100', 54321, 'tcp', 'established'
        );
        $this->assertEquals('tcp', $r->protocol);
        $this->assertEquals('established', $r->state);
        $this->assertFalse($r->suspicious_port);
    }

    public function test_suspicious_port_flagged(): void
    {
        $r = $this->service->recordSocketLifecycle(
            'ep-001', 't1', 'nc.exe', '0.0.0.0', 4444, 'tcp', 'listen'
        );
        $this->assertTrue($r->suspicious_port);
    }

    public function test_socket_lifecycle_rejects_invalid_protocol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSocketLifecycle(
            'ep-001', 't1', 'proc.exe', '0.0.0.0', 80, 'icmp', 'listen'
        );
    }

    public function test_socket_lifecycle_uses_explicit_table(): void
    {
        $r = $this->service->recordSocketLifecycle(
            'ep-001', 't1', 'svchost.exe', '0.0.0.0', 443, 'tcp', 'listen'
        );
        $this->assertDatabaseHas('endpoint_socket_lifecycle', ['socket_id' => $r->socket_id]);
    }

    public function test_socket_lifecycle_is_append_only(): void
    {
        $r = $this->service->recordSocketLifecycle(
            'ep-001', 't1', 'proc.exe', '0.0.0.0', 80, 'tcp', 'listen'
        );
        $model = EndpointSocketLifecycle::where('socket_id', $r->socket_id)->first();
        $this->expectException(LogicException::class);
        $model->state = 'closed';
        $model->save();
    }

    // =========================================================================
    // Process ancestry validation
    // =========================================================================

    public function test_ancestry_valid_with_found_parent(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'notepad.exe', [
            'parent_process_name' => 'explorer.exe',
            'parent_found'        => true,
            'lineage_consistent'  => true,
            'lineage_confidence'  => 0.95,
        ]);
        $this->assertFalse($r->orphaned_chain);
        $this->assertFalse($r->ancestry_divergence_detected);
        $this->assertTrue($r->replay_ordered);
    }

    public function test_orphaned_chain_when_parent_not_found(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'suspicious.exe', [
            'parent_found' => false,
        ]);
        $this->assertTrue($r->orphaned_chain);
    }

    public function test_divergence_detected_with_low_confidence(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'proc.exe', [
            'parent_found'       => true,
            'lineage_confidence' => 0.50,
        ]);
        $this->assertTrue($r->ancestry_divergence_detected);
    }

    public function test_lineage_confidence_clamped(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'proc.exe', [
            'lineage_confidence' => 2.0,
        ]);
        $this->assertLessThanOrEqual(1.0, $r->lineage_confidence);
    }

    public function test_ancestry_validation_uses_explicit_table(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'cmd.exe', []);
        $this->assertDatabaseHas('endpoint_process_ancestry_validation', ['validation_id' => $r->validation_id]);
    }

    public function test_ancestry_validation_is_append_only(): void
    {
        $r = $this->service->validateProcessAncestry('ep-001', 't1', 'cmd.exe', []);
        $model = EndpointProcessAncestryValidation::where('validation_id', $r->validation_id)->first();
        $this->expectException(LogicException::class);
        $model->orphaned_chain = true;
        $model->save();
    }

    // =========================================================================
    // Anti-evasion indicators
    // =========================================================================

    public function test_anti_evasion_indicator_creates_record(): void
    {
        $r = $this->service->recordAntiEvasionIndicator(
            'ep-001', 't1', 'parent_spoofing', 'powershell.exe', 0.75
        );
        $this->assertEquals('parent_spoofing', $r->indicator_type);
        $this->assertEquals('high', $r->severity);
        $this->assertTrue($r->is_advisory);
    }

    public function test_evasion_confirmed_sets_critical_severity(): void
    {
        $r = $this->service->recordAntiEvasionIndicator(
            'ep-001', 't1', 'telemetry_suppression', 'proc.exe', 0.85,
            ['evasion_confirmed' => true]
        );
        $this->assertEquals('critical', $r->severity);
        $this->assertTrue($r->evasion_confirmed);
    }

    public function test_anti_evasion_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordAntiEvasionIndicator(
            'ep-001', 't1', 'unknown_evasion_type', 'proc.exe', 0.80
        );
    }

    public function test_confidence_score_is_clamped(): void
    {
        $r = $this->service->recordAntiEvasionIndicator(
            'ep-001', 't1', 'delayed_execution', 'proc.exe', 1.5
        );
        $this->assertLessThanOrEqual(1.0, $r->confidence_score);
    }

    public function test_anti_evasion_indicator_is_append_only(): void
    {
        $r = $this->service->recordAntiEvasionIndicator(
            'ep-001', 't1', 'module_load_anomaly', 'proc.exe', 0.60
        );
        $model = EndpointAntiEvasionIndicator::where('indicator_id', $r->indicator_id)->first();
        $this->expectException(LogicException::class);
        $model->severity = 'critical';
        $model->save();
    }

    // =========================================================================
    // Runtime visibility
    // =========================================================================

    public function test_runtime_visibility_creates_record(): void
    {
        $r = $this->service->recordRuntimeVisibility('ep-001', 't1', 'process_snapshot', [
            'process_count'               => 120,
            'visibility_completeness_pct' => 0.98,
        ]);
        $this->assertEquals('process_snapshot', $r->visibility_type);
        $this->assertEquals(120, $r->process_count);
        $this->assertTrue($r->is_advisory);
    }

    public function test_runtime_visibility_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordRuntimeVisibility('ep-001', 't1', 'network_snapshot', []);
    }

    public function test_visibility_completeness_clamped(): void
    {
        $r = $this->service->recordRuntimeVisibility('ep-001', 't1', 'module_snapshot', [
            'visibility_completeness_pct' => 1.5,
        ]);
        $this->assertLessThanOrEqual(1.0, $r->visibility_completeness_pct);
    }

    public function test_runtime_visibility_uses_explicit_table(): void
    {
        $r = $this->service->recordRuntimeVisibility('ep-001', 't1', 'socket_snapshot', []);
        $this->assertDatabaseHas('endpoint_runtime_visibility', ['visibility_id' => $r->visibility_id]);
    }

    public function test_runtime_visibility_is_append_only(): void
    {
        $r = $this->service->recordRuntimeVisibility('ep-001', 't1', 'registry_snapshot', []);
        $model = EndpointRuntimeVisibility::where('visibility_id', $r->visibility_id)->first();
        $this->expectException(LogicException::class);
        $model->process_count = 999;
        $model->save();
    }

    // =========================================================================
    // Lineage confidence
    // =========================================================================

    public function test_lineage_confidence_no_degradation_when_stable(): void
    {
        $r = $this->service->recordLineageConfidence('ep-001', 't1', 'process', 0.95, 1.0);
        $this->assertFalse($r->degradation_detected);
        $this->assertTrue($r->replay_safe);
    }

    public function test_lineage_confidence_degradation_detected_at_threshold(): void
    {
        $r = $this->service->recordLineageConfidence(
            'ep-001', 't1', 'process', 0.85, 1.0
        );
        $this->assertTrue($r->degradation_detected);
        $this->assertEqualsWithDelta(0.15, $r->degradation_delta, 0.001);
    }

    public function test_lineage_confidence_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordLineageConfidence('ep-001', 't1', 'unknown_type', 0.9);
    }

    public function test_lineage_confidence_uses_explicit_table(): void
    {
        $r = $this->service->recordLineageConfidence('ep-001', 't1', 'module', 0.95);
        $this->assertDatabaseHas('endpoint_lineage_confidence', ['confidence_id' => $r->confidence_id]);
    }

    public function test_lineage_confidence_is_append_only(): void
    {
        $r = $this->service->recordLineageConfidence('ep-001', 't1', 'registry', 0.90);
        $model = EndpointLineageConfidence::where('confidence_id', $r->confidence_id)->first();
        $this->expectException(LogicException::class);
        $model->confidence_score = 0.50;
        $model->save();
    }

    // =========================================================================
    // Socket anomalies
    // =========================================================================

    public function test_socket_anomaly_creates_record(): void
    {
        $r = $this->service->recordSocketAnomaly(
            'ep-001', 't1', 'nc.exe', 'beaconing_pattern', 0.90
        );
        $this->assertEquals('beaconing_pattern', $r->anomaly_type);
        $this->assertEquals('critical', $r->severity);
        $this->assertTrue($r->is_advisory);
    }

    public function test_socket_anomaly_rejects_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordSocketAnomaly('ep-001', 't1', 'proc.exe', 'unknown_anomaly', 0.5);
    }

    public function test_socket_anomaly_severity_tiers(): void
    {
        $crit   = $this->service->recordSocketAnomaly('ep-001', 't1', 'p', 'suspicious_port', 0.90);
        $high   = $this->service->recordSocketAnomaly('ep-001', 't1', 'p', 'repeated_reconnect', 0.70);
        $medium = $this->service->recordSocketAnomaly('ep-001', 't1', 'p', 'unusual_destination', 0.50);
        $low    = $this->service->recordSocketAnomaly('ep-001', 't1', 'p', 'long_lived_connection', 0.20);

        $this->assertEquals('critical', $crit->severity);
        $this->assertEquals('high',     $high->severity);
        $this->assertEquals('medium',   $medium->severity);
        $this->assertEquals('low',      $low->severity);
    }

    public function test_socket_anomaly_is_append_only(): void
    {
        $r = $this->service->recordSocketAnomaly('ep-001', 't1', 'proc.exe', 'suspicious_port', 0.5);
        $model = EndpointSocketAnomaly::where('anomaly_id', $r->anomaly_id)->first();
        $this->expectException(LogicException::class);
        $model->severity = 'critical';
        $model->save();
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_expected_keys(): void
    {
        $stats = $this->service->dashboardStats();
        $this->assertArrayHasKey('hash_lineage_records', $stats);
        $this->assertArrayHasKey('suspicious_propagation', $stats);
        $this->assertArrayHasKey('unsigned_modules', $stats);
        $this->assertArrayHasKey('registry_persistence', $stats);
        $this->assertArrayHasKey('socket_anomalies', $stats);
        $this->assertArrayHasKey('evasion_critical', $stats);
        $this->assertArrayHasKey('ancestry_divergence', $stats);
        $this->assertArrayHasKey('confidence_degraded', $stats);
    }

    // =========================================================================
    // ThreatHunting domain integration
    // =========================================================================

    public function test_threat_hunting_has_120_supported_domains(): void
    {
        $this->assertCount(135, app(ThreatHuntingService::class)->supportedDomains());
    }

    public function test_endpoint_file_hash_lineage_domain_supported(): void
    {
        $this->assertContains('endpoint_file_hash_lineage', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_module_loads_domain_supported(): void
    {
        $this->assertContains('endpoint_module_loads', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_registry_timelines_domain_supported(): void
    {
        $this->assertContains('endpoint_registry_timelines', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_socket_lifecycle_domain_supported(): void
    {
        $this->assertContains('endpoint_socket_lifecycle', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_anti_evasion_indicators_domain_supported(): void
    {
        $this->assertContains('endpoint_anti_evasion_indicators', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    // =========================================================================
    // Route access
    // =========================================================================

    public function test_ep_sensor_dashboard_requires_auth(): void
    {
        $this->get(route('ep-sensor.dashboard'))->assertRedirect();
    }

    public function test_ep_sensor_dashboard_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('ep-sensor.dashboard'))->assertOk();
    }

    public function test_ep_sensor_anti_evasion_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('ep-sensor.anti-evasion'))->assertOk();
    }

    public function test_ep_sensor_lineage_confidence_accessible_to_admin(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('ep-sensor.lineage-confidence'))->assertOk();
    }

    // =========================================================================
    // Advisory notice in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('ep-sensor.dashboard'))
            ->assertSee('advisory-only');
    }
}
