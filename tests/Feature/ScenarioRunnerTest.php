<?php

namespace Tests\Feature;

use App\Jobs\ExecuteScenarioRunJob;
use App\Models\ScenarioEvidence;
use App\Models\ScenarioRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScenarioRunnerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);

        // Disable stage sleep so tests complete instantly
        config(['scenarios.stage_delay_ms' => 0]);
    }

    // -------------------------------------------------------------------------
    // store() — dispatch path
    // -------------------------------------------------------------------------

    public function test_store_creates_pending_run_and_dispatches_job(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)
            ->post('/scenario/runs', [
                'scenario_id' => 'sql_injection_emulation',
                'run_mode'    => 'live',
            ]);

        $run = ScenarioRun::first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);
        $this->assertSame('sql_injection_emulation', $run->scenario_id);
        $this->assertSame('live', $run->run_mode);
        $this->assertStringStartsWith('scenario-', $run->trace_id);
        $this->assertNotNull($run->started_at);

        Bus::assertDispatched(ExecuteScenarioRunJob::class, function ($job) use ($run) {
            return $job->runId === $run->id;
        });

        $response->assertRedirect(route('scenario.runs.timeline', $run->id));
    }

    public function test_store_rejects_unknown_scenario(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post('/scenario/runs', [
                'scenario_id' => 'does_not_exist',
                'run_mode'    => 'live',
            ])
            ->assertNotFound();

        Bus::assertNothingDispatched();
    }

    public function test_store_requires_scenario_run_permission(): void
    {
        Bus::fake();

        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post('/scenario/runs', [
                'scenario_id' => 'sql_injection_emulation',
                'run_mode'    => 'live',
            ])
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_replay_run_gets_replay_prefix_on_trace_id(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post('/scenario/runs', [
                'scenario_id' => 'failed_login_burst',
                'run_mode'    => 'replay',
            ]);

        $run = ScenarioRun::first();
        $this->assertStringStartsWith('replay-', $run->trace_id);
        $this->assertSame('replay', $run->run_mode);
    }

    // -------------------------------------------------------------------------
    // ExecuteScenarioRunJob — job behaviour
    // -------------------------------------------------------------------------

    public function test_job_creates_one_evidence_row_per_pipeline_stage(): void
    {
        $run = $this->makePendingRun('sql_injection_emulation');

        (new ExecuteScenarioRunJob($run->id))->handle();

        $scenario = collect(config('scenarios.scenarios'))->firstWhere('id', 'sql_injection_emulation');
        $stages   = $scenario['pipeline_stages'];

        $this->assertCount(count($stages), ScenarioEvidence::where('scenario_run_id', $run->id)->get());

        foreach ($stages as $stage) {
            $this->assertDatabaseHas('scenario_evidence', [
                'scenario_run_id' => $run->id,
                'stage'           => $stage,
                'status'          => 'detected',
            ]);
        }
    }

    public function test_job_marks_run_completed_pass(): void
    {
        $run = $this->makePendingRun('sql_injection_emulation');

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('PASS', $run->validation_result);
        $this->assertTrue($run->detection_passed);
        $this->assertGreaterThan(0, $run->alerts_detected);
        $this->assertNotNull($run->completed_at);
        $this->assertNotNull($run->results);
        $this->assertArrayHasKey('rule_matched', $run->results);
    }

    public function test_job_sets_correct_rule_and_severity_on_evidence(): void
    {
        $run      = $this->makePendingRun('suspicious_powershell_event');
        $scenario = collect(config('scenarios.scenarios'))->firstWhere('id', 'suspicious_powershell_event');

        (new ExecuteScenarioRunJob($run->id))->handle();

        $correlationEvidence = ScenarioEvidence::where('scenario_run_id', $run->id)
            ->where('stage', 'correlation')
            ->first();

        $this->assertNotNull($correlationEvidence);
        $this->assertSame($scenario['expected_detection']['rule'], $correlationEvidence->rule_id);
        $this->assertNotNull($correlationEvidence->payload);
    }

    public function test_job_evidence_rows_have_latency_ms_set(): void
    {
        $run = $this->makePendingRun('ioc_match');

        (new ExecuteScenarioRunJob($run->id))->handle();

        $evidence = ScenarioEvidence::where('scenario_run_id', $run->id)->get();

        foreach ($evidence as $ev) {
            $this->assertNotNull($ev->latency_ms);
            $this->assertGreaterThan(0, $ev->latency_ms);
        }
    }

    public function test_job_marks_run_failed_when_scenario_definition_not_found(): void
    {
        $run = ScenarioRun::create([
            'scenario_id' => 'nonexistent_scenario_xyz',
            'user_id'     => $this->user->id,
            'status'      => 'pending',
            'run_mode'    => 'live',
            'trace_id'    => 'test-fail-trace',
            'started_at'  => now(),
        ]);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('FAIL', $run->validation_result);
        $this->assertNotNull($run->failure_reason);
        $this->assertStringContainsString('nonexistent_scenario_xyz', $run->failure_reason);
    }

    public function test_job_failed_hook_marks_run_failed(): void
    {
        $run = $this->makePendingRun('sql_injection_emulation');

        $job = new ExecuteScenarioRunJob($run->id);
        $job->failed(new \RuntimeException('Simulated queue worker failure'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->failure_reason);
        $this->assertStringContainsString('Simulated queue worker failure', $run->failure_reason);
    }

    public function test_job_preserves_completed_stages_on_failure(): void
    {
        // Partially-completed run: 3 evidence rows already exist before failure hook fires
        $run = $this->makePendingRun('sql_injection_emulation');

        ScenarioEvidence::create([
            'scenario_run_id' => $run->id,
            'stage'           => 'ingestion',
            'event_id'        => 'evt-test',
            'trace_id'        => $run->trace_id,
            'status'          => 'detected',
            'processed_at'    => now(),
        ]);

        $job = new ExecuteScenarioRunJob($run->id);
        $job->failed(new \RuntimeException('Worker died mid-pipeline'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('PARTIAL', $run->validation_result); // has some completed stages
        $this->assertSame(1, $run->results['stages_completed']);
    }

    public function test_stopped_run_is_skipped_by_job(): void
    {
        $run = $this->makePendingRun('sql_injection_emulation');
        $run->update(['status' => 'stopped']);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('stopped', $run->status); // unchanged
        $this->assertCount(0, $run->evidence);       // no evidence created
    }

    public function test_job_is_idempotent_for_completed_run(): void
    {
        $run = $this->makePendingRun('sql_injection_emulation');
        $run->update(['status' => 'completed']);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $this->assertCount(0, $run->fresh()->evidence); // job exits early, no evidence
    }

    // -------------------------------------------------------------------------
    // Gate / @can integration
    // -------------------------------------------------------------------------

    public function test_soc_gate_allows_admin_scenario_run(): void
    {
        $this->actingAs($this->user);
        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('soc:scenario.run'));
    }

    public function test_soc_gate_denies_viewer_scenario_run(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);
        $this->assertFalse(\Illuminate\Support\Facades\Gate::allows('soc:scenario.run'));
    }

    public function test_soc_gate_allows_scenario_operator_to_run_and_replay(): void
    {
        $operator = User::factory()->create(['role' => 'scenario_operator']);
        $this->actingAs($operator);

        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('soc:scenario.run'));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('soc:scenario.replay'));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::allows('soc:scenario.evidence.view'));
    }

    // -------------------------------------------------------------------------
    // Stub mode — stage_type field
    // -------------------------------------------------------------------------

    public function test_stub_mode_sets_simulated_stage_type_on_all_evidence(): void
    {
        config(['scenarios.pipeline_mode' => 'stub', 'scenarios.stage_delay_ms' => 0]);

        $run = $this->makePendingRun('sql_injection_emulation');
        (new ExecuteScenarioRunJob($run->id))->handle();

        $types = ScenarioEvidence::where('scenario_run_id', $run->id)->pluck('stage_type')->unique()->values();
        $this->assertCount(1, $types);
        $this->assertSame('simulated_runner_stage', $types->first());
    }

    public function test_stub_mode_does_not_write_to_soc_tables(): void
    {
        // Stub mode only writes scenario_evidence — no fake security_alerts/incidents.
        // SOC artifacts come from the real pipeline in real mode only.
        config(['scenarios.pipeline_mode' => 'stub', 'scenarios.stage_delay_ms' => 0]);

        $alertsBefore    = DB::table('security_alerts')->count();
        $incidentsBefore = DB::table('security_incidents')->count();

        $run = $this->makePendingRun('sql_injection_emulation');
        (new ExecuteScenarioRunJob($run->id))->handle();

        // The job must not add any new rows to SOC tables
        $this->assertSame($alertsBefore,    DB::table('security_alerts')->count());
        $this->assertSame($incidentsBefore, DB::table('security_incidents')->count());
    }

    // -------------------------------------------------------------------------
    // Real mode — gateway + polling
    // -------------------------------------------------------------------------

    private function realModeConfig(string $traceId = ''): void
    {
        config([
            'scenarios.pipeline_mode'             => 'real',
            'scenarios.ingestion_gateway_url'     => 'http://test-gw:8091',
            'scenarios.ingestion_gateway_secret'  => '', // no signature required in test
            'scenarios.pipeline_timeout_seconds'  => 2,
            'scenarios.pipeline_poll_ms'          => 50,
            'scenarios.stage_delay_ms'            => 0,
        ]);
    }

    public function test_real_mode_publishes_to_ingestion_gateway_and_marks_pass(): void
    {
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1, 'latency_ms' => 4], 202),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');

        // Seed alert that simulates the real pipeline completing.
        // Polling uses actor_key + alert_type + detected_at >= started_at.
        DB::table('security_alerts')->insert([
            'alert_id'    => 'real-alert-test-pass',
            'alert_type'  => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'actor_key'   => 'scenario-actor@test.local',
            'detected_at' => now(),
            'severity'    => 'high',
            'score'       => 0.85,
            'evidence'    => json_encode(['rule' => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS']),
            'raw_event'   => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('PASS', $run->validation_result);
        $this->assertTrue($run->detection_passed);
        $this->assertSame('real_pipeline', $run->results['run_type']);

        // Gateway was called
        Http::assertSent(function (ClientRequest $req) {
            return str_ends_with($req->url(), '/v1/ingest')
                && $req->method() === 'POST';
        });

        // Ingestion evidence is real_pipeline_stage
        $this->assertDatabaseHas('scenario_evidence', [
            'scenario_run_id' => $run->id,
            'stage'           => 'ingestion',
            'stage_type'      => 'real_pipeline_stage',
            'status'          => 'detected',
        ]);

        // Alert evidence recorded
        $this->assertDatabaseHas('scenario_evidence', [
            'scenario_run_id' => $run->id,
            'stage'           => 'xdr.alerts',
            'stage_type'      => 'real_pipeline_stage',
            'status'          => 'detected',
        ]);
    }

    public function test_real_mode_includes_trace_id_in_published_payload(): void
    {
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1], 202),
        ]);

        $run = $this->makePendingRun('failed_login_burst');

        // Seed alert so it doesn't hang on poll
        DB::table('security_alerts')->insert([
            'alert_id'    => 'real-alert-test-trace',
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'actor_key'   => 'scenario-actor@test.local',
            'detected_at' => now(),
            'severity'    => 'high',
            'score'       => 0.90,
            'evidence'    => json_encode([]),
            'raw_event'   => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        (new ExecuteScenarioRunJob($run->id))->handle();

        Http::assertSent(function (ClientRequest $req) use ($run) {
            $body = json_decode($req->body(), true);
            // Body is array of events; first event must carry trace_id and source
            return is_array($body)
                && ($body[0]['trace_id'] ?? '') === $run->trace_id
                && ($body[0]['source']   ?? '') === 'xdr-scenario-runner';
        });
    }

    public function test_real_mode_marks_partial_when_no_alert_within_timeout(): void
    {
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1], 202),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');
        // No alert seeded — simulates pipeline not responding

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('PARTIAL', $run->validation_result);
        $this->assertFalse((bool) $run->detection_passed);
        $this->assertStringContainsString('CLOUD_SUSPICIOUS_OBJECT_ACCESS', $run->failure_reason);
        $this->assertStringContainsString('timeout', strtolower($run->failure_reason));

        // Ingestion still recorded as successful
        $this->assertDatabaseHas('scenario_evidence', [
            'scenario_run_id' => $run->id,
            'stage'           => 'ingestion',
            'status'          => 'detected',
        ]);
    }

    public function test_real_mode_marks_fail_when_gateway_returns_error(): void
    {
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response('Bad Gateway', 502),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('FAIL', $run->validation_result);
        $this->assertStringContainsString('502', $run->failure_reason);

        // Failed ingestion evidence recorded
        $this->assertDatabaseHas('scenario_evidence', [
            'scenario_run_id' => $run->id,
            'stage'           => 'ingestion',
            'stage_type'      => 'real_pipeline_stage',
            'status'          => 'failed',
        ]);
    }

    public function test_real_mode_https_fails_closed_when_mtls_identity_is_missing(): void
    {
        $this->realModeConfig();
        config([
            'scenarios.ingestion_gateway_url' => 'https://test-gw:8091',
            'xdr.internal_mtls.enabled' => true,
            'xdr.internal_mtls.ca_cert' => storage_path('missing-scenario-ca.crt'),
            'xdr.internal_mtls.client_cert' => storage_path('missing-scenario-client.crt'),
            'xdr.internal_mtls.client_key' => storage_path('missing-scenario-client.key'),
        ]);
        Http::fake([
            'https://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1], 202),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');
        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('FAIL', $run->validation_result);
        $this->assertStringContainsString('Internal mTLS CA certificate not found', $run->failure_reason);
        Http::assertNothingSent();
    }

    public function test_real_mode_records_incident_when_alert_has_incident_id(): void
    {
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1], 202),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');

        DB::table('security_incidents')->insert([
            'incident_id'  => 'inc-real-test-001',
            'title'        => 'SQL Injection Detected',
            'status'       => 'open',
            'severity'     => 'high',
            'first_seen_at'=> now(),
            'last_seen_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('security_alerts')->insert([
            'alert_id'    => 'real-alert-test-incident',
            'alert_type'  => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'actor_key'   => 'scenario-actor@test.local',
            'incident_id' => 'inc-real-test-001',
            'detected_at' => now(),
            'severity'    => 'high',
            'score'       => 0.85,
            'evidence'    => json_encode([]),
            'raw_event'   => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('PASS', $run->validation_result);
        $this->assertSame('inc-real-test-001', $run->results['incident_id']);

        $this->assertDatabaseHas('scenario_evidence', [
            'scenario_run_id' => $run->id,
            'stage'           => 'incidents.updated',
            'stage_type'      => 'real_pipeline_stage',
            'status'          => 'detected',
        ]);
    }

    public function test_real_mode_marks_partial_when_different_rule_fires_but_expected_absent(): void
    {
        // Polling matches actor_key + expected alert_type. If a different rule fires
        // but the expected rule does not, the expected alert is never found → PARTIAL.
        $this->realModeConfig();

        Http::fake([
            'http://test-gw:8091/v1/ingest' => Http::response(['accepted' => 1], 202),
        ]);

        $run = $this->makePendingRun('sql_injection_emulation');

        // A different rule fires for this actor — expected rule absent
        DB::table('security_alerts')->insert([
            'alert_id'    => 'real-alert-wrong-rule',
            'alert_type'  => 'SOME_OTHER_RULE',
            'actor_key'   => 'scenario-actor@test.local',
            'detected_at' => now(),
            'severity'    => 'high',
            'score'       => 0.80,
            'evidence'    => json_encode([]),
            'raw_event'   => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        (new ExecuteScenarioRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('PARTIAL', $run->validation_result);
        $this->assertFalse((bool) $run->detection_passed);
        // Reason is timeout (expected rule not found), not a mismatch message
        $this->assertStringContainsString('CLOUD_SUSPICIOUS_OBJECT_ACCESS', $run->failure_reason);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makePendingRun(string $scenarioId): ScenarioRun
    {
        return ScenarioRun::create([
            'scenario_id' => $scenarioId,
            'user_id'     => $this->user->id,
            'status'      => 'pending',
            'run_mode'    => 'live',
            'trace_id'    => 'test-trace-' . $scenarioId . '-' . uniqid(),
            'started_at'  => now(),
        ]);
    }
}
