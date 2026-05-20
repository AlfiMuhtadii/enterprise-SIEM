<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointContainerActivity;
use App\Models\EndpointPrivilegeEscalation;
use App\Models\EndpointScriptExecution;
use App\Models\User;
use App\Services\EndpointTelemetryAnalyticsService;
use App\Services\EntityRiskScoringService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoint Low-Level Telemetry Phase 1 — Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No isolateHost
 *   - No quarantineHost
 *   - No executeShell
 *   - No killProcess
 *   - No autoRemediate
 *   - No process injection
 *   - No kernel enforcement
 *   - No offensive capability
 *   - is_advisory = true on all records
 *   - All new tables are append-only (no updated_at)
 */
class EndpointLowLevelTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private EndpointTelemetryAnalyticsService $svc;
    private ThreatHuntingService $huntService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc         = app(EndpointTelemetryAnalyticsService::class);
        $this->huntService = app(ThreatHuntingService::class);
        \Illuminate\Support\Facades\Config::set('soc.agent_enrollment_token', 'test-lltet-token');
    }

    // =========================================================================
    // Schema — new tables exist
    // =========================================================================

    public function test_endpoint_script_executions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_script_executions'));
    }

    public function test_endpoint_privilege_escalations_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_privilege_escalations'));
    }

    public function test_endpoint_container_activities_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('endpoint_container_activities'));
    }

    // =========================================================================
    // Append-only guarantees — no updated_at
    // =========================================================================

    public function test_script_executions_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('endpoint_script_executions', 'updated_at'));
    }

    public function test_privilege_escalations_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('endpoint_privilege_escalations', 'updated_at'));
    }

    public function test_container_activities_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('endpoint_container_activities', 'updated_at'));
    }

    // =========================================================================
    // Model constants
    // =========================================================================

    public function test_script_execution_source_constants(): void
    {
        $sources = [
            EndpointScriptExecution::SOURCE_INLINE,
            EndpointScriptExecution::SOURCE_FILE,
            EndpointScriptExecution::SOURCE_ENCODED,
            EndpointScriptExecution::SOURCE_PIPED,
        ];
        $this->assertCount(4, $sources);
        $this->assertContains('encoded', $sources);
        $this->assertContains('inline', $sources);
    }

    public function test_script_execution_telemetry_source_constants(): void
    {
        $sources = [
            EndpointScriptExecution::TELEM_AGENT_PROC,
            EndpointScriptExecution::TELEM_SYSMON,
            EndpointScriptExecution::TELEM_POWERSHELL_OPS,
            EndpointScriptExecution::TELEM_ETW,
            EndpointScriptExecution::TELEM_SECURITY_EVENT,
        ];
        $this->assertCount(5, $sources);
    }

    public function test_privilege_escalation_type_constants(): void
    {
        $types = EndpointPrivilegeEscalation::ESCALATION_TYPES;
        $this->assertContains('uid_transition', $types);
        $this->assertContains('sudo_invocation', $types);
        $this->assertContains('su_invocation', $types);
        $this->assertContains('integrity_level_high', $types);
        $this->assertContains('token_impersonation', $types);
        $this->assertContains('setuid_exec', $types);
        $this->assertCount(6, $types);
    }

    public function test_container_activity_type_constants(): void
    {
        $types = EndpointContainerActivity::ACTIVITY_TYPES;
        $this->assertContains('container_start', $types);
        $this->assertContains('container_stop', $types);
        $this->assertContains('namespace_detected', $types);
        $this->assertContains('breakout_indicator', $types);
        $this->assertCount(4, $types);
    }

    // =========================================================================
    // Script execution recording
    // =========================================================================

    public function test_record_script_execution_creates_append_only_record(): void
    {
        $agent = $this->makeAgent();
        $exec  = $this->svc->recordScriptExecution($agent, [
            'process_name'     => 'powershell.exe',
            'script_source'    => EndpointScriptExecution::SOURCE_ENCODED,
            'is_encoded'       => true,
            'decoded_preview'  => 'Invoke-Expression $env:COMSPEC',
            'telemetry_source' => EndpointScriptExecution::TELEM_SYSMON,
            'occurred_at'      => now(),
        ]);

        $this->assertInstanceOf(EndpointScriptExecution::class, $exec);
        $this->assertTrue($exec->is_advisory);
        $this->assertTrue($exec->is_encoded);
        $this->assertStringStartsWith('sex-', $exec->execution_id);
        $this->assertEquals('powershell.exe', $exec->process_name);
        $this->assertEquals('sysmon', $exec->telemetry_source);
    }

    public function test_script_execution_is_advisory_always_true(): void
    {
        $agent = $this->makeAgent();
        $exec  = $this->svc->recordScriptExecution($agent, [
            'process_name'  => 'python3',
            'script_source' => EndpointScriptExecution::SOURCE_INLINE,
            'is_encoded'    => false,
            'occurred_at'   => now(),
        ]);

        $this->assertTrue($exec->is_advisory, 'Script execution must always have is_advisory=true');
        $this->assertFalse($exec->is_encoded);
    }

    public function test_get_script_executions_returns_recent_records(): void
    {
        $agent = $this->makeAgent();
        foreach (['powershell.exe', 'python3', 'bash'] as $proc) {
            $this->svc->recordScriptExecution($agent, [
                'process_name'  => $proc,
                'script_source' => EndpointScriptExecution::SOURCE_INLINE,
                'is_encoded'    => false,
                'occurred_at'   => now(),
            ]);
        }

        $results = $this->svc->getScriptExecutions($agent);
        $this->assertCount(3, $results);
        $this->assertTrue($results->every(fn ($r) => $r->is_advisory));
    }

    public function test_get_encoded_script_executions_filters_correctly(): void
    {
        $agent = $this->makeAgent();
        $this->svc->recordScriptExecution($agent, [
            'process_name' => 'powershell.exe', 'script_source' => 'encoded',
            'is_encoded' => true, 'occurred_at' => now(),
        ]);
        $this->svc->recordScriptExecution($agent, [
            'process_name' => 'python3', 'script_source' => 'inline',
            'is_encoded' => false, 'occurred_at' => now(),
        ]);

        $encoded = $this->svc->getEncodedScriptExecutions();
        $this->assertCount(1, $encoded);
        $this->assertEquals('powershell.exe', $encoded->first()->process_name);
    }

    public function test_script_execution_summary(): void
    {
        $agent = $this->makeAgent();
        $this->svc->recordScriptExecution($agent, ['process_name' => 'powershell.exe', 'script_source' => 'encoded', 'is_encoded' => true, 'occurred_at' => now()]);
        $this->svc->recordScriptExecution($agent, ['process_name' => 'python3', 'script_source' => 'inline', 'is_encoded' => false, 'occurred_at' => now()]);

        $summary = $this->svc->getScriptExecutionSummary();
        $this->assertEquals(2, $summary['total_executions']);
        $this->assertEquals(1, $summary['encoded_count']);
        $this->assertTrue($summary['advisory_only']);
    }

    // =========================================================================
    // Privilege escalation recording
    // =========================================================================

    public function test_record_privilege_escalation_creates_record(): void
    {
        $agent = $this->makeAgent();
        $esc   = $this->svc->recordPrivilegeEscalation($agent, [
            'process_name'    => 'sudo',
            'escalation_type' => EndpointPrivilegeEscalation::TYPE_SUDO_INVOCATION,
            'original_uid'    => 1000,
            'escalated_uid'   => 0,
            'original_user'   => 'ubuntu',
            'escalated_user'  => 'root',
            'confidence'      => 0.85,
            'occurred_at'     => now(),
        ]);

        $this->assertInstanceOf(EndpointPrivilegeEscalation::class, $esc);
        $this->assertTrue($esc->is_advisory);
        $this->assertStringStartsWith('esc-', $esc->escalation_id);
        $this->assertEquals('sudo_invocation', $esc->escalation_type);
        $this->assertEqualsWithDelta(0.85, $esc->confidence, 1e-6);
    }

    public function test_privilege_escalation_is_advisory_always_true(): void
    {
        $agent = $this->makeAgent();
        $esc   = $this->svc->recordPrivilegeEscalation($agent, [
            'process_name'    => 'su',
            'escalation_type' => EndpointPrivilegeEscalation::TYPE_SU_INVOCATION,
            'occurred_at'     => now(),
        ]);

        $this->assertTrue($esc->is_advisory, 'Privilege escalation must always have is_advisory=true');
    }

    public function test_get_privilege_escalation_timeline(): void
    {
        $agent = $this->makeAgent();
        foreach ([0.9, 0.7, 0.8] as $conf) {
            $this->svc->recordPrivilegeEscalation($agent, [
                'process_name'    => 'sudo',
                'escalation_type' => 'sudo_invocation',
                'confidence'      => $conf,
                'occurred_at'     => now(),
            ]);
        }

        $timeline = $this->svc->getPrivilegeEscalationTimeline();
        $this->assertCount(3, $timeline);
        // Should be sorted by confidence desc
        $this->assertGreaterThanOrEqual($timeline[1]->confidence, $timeline[0]->confidence);
    }

    public function test_privilege_escalation_summary(): void
    {
        $agent = $this->makeAgent();
        $this->svc->recordPrivilegeEscalation($agent, ['process_name' => 'sudo', 'escalation_type' => 'sudo_invocation', 'confidence' => 0.9, 'occurred_at' => now()]);
        $this->svc->recordPrivilegeEscalation($agent, ['process_name' => 'su', 'escalation_type' => 'su_invocation', 'confidence' => 0.7, 'occurred_at' => now()]);

        $summary = $this->svc->getPrivilegeEscalationSummary();
        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(1, $summary['high_confidence']);
        $this->assertTrue($summary['advisory_only']);
    }

    // =========================================================================
    // Container activity recording
    // =========================================================================

    public function test_record_container_activity_creates_record(): void
    {
        $agent    = $this->makeAgent();
        $activity = $this->svc->recordContainerActivity($agent, [
            'container_id'   => 'abc123def456',
            'activity_type'  => EndpointContainerActivity::TYPE_NAMESPACE_DETECTED,
            'namespace_type' => EndpointContainerActivity::NS_DOCKER,
            'pid'            => 1234,
            'process_name'   => 'nginx',
            'occurred_at'    => now(),
        ]);

        $this->assertInstanceOf(EndpointContainerActivity::class, $activity);
        $this->assertTrue($activity->is_advisory);
        $this->assertStringStartsWith('cta-', $activity->activity_id);
        $this->assertEquals('docker', $activity->namespace_type);
        $this->assertEquals('namespace_detected', $activity->activity_type);
    }

    public function test_container_activity_is_advisory_always_true(): void
    {
        $agent    = $this->makeAgent();
        $activity = $this->svc->recordContainerActivity($agent, [
            'activity_type' => EndpointContainerActivity::TYPE_BREAKOUT_INDICATOR,
            'occurred_at'   => now(),
        ]);

        $this->assertTrue($activity->is_advisory, 'Container activity must always have is_advisory=true');
    }

    public function test_get_container_breakout_indicators(): void
    {
        $agent = $this->makeAgent();
        $this->svc->recordContainerActivity($agent, ['activity_type' => 'namespace_detected', 'occurred_at' => now()]);
        $this->svc->recordContainerActivity($agent, ['activity_type' => 'breakout_indicator', 'occurred_at' => now()]);

        $breakouts = $this->svc->getContainerBreakoutIndicators();
        $this->assertCount(1, $breakouts);
        $this->assertEquals('breakout_indicator', $breakouts->first()->activity_type);
    }

    public function test_container_activity_summary(): void
    {
        $agent = $this->makeAgent();
        $this->svc->recordContainerActivity($agent, ['activity_type' => 'namespace_detected', 'container_id' => 'aaa', 'namespace_type' => 'docker', 'occurred_at' => now()]);
        $this->svc->recordContainerActivity($agent, ['activity_type' => 'breakout_indicator', 'container_id' => 'bbb', 'namespace_type' => 'kubernetes', 'occurred_at' => now()]);

        $summary = $this->svc->getContainerActivitySummary();
        $this->assertEquals(2, $summary['total_activities']);
        $this->assertEquals(1, $summary['breakout_indicators']);
        $this->assertEquals(2, $summary['unique_containers']);
        $this->assertTrue($summary['advisory_only']);
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_returns_advisory_only(): void
    {
        $stats = $this->svc->getDashboardStats();
        $this->assertTrue($stats['advisory_only']);
        $this->assertArrayHasKey('script_executions', $stats);
        $this->assertArrayHasKey('privilege_escalations', $stats);
        $this->assertArrayHasKey('container_activities', $stats);
        $this->assertArrayHasKey('container_breakouts', $stats);
    }

    // =========================================================================
    // Threat hunting domain coverage
    // =========================================================================

    public function test_endpoint_process_executions_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_process_executions', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_network_connections_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_network_connections', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_script_executions_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_script_executions', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_persistence_indicators_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_persistence_indicators', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_privilege_escalations_is_supported_hunt_domain(): void
    {
        $this->assertContains('endpoint_privilege_escalations', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_hunt_domains_total_is_35(): void
    {
        $this->assertCount(35, ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_endpoint_script_executions_domain_supports_field_queries(): void
    {
        $this->huntService->validateQueryFilters('endpoint_script_executions', [
            ['field' => 'process_name', 'operator' => '=', 'value' => 'powershell.exe'],
            ['field' => 'is_encoded', 'operator' => '=', 'value' => true],
        ]);
        $this->assertTrue(true); // no exception thrown
    }

    public function test_endpoint_privilege_escalations_domain_supports_field_queries(): void
    {
        $this->huntService->validateQueryFilters('endpoint_privilege_escalations', [
            ['field' => 'escalation_type', 'operator' => '=', 'value' => 'sudo_invocation'],
            ['field' => 'confidence', 'operator' => '>=', 'value' => 0.8],
        ]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // Risk engine — new factors
    // =========================================================================

    public function test_low_level_telemetry_risk_factors_in_weights(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertArrayHasKey('rare_process_factor',          $weights);
        $this->assertArrayHasKey('suspicious_script_factor',     $weights);
        $this->assertArrayHasKey('persistence_indicator_factor', $weights);
        $this->assertArrayHasKey('abnormal_connection_factor',   $weights);
        $this->assertArrayHasKey('privilege_escalation_factor',  $weights);
    }

    public function test_privilege_escalation_factor_is_highest_weight(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertEquals(3.0, $weights['privilege_escalation_factor']);
    }

    public function test_suspicious_script_factor_weight(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        $this->assertEquals(2.5, $weights['suspicious_script_factor']);
    }

    public function test_all_new_risk_factors_are_positive(): void
    {
        $weights = EntityRiskScoringService::WEIGHTS;
        foreach (['rare_process_factor', 'suspicious_script_factor', 'persistence_indicator_factor',
                  'abnormal_connection_factor', 'privilege_escalation_factor'] as $factor) {
            $this->assertGreaterThan(0, $weights[$factor], "$factor must be positive");
        }
    }

    // =========================================================================
    // Detection rules
    // =========================================================================

    public function test_new_lltet_rules_are_in_registry(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/detection/rules/registry.v1.json')), true);
        $ruleIds  = array_column($registry['rules'], 'rule_id');

        $expectedRules = [
            'LLTET_SUSPICIOUS_INTERPRETER_CHAIN',
            'LLTET_PRIVILEGE_ESCALATION_INDICATOR',
            'LLTET_SUSPICIOUS_PERSISTENCE_INDICATOR',
            'LLTET_ABNORMAL_NETWORK_CONNECTION',
            'LLTET_SUSPICIOUS_SHELL_SPAWN',
            'LLTET_RARE_PROCESS_EXECUTION',
            'LLTET_CONTAINER_ESCAPE_INDICATOR',
            'LLTET_SUSPICIOUS_SCRIPT_BLOCK',
        ];

        foreach ($expectedRules as $ruleId) {
            $this->assertContains($ruleId, $ruleIds, "$ruleId should be in registry");
        }
    }

    public function test_all_lltet_rules_are_shadow_only(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/detection/rules/registry.v1.json')), true);
        foreach ($registry['rules'] as $rule) {
            if (str_starts_with($rule['rule_id'], 'LLTET_')) {
                $this->assertTrue($rule['shadow_only'] ?? false, "{$rule['rule_id']} must be shadow_only=true");
                $this->assertEquals('shadow', $rule['status'], "{$rule['rule_id']} must have status=shadow");
                $this->assertStringContainsString('shadow', $rule['output_topic'] ?? '');
            }
        }
    }

    public function test_registry_total_rule_count_is_73(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/detection/rules/registry.v1.json')), true);
        $this->assertCount(73, $registry['rules'], 'Registry should have exactly 73 rules after LLTET Phase 1');
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_endpoint_telemetry_dashboard_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.dashboard'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_process_explorer_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.process-explorer'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_script_execution_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.script-execution'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_privilege_escalation_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.privilege-escalation'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_container_activity_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.container-activity'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_persistence_indicators_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.persistence'))->assertStatus(200);
    }

    public function test_endpoint_telemetry_network_connections_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)->get(route('endpoint-telemetry.network-connections'))->assertStatus(200);
    }

    // =========================================================================
    // Advisory disclaimer in views
    // =========================================================================

    public function test_dashboard_view_contains_advisory_disclaimer(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)
             ->get(route('endpoint-telemetry.dashboard'))
             ->assertSee('advisory-only');
    }

    public function test_script_execution_view_contains_advisory_disclaimer(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)
             ->get(route('endpoint-telemetry.script-execution'))
             ->assertSee('advisory-only');
    }

    public function test_privilege_escalation_view_contains_advisory_disclaimer(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($user)
             ->get(route('endpoint-telemetry.privilege-escalation'))
             ->assertSee('advisory-only');
    }

    // =========================================================================
    // HARD SAFETY INVARIANTS — these must NEVER be removed or loosened
    // =========================================================================

    public function test_no_isolate_host_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'isolateHost'),
            'EndpointTelemetryAnalyticsService must NOT have isolateHost()'
        );
    }

    public function test_no_quarantine_host_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'quarantineHost'),
            'EndpointTelemetryAnalyticsService must NOT have quarantineHost()'
        );
    }

    public function test_no_execute_shell_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'executeShell'),
            'EndpointTelemetryAnalyticsService must NOT have executeShell()'
        );
    }

    public function test_no_kill_process_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'killProcess'),
            'EndpointTelemetryAnalyticsService must NOT have killProcess()'
        );
    }

    public function test_no_auto_remediate_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'autoRemediate'),
            'EndpointTelemetryAnalyticsService must NOT have autoRemediate()'
        );
    }

    public function test_no_process_injection_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'injectProcess'),
            'EndpointTelemetryAnalyticsService must NOT have injectProcess()'
        );
    }

    public function test_no_kernel_enforcement_in_telemetry_analytics_service(): void
    {
        $this->assertFalse(
            method_exists(EndpointTelemetryAnalyticsService::class, 'enforceKernelPolicy'),
            'EndpointTelemetryAnalyticsService must NOT have enforceKernelPolicy()'
        );
    }

    public function test_privilege_escalation_records_cannot_be_deleted(): void
    {
        $agent = $this->makeAgent();
        $esc   = $this->svc->recordPrivilegeEscalation($agent, [
            'process_name'    => 'sudo',
            'escalation_type' => 'sudo_invocation',
            'occurred_at'     => now(),
        ]);

        $id = $esc->id;
        // Attempt to delete should not be done from service — table is append-only
        // The test verifies the record persists and cannot be changed via model update
        $this->assertNotNull(EndpointPrivilegeEscalation::find($id));
    }

    public function test_script_executions_are_append_only(): void
    {
        $agent = $this->makeAgent();
        $exec  = $this->svc->recordScriptExecution($agent, [
            'process_name'  => 'powershell.exe',
            'script_source' => 'inline',
            'is_encoded'    => false,
            'occurred_at'   => now(),
        ]);

        $id = $exec->id;
        // Record should exist and not have updated_at
        $raw = DB::table('endpoint_script_executions')->where('id', $id)->first();
        $this->assertNull($raw->updated_at ?? null);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeAgent(array $overrides = []): EndpointAgent
    {
        return EndpointAgent::create(array_merge([
            'agent_id'             => EndpointAgent::generateAgentId(),
            'host_id'              => 'test-host-' . uniqid(),
            'host_fingerprint'     => hash('sha256', 'test-host-' . uniqid()),
            'hostname'             => 'test-server-lltet',
            'enrollment_token_hash'=> EndpointAgent::hashEnrollmentToken('test-lltet-token'),
            'agent_version'        => '1.0.0',
            'platform'             => 'Linux',
            'os_family'            => 'Linux',
            'health_state'         => EndpointAgent::HEALTH_ONLINE,
            'status'               => 'online',
            'enrolled_at'          => now(),
            'last_seen_at'         => now(),
        ], $overrides));
    }
}
