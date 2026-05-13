<?php

namespace Tests\Feature;

use App\Support\XdrCorrelationCutover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XdrMaturityOperationalTest extends TestCase
{
    use RefreshDatabase;

    public function test_xdr_maturity_commands_record_operational_metrics(): void
    {
        foreach ([
            'xdr_stream_reliability_metrics',
            'xdr_detection_rule_maturity',
            'xdr_identity_risk_timelines',
            'xdr_attack_reconstructions',
            'xdr_storage_maturity_metrics',
            'xdr_recovery_reports',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }

        $baseEvent = [
            'src_ip' => null,
            'dst_ip' => null,
            'dst_port' => null,
            'protocol' => null,
            'process_name' => null,
            'user_name_hash' => null,
            'xdr_host' => null,
            'destination_ip' => null,
            'domain' => null,
            'file_hash' => null,
            'email_sender' => null,
            'email_recipient' => null,
            'cloud_account' => null,
            'xdr_action' => null,
            'xdr_result' => null,
        ];
        DB::table('telemetry_events')->insert([
            $baseEvent + [
                'ts' => now()->subMinutes(20),
                'event_id' => 'maturity-identity-1',
                'telemetry_type' => 'identity',
                'event_type' => 'login_success',
                'host_id' => 'azure-ad',
                'xdr_user' => 'maturity@example.com',
                'source_ip' => '198.51.100.9',
                'risk_score' => 0.9,
                'event_source' => 'azure-signin',
                'payload' => json_encode(['test' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $baseEvent + [
                'ts' => now()->subMinutes(10),
                'event_id' => 'maturity-cloud-1',
                'telemetry_type' => 'cloud',
                'event_type' => 'privilege_escalation',
                'host_id' => 'aws',
                'xdr_user' => 'maturity@example.com',
                'cloud_account' => 'acct-1',
                'source_ip' => '198.51.100.9',
                'xdr_action' => 'admin_role_change',
                'risk_score' => 0.85,
                'event_source' => 'aws-cloudtrail',
                'payload' => json_encode(['test' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('security_alerts')->insert([
            'alert_id' => 'maturity-alert-1',
            'detected_at' => now(),
            'alert_type' => 'XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD',
            'detector_name' => 'xdr-correlation',
            'detector_version' => 'test',
            'severity' => 'high',
            'actor_key' => 'maturity@example.com',
            'incident_id' => 'xdr-maturity-incident',
            'score' => 0.9,
            'evidence' => json_encode([
                'xdr_domains' => ['identity', 'cloud'],
                'evidence_chain' => [
                    ['event_id' => 'maturity-identity-1', 'telemetry_type' => 'identity', 'user' => 'maturity@example.com', 'source_ip' => '198.51.100.9'],
                    ['event_id' => 'maturity-cloud-1', 'telemetry_type' => 'cloud', 'user' => 'maturity@example.com', 'cloud_account' => 'acct-1'],
                ],
            ]),
            'raw_event' => json_encode(['test' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('xdr:stream-metrics')->assertSuccessful();
        $this->artisan('xdr:stream-maturity --consumers=2 --partitions=4 --replay-events=1000')->assertSuccessful();
        $this->artisan('xdr:large-scale-validate --normal=1000 --malicious=100 --duration-minutes=10 --noise=0.2')->assertSuccessful();
        $this->artisan('xdr:rule-maturity --environment=staging')->assertSuccessful();
        $this->artisan('xdr:identity-risk --minutes=1440')->assertSuccessful();
        $this->artisan('xdr:attack-reconstruct --minutes=1440')->assertSuccessful();
        $this->artisan('xdr:storage-maturity')->assertSuccessful();
        $this->artisan('xdr:recovery-validate --scenario=node-restart')->assertSuccessful();
        $this->artisan('xdr:strangler-status')->assertSuccessful();

        $this->assertDatabaseHas('xdr_stream_reliability_metrics', ['topic' => 'telemetry.raw']);
        $this->assertDatabaseHas('xdr_detection_rule_maturity', ['rule_id' => 'xdr.email.identity.endpoint_chain']);
        $this->assertDatabaseHas('xdr_identity_risk_timelines', ['user_key' => 'maturity@example.com']);
        $this->assertDatabaseHas('xdr_attack_reconstructions', ['incident_id' => 'xdr-maturity-incident']);
        $this->assertDatabaseHas('xdr_storage_maturity_metrics', ['store_name' => 'raw_telemetry']);
        $this->assertDatabaseHas('xdr_recovery_reports', ['scenario' => 'node-restart']);
        $this->assertDatabaseHas('xdr_service_health', ['service_name' => 'ingestion-gateway']);
    }

    public function test_dashboard_exposes_xdr_maturity_visibility(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        DB::table('xdr_stream_reliability_metrics')->insert([
            'run_id' => 'run-test',
            'topic' => 'telemetry.raw',
            'consumer_group' => 'telemetry-raw-consumer',
            'partition_count' => 4,
            'parallel_consumers' => 2,
            'partition_lag' => 10,
            'retry_count' => 0,
            'dead_letter_count' => 0,
            'rebalance_count' => 1,
            'backpressure_ratio' => 0.1,
            'replay_pressure' => 0.2,
            'throughput_eps' => 100,
            'saturation_ratio' => 0.2,
            'status' => 'healthy',
            'warnings' => json_encode([]),
            'measured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($analyst)
            ->get('/soc')
            ->assertOk()
            ->assertSee('Stream Maturity')
            ->assertSee('Rule Lifecycle')
            ->assertSee('Identity Risk')
            ->assertSee('Recovery / Attack Flow')
            ->assertSee('Service Extraction');
    }

    public function test_strangler_service_scaffold_files_exist(): void
    {
        $root = base_path();
        foreach ([
            'services/ingestion-gateway/main.go',
            'services/normalizer-worker/main.go',
            'services/correlation-worker/main.go',
            'services/alert-writer-service/main.py',
            'services/incident-builder-service/main.py',
            'services/ai-rag-service/main.py',
            'scripts/xdr_generate_realistic_dataset.py',
            'scripts/xdr_operational_validate.py',
            'scripts/xdr_correlation_shadow_benchmark.py',
            'scripts/xdr_generate_identity_cloud_golden.py',
            'scripts/xdr_generate_endpoint_dns_proxy_golden.py',
            'scripts/xdr_endpoint_dns_proxy_shadow_prep.py',
            'samples/golden/xdr_identity_cloud_golden.jsonl',
            'samples/golden/xdr_endpoint_dns_proxy_golden.jsonl',
            'reports/xdr_endpoint_dns_proxy_shadow_prep.json',
            'README_XDR_STRANGLER_OPERATIONAL_VALIDATION.md',
            'README_XDR_SERVICE_MIGRATION_RUNBOOK.md',
        ] as $path) {
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        }
    }

    public function test_service_migration_visibility_does_not_change_identity_cloud_cutover_default(): void
    {
        $this->assertSame('shadow', config('xdr.correlation.engine'));
        $this->assertArrayHasKey('alert-writer', config('xdr.services'));
        $this->assertArrayHasKey('incident-builder', config('xdr.services'));
        $this->assertContains('xdr.alerts', config('xdr.services.alert-writer.consumes'));
        $this->assertContains('alerts.created', config('xdr.services.incident-builder.consumes'));

        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->get('/soc')
            ->assertOk()
            ->assertSee('Separated Service Migration')
            ->assertSee('Endpoint/DNS/Proxy Shadow');
    }

    public function test_identity_cloud_cutover_falls_back_and_audits_when_go_is_unhealthy(): void
    {
        Http::fake([
            'http://127.0.0.1:8093/health' => Http::response(['status' => 'down'], 503),
        ]);

        config([
            'xdr.correlation.engine' => 'go',
            'xdr.correlation.scope' => 'identity-cloud',
            'xdr.correlation.fallback_to_legacy' => true,
            'xdr.correlation.fallback_failure_threshold' => 1,
        ]);

        $status = XdrCorrelationCutover::status();
        $this->assertSame('go', $status['configured_engine']);
        $this->assertSame('legacy', $status['effective_engine']);
        $this->assertTrue($status['fallback_active']);

        $this->artisan('xdr:correlation-cutover-status --json')->assertSuccessful();

        $this->assertDatabaseHas('security_audit_trails', [
            'action' => 'xdr.correlation.rollback_auto',
            'target_type' => 'xdr_correlation_cutover',
            'target_id' => 'identity-cloud',
        ]);
    }

    public function test_identity_cloud_cutover_accepts_healthy_go_shadow_gate(): void
    {
        Http::fake([
            'http://127.0.0.1:8093/health' => Http::response(['status' => 'ok', 'service' => 'xdr-correlation'], 200),
        ]);

        config([
            'xdr.correlation.engine' => 'shadow',
            'xdr.correlation.scope' => 'identity-cloud',
        ]);

        $status = XdrCorrelationCutover::status();
        $this->assertSame('shadow', $status['configured_engine']);
        $this->assertSame('legacy', $status['effective_engine']);
        $this->assertSame('go_shadow', $status['comparison_engine']);

        $this->artisan('xdr:correlation-cutover-status --json --audit=0')->assertSuccessful();
    }
}
