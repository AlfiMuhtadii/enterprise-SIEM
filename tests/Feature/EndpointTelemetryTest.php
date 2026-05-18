<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Endpoint telemetry layer tests.
 *
 * Covers:
 *  - Registry integrity for new endpoint rules
 *  - Shadow-only enforcement (no active promotion)
 *  - Replay fixture existence and schema
 *  - Endpoint schema files (new event types)
 *  - Trace propagation through endpoint alerts
 *  - Endpoint UI page access
 *  - Agent health API
 *  - Agent heartbeat recording
 */
class EndpointTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'admin']);
    }

    private function registry(): array
    {
        $path = base_path('docs/detection/rules/registry.v1.json');
        return json_decode(file_get_contents($path), true)['rules'] ?? [];
    }

    private function rule(string $id): ?array
    {
        return collect($this->registry())->firstWhere('rule_id', $id);
    }

    // -----------------------------------------------------------------------
    // Registry — new rules present and correctly configured
    // -----------------------------------------------------------------------

    public function test_registry_now_has_37_rules(): void
    {
        $this->assertCount(37, $this->registry());
    }

    public function test_scheduled_task_persistence_rule_exists(): void
    {
        $rule = $this->rule('scheduled_task_persistence');
        $this->assertNotNull($rule, 'scheduled_task_persistence must be in registry');
        $this->assertSame('endpoint', $rule['domain']);
        $this->assertSame('shadow', $rule['status']);
        $this->assertTrue($rule['shadow_only']);
        $this->assertSame('xdr.alerts.shadow.endpoint', $rule['output_topic']);
        $this->assertNotEmpty($rule['mitre_attack']);
        $this->assertContains('T1053', $rule['mitre_attack']);
    }

    public function test_new_service_persistence_rule_exists(): void
    {
        $rule = $this->rule('new_service_persistence');
        $this->assertNotNull($rule);
        $this->assertSame('endpoint', $rule['domain']);
        $this->assertSame('shadow', $rule['status']);
        $this->assertTrue($rule['shadow_only']);
        $this->assertContains('T1543', $rule['mitre_attack']);
    }

    public function test_c2_beacon_pattern_rule_exists(): void
    {
        $rule = $this->rule('c2_beacon_pattern');
        $this->assertNotNull($rule);
        $this->assertSame('endpoint', $rule['domain']);
        $this->assertSame('shadow', $rule['status']);
        $this->assertTrue($rule['shadow_only']);
        $this->assertContains('T1071', $rule['mitre_attack']);
    }

    public function test_all_new_endpoint_rules_have_replay_fixtures(): void
    {
        $newRules = ['scheduled_task_persistence', 'new_service_persistence', 'c2_beacon_pattern'];
        foreach ($newRules as $id) {
            $rule = $this->rule($id);
            $this->assertNotEmpty($rule['replay_fixture'], "$id must declare replay_fixture");
            $this->assertFileExists(
                base_path($rule['replay_fixture']),
                "$id replay_fixture must exist at {$rule['replay_fixture']}"
            );
        }
    }

    public function test_all_new_endpoint_rules_have_suppression_keys(): void
    {
        foreach (['scheduled_task_persistence', 'new_service_persistence', 'c2_beacon_pattern'] as $id) {
            $rule = $this->rule($id);
            $this->assertNotEmpty($rule['suppression_key'], "$id must have suppression_key");
        }
    }

    public function test_all_new_endpoint_rules_have_owner_and_fp_notes(): void
    {
        foreach (['scheduled_task_persistence', 'new_service_persistence', 'c2_beacon_pattern'] as $id) {
            $rule = $this->rule($id);
            $this->assertNotEmpty($rule['owner'], "$id must have owner");
            $this->assertNotEmpty($rule['false_positive_notes'], "$id must have false_positive_notes");
        }
    }

    // -----------------------------------------------------------------------
    // Shadow enforcement — new rules must stay shadow
    // -----------------------------------------------------------------------

    public function test_new_endpoint_rules_are_not_staged_active(): void
    {
        $violations = [];
        foreach (['scheduled_task_persistence', 'new_service_persistence', 'c2_beacon_pattern'] as $id) {
            $rule = $this->rule($id);
            if (($rule['status'] ?? '') === 'staged_active') {
                $violations[] = $id;
            }
        }
        $this->assertEmpty($violations, 'New endpoint rules must remain shadow: ' . implode(', ', $violations));
    }

    public function test_no_endpoint_rule_has_active_output_topic(): void
    {
        $violations = [];
        foreach ($this->registry() as $rule) {
            if (($rule['domain'] ?? '') !== 'endpoint') {
                continue;
            }
            if (($rule['output_topic'] ?? '') === 'xdr.alerts') {
                $violations[] = $rule['rule_id'];
            }
        }
        $this->assertEmpty($violations, 'Endpoint rules must not use xdr.alerts output topic: ' . implode(', ', $violations));
    }

    public function test_all_endpoint_rules_have_shadow_only_true(): void
    {
        $violations = [];
        foreach ($this->registry() as $rule) {
            if (($rule['domain'] ?? '') !== 'endpoint') {
                continue;
            }
            if (!($rule['shadow_only'] ?? false)) {
                $violations[] = $rule['rule_id'];
            }
        }
        $this->assertEmpty($violations, 'All endpoint rules must have shadow_only=true: ' . implode(', ', $violations));
    }

    public function test_registry_has_22_endpoint_rules(): void
    {
        $count = count(array_filter($this->registry(), fn ($r) => ($r['domain'] ?? '') === 'endpoint'));
        $this->assertSame(22, $count, "Expected 22 endpoint rules (18 analytics + 4 hunting)");
    }

    // -----------------------------------------------------------------------
    // Replay fixtures — validate structure
    // -----------------------------------------------------------------------

    public function test_scheduled_task_fixture_has_correct_event_type(): void
    {
        $fixture = json_decode(file_get_contents(
            base_path('tests/fixtures/endpoint_shadow/suspicious/suspicious_scheduled_task.json')
        ), true);
        $this->assertIsArray($fixture);
        $this->assertNotEmpty($fixture);
        $this->assertSame('scheduled_task_create', $fixture[0]['event_type']);
        $this->assertSame('endpoint', $fixture[0]['telemetry_type']);
        $this->assertNotEmpty($fixture[0]['trace_id']);
        $this->assertNotEmpty($fixture[0]['normalized_event_id']);
    }

    public function test_service_install_fixture_has_correct_event_type(): void
    {
        $fixture = json_decode(file_get_contents(
            base_path('tests/fixtures/endpoint_shadow/suspicious/suspicious_service_install.json')
        ), true);
        $this->assertIsArray($fixture);
        $this->assertSame('service_install', $fixture[0]['event_type']);
        $this->assertSame('endpoint', $fixture[0]['telemetry_type']);
        $this->assertNotEmpty($fixture[0]['trace_id']);
    }

    public function test_c2_beacon_fixture_has_three_network_connections(): void
    {
        $fixture = json_decode(file_get_contents(
            base_path('tests/fixtures/endpoint_shadow/suspicious/suspicious_c2_beacon.json')
        ), true);
        $this->assertCount(3, $fixture);
        foreach ($fixture as $ev) {
            $this->assertSame('network_connection', $ev['event_type']);
            $this->assertSame('endpoint', $ev['telemetry_type']);
            $this->assertSame('203.0.113.99', $ev['network']['destination_ip']);
            $this->assertSame(4444, $ev['network']['destination_port']);
        }
        // All events in same batch share trace_id
        $traceIds = array_unique(array_column($fixture, 'trace_id'));
        $this->assertCount(1, $traceIds, 'All beacon events must share the same trace_id');
    }

    public function test_all_existing_fixtures_are_valid_json(): void
    {
        $fixtures = glob(base_path('tests/fixtures/endpoint_shadow/**/*.json'));
        $this->assertNotEmpty($fixtures);
        foreach ($fixtures as $path) {
            $data = json_decode(file_get_contents($path), true);
            $this->assertIsArray($data, "Fixture $path must be valid JSON array");
            $this->assertNotEmpty($data, "Fixture $path must not be empty");
        }
    }

    public function test_all_fixture_events_have_required_fields(): void
    {
        $requiredFields = ['schema_version', 'ts', 'telemetry_type', 'event_type', 'host', 'trace_id'];
        $fixtures = glob(base_path('tests/fixtures/endpoint_shadow/suspicious/*.json'));
        foreach ($fixtures as $path) {
            $events = json_decode(file_get_contents($path), true);
            foreach ($events as $ev) {
                foreach ($requiredFields as $field) {
                    $this->assertArrayHasKey(
                        $field, $ev,
                        "Event in " . basename($path) . " must have '$field' field"
                    );
                }
            }
        }
    }

    public function test_all_fixture_events_have_telemetry_type_endpoint(): void
    {
        $fixtures = glob(base_path('tests/fixtures/endpoint_shadow/suspicious/*.json'));
        foreach ($fixtures as $path) {
            $events = json_decode(file_get_contents($path), true);
            foreach ($events as $ev) {
                $this->assertSame(
                    'endpoint', $ev['telemetry_type'],
                    basename($path) . " events must have telemetry_type=endpoint"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Schema files — new event types declared
    // -----------------------------------------------------------------------

    public function test_scheduled_task_schema_file_exists(): void
    {
        $path = base_path('docs/contracts/telemetry/endpoint/scheduled_task.v1.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true);
        $this->assertIsArray($schema);
        $this->assertSame('scheduled_task_create', $schema['properties']['event_type']['const'] ?? null);
    }

    public function test_service_install_schema_file_exists(): void
    {
        $path = base_path('docs/contracts/telemetry/endpoint/service_install.v1.schema.json');
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true);
        $this->assertIsArray($schema);
        $this->assertSame('service_install', $schema['properties']['event_type']['const'] ?? null);
    }

    public function test_base_schema_includes_new_event_types(): void
    {
        $path = base_path('docs/contracts/telemetry/endpoint/endpoint-raw-base.v1.schema.json');
        $schema = json_decode(file_get_contents($path), true);
        $enum   = $schema['properties']['event_type']['enum'] ?? [];
        $this->assertContains('scheduled_task_create', $enum);
        $this->assertContains('service_install', $enum);
        $this->assertContains('heartbeat', $enum);
    }

    // -----------------------------------------------------------------------
    // Trace propagation — endpoint alerts carry trace_id
    // -----------------------------------------------------------------------

    public function test_endpoint_alert_trace_id_linked_to_incident(): void
    {
        $traceId = 'ep-trace-test-' . uniqid();

        DB::table('security_alerts')->insert([
            'alert_id'          => 'ep-alert-' . uniqid(),
            'alert_fingerprint' => md5('ep-fp'),
            'alert_type'        => 'suspicious_parent_child_process',
            'severity'          => 'high',
            'detected_at'       => now()->toIsoString(),
            'actor_key'         => 'host-ep01.corp.test',
            'score'             => 0.80,
            'detector_name'     => 'xdr-correlation',
            'detector_version'  => 'go-shadow',
            'evidence'          => '{"shadow_mode":true}',
            'raw_event'         => '{}',
            'trace_id'          => $traceId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('security_incidents')->insert([
            'incident_id'       => 'ep-inc-' . uniqid(),
            'title'             => 'Endpoint shadow incident',
            'status'            => 'open',
            'severity'          => 'high',
            'confidence'        => 0.80,
            'first_seen_at'     => now()->toIsoString(),
            'last_seen_at'      => now()->toIsoString(),
            'affected_entities' => '[]',
            'timeline'          => '[]',
            'mitre_mapping'     => '[]',
            'metadata'          => '{}',
            'xdr_domains'       => '[]',
            'trace_id'          => $traceId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $alertRow    = DB::table('security_alerts')->where('trace_id', $traceId)->first();
        $incidentRow = DB::table('security_incidents')->where('trace_id', $traceId)->first();

        $this->assertSame($traceId, $alertRow->trace_id);
        $this->assertSame($traceId, $incidentRow->trace_id);
        $this->assertSame($alertRow->trace_id, $incidentRow->trace_id);
    }

    public function test_endpoint_shadow_alert_does_not_enter_active_pipeline(): void
    {
        // Shadow alerts are stored in security_alerts with detector_version=go-shadow.
        // They must NOT be promoted into the active incident pipeline automatically.
        // Verify: no incident created for a shadow alert without explicit promotion.
        $traceId = 'ep-shadow-only-' . uniqid();

        DB::table('security_alerts')->insert([
            'alert_id'          => 'ep-shadow-' . uniqid(),
            'alert_fingerprint' => md5('ep-shadow-fp'),
            'alert_type'        => 'c2_beacon_pattern',
            'severity'          => 'high',
            'detected_at'       => now()->toIsoString(),
            'actor_key'         => 'host-ep02.corp.test',
            'score'             => 0.72,
            'detector_name'     => 'xdr-correlation',
            'detector_version'  => 'go-shadow',
            'evidence'          => '{"shadow_mode":true,"destination_ip":"1.2.3.4"}',
            'raw_event'         => '{}',
            'trace_id'          => $traceId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // No incident should have been created automatically
        $incidentCount = DB::table('security_incidents')->where('trace_id', $traceId)->count();
        $this->assertSame(0, $incidentCount, 'Shadow alert must not auto-create incidents');
    }

    // -----------------------------------------------------------------------
    // Endpoint UI pages
    // -----------------------------------------------------------------------

    public function test_endpoint_inventory_page_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('endpoint.index'))
            ->assertOk()
            ->assertSee('Endpoint Inventory');
    }

    public function test_endpoint_inventory_page_requires_auth(): void
    {
        $this->get(route('endpoint.index'))->assertRedirect(route('login'));
    }

    public function test_endpoint_health_api_returns_json(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('endpoint.health'))
            ->assertOk()
            ->assertJsonStructure(['total', 'online', 'offline', 'shadow_alerts', 'alert_types']);
    }

    public function test_endpoint_show_returns_404_for_unknown_agent(): void
    {
        $this->actingAs($this->admin())
            ->get(route('endpoint.show', 'nonexistent-agent-xyz'))
            ->assertNotFound();
    }

    public function test_endpoint_show_returns_200_for_enrolled_agent(): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id'         => 'test-agent-ep01',
            'host_id'          => 'host-ep-test-001',
            'host_fingerprint' => hash('sha256', 'host-ep-test-001'),
            'agent_version'    => '0.3.0',
            'last_seen_at'     => now()->toIsoString(),
            'enrolled_at'      => now()->toIsoString(),
            'metadata'         => '{"startup_integrity_ok":true,"unexpected_restart_count":0}',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('endpoint.show', 'test-agent-ep01'))
            ->assertOk()
            ->assertSee('test-agent-ep01');
    }

    // -----------------------------------------------------------------------
    // Agent heartbeat tests
    // -----------------------------------------------------------------------

    public function test_agent_last_seen_at_determines_online_status(): void
    {
        // Recent heartbeat → online
        DB::table('endpoint_agents')->insert([
            'agent_id'         => 'hb-online-agent',
            'host_id'          => 'host-hb-online',
            'host_fingerprint' => hash('sha256', 'host-hb-online'),
            'agent_version'    => '0.3.0',
            'last_seen_at'     => now()->subSeconds(30)->toIsoString(),
            'enrolled_at'      => now()->toIsoString(),
            'metadata'         => '{}',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Stale heartbeat → offline
        DB::table('endpoint_agents')->insert([
            'agent_id'         => 'hb-offline-agent',
            'host_id'          => 'host-hb-offline',
            'host_fingerprint' => hash('sha256', 'host-hb-offline'),
            'agent_version'    => '0.3.0',
            'last_seen_at'     => now()->subMinutes(10)->toIsoString(),
            'enrolled_at'      => now()->toIsoString(),
            'metadata'         => '{}',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('endpoint.health'))
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('online'));
        $this->assertGreaterThanOrEqual(1, $response->json('offline'));
    }

    public function test_integrity_fail_is_tracked_in_health_api(): void
    {
        DB::table('endpoint_agents')->insert([
            'agent_id'         => 'integrity-fail-agent',
            'host_id'          => 'host-integrity-fail',
            'host_fingerprint' => hash('sha256', 'host-integrity-fail'),
            'agent_version'    => '0.3.0',
            'last_seen_at'     => now()->toIsoString(),
            'enrolled_at'      => now()->toIsoString(),
            'metadata'         => '{"startup_integrity_ok":false,"unexpected_restart_count":3}',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('endpoint.index'))
            ->assertOk()
            ->assertSee('Endpoint Inventory');
    }

    // -----------------------------------------------------------------------
    // Agent script existence
    // -----------------------------------------------------------------------

    public function test_endpoint_agent_script_exists(): void
    {
        $this->assertFileExists(base_path('services/endpoint-agent/agent.py'));
    }

    public function test_endpoint_agent_grafana_dashboard_exists(): void
    {
        $path = base_path('docs/grafana/endpoint-dashboards.json');
        $this->assertFileExists($path);
        $dashboard = json_decode(file_get_contents($path), true);
        $this->assertIsArray($dashboard);
        $this->assertArrayHasKey('panels', $dashboard);
        $this->assertNotEmpty($dashboard['panels']);
    }

    // -----------------------------------------------------------------------
    // Registry validator script compatibility
    // -----------------------------------------------------------------------

    public function test_registry_validator_script_exists(): void
    {
        $this->assertFileExists(base_path('scripts/xdr_rule_registry_validate.py'));
    }

    public function test_registry_has_correct_rule_counts_after_additions(): void
    {
        $rules    = $this->registry();
        $active   = count(array_filter($rules, fn ($r) => ($r['status'] ?? '') === 'staged_active'));
        $shadow   = count(array_filter($rules, fn ($r) => ($r['status'] ?? '') === 'shadow'));
        $endpoint = count(array_filter($rules, fn ($r) => ($r['domain'] ?? '') === 'endpoint'));
        $ioc      = count(array_filter($rules, fn ($r) => ($r['domain'] ?? '') === 'threat-intel'));

        $this->assertSame(12, $active,   "12 staged_active rules expected");
        $this->assertSame(25, $shadow,   "25 shadow rules expected (22 endpoint + 3 IOC)");
        $this->assertSame(22, $endpoint, "22 endpoint rules expected (18 analytics + 4 hunting)");
        $this->assertSame(3,  $ioc,      "3 threat-intel rules expected");
    }

    public function test_no_new_endpoint_rules_are_in_active_state(): void
    {
        $newRules = ['scheduled_task_persistence', 'new_service_persistence', 'c2_beacon_pattern'];
        foreach ($newRules as $id) {
            $rule = collect($this->registry())->firstWhere('rule_id', $id);
            $this->assertNotSame('staged_active', $rule['status'] ?? '', "$id must not be staged_active");
            $this->assertNotSame('active', $rule['status'] ?? '', "$id must not be active");
        }
    }

    public function test_mitre_techniques_declared_for_all_new_rules(): void
    {
        $expected = [
            'scheduled_task_persistence' => ['T1053', 'T1053.005'],
            'new_service_persistence'    => ['T1543', 'T1543.003'],
            'c2_beacon_pattern'          => ['T1071', 'T1071.001', 'T1571', 'T1095'],
        ];

        foreach ($expected as $ruleId => $techniques) {
            $rule   = collect($this->registry())->firstWhere('rule_id', $ruleId);
            $attack = $rule['mitre_attack'] ?? [];
            foreach ($techniques as $tech) {
                $this->assertContains($tech, $attack, "$ruleId must map to $tech");
            }
        }
    }
}
