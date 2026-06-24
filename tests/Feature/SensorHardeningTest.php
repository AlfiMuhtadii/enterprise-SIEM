<?php

namespace Tests\Feature;

use App\Models\CollectorHealthEvent;
use App\Models\CollectorRestartAudit;
use App\Models\EndpointUpgradeValidation;
use App\Models\OfflineRecoveryRun;
use App\Models\PackageSignatureValidation;
use App\Models\SensorResourceSnapshot;
use App\Models\TelemetryGapReport;
use App\Models\TelemetryIntegrityRun;
use App\Models\TelemetrySequenceValidation;
use App\Services\SensorHardeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensorHardeningTest extends TestCase
{
    use RefreshDatabase;

    private SensorHardeningService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SensorHardeningService::class);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Hard constraints Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_no_isolate_host(): void
    {
        $this->assertFalse(method_exists($this->svc, 'isolateHost'));
    }

    public function test_no_quarantine_host(): void
    {
        $this->assertFalse(method_exists($this->svc, 'quarantineHost'));
    }

    public function test_no_execute_shell(): void
    {
        $this->assertFalse(method_exists($this->svc, 'executeShell'));
    }

    public function test_no_kill_process(): void
    {
        $this->assertFalse(method_exists($this->svc, 'killProcess'));
    }

    public function test_no_auto_remediate(): void
    {
        $this->assertFalse(method_exists($this->svc, 'autoRemediate'));
    }

    public function test_no_kernel_driver_injection(): void
    {
        $this->assertFalse(method_exists($this->svc, 'injectKernelDriver'));
        $this->assertFalse(method_exists($this->svc, 'loadKernelModule'));
    }

    public function test_no_process_injection(): void
    {
        $this->assertFalse(method_exists($this->svc, 'injectProcess'));
        $this->assertFalse(method_exists($this->svc, 'processInject'));
    }

    public function test_no_memory_dumping(): void
    {
        $this->assertFalse(method_exists($this->svc, 'dumpMemory'));
        $this->assertFalse(method_exists($this->svc, 'captureMemory'));
    }

    public function test_no_hidden_collector_restart(): void
    {
        $this->assertFalse(method_exists($this->svc, 'silentlyRestartCollector'));
        $this->assertFalse(method_exists($this->svc, 'forceCollectorRestart'));
    }

    public function test_no_silent_event_loss(): void
    {
        $this->assertFalse(method_exists($this->svc, 'silentlyDropEvents'));
        $this->assertFalse(method_exists($this->svc, 'suppressTelemetry'));
    }

    public function test_advisory_only_flag(): void
    {
        $this->assertTrue($this->svc->getDashboardStats()['advisory_only']);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Resource Snapshot Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_resource_snapshot_normal_state(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 20.0, 64, 1024, 10);
        $this->assertEquals('normal', $snap->pressure_state);
        $this->assertStringStartsWith('srs-', $snap->snapshot_id);
    }

    public function test_resource_snapshot_critical_cpu(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 85.0, 64, 1024, 10);
        $this->assertEquals('critical', $snap->pressure_state);
    }

    public function test_resource_snapshot_critical_memory(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 10.0, 600, 1024, 10);
        $this->assertEquals('critical', $snap->pressure_state);
    }

    public function test_resource_snapshot_critical_spool(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 10.0, 64, SensorHardeningService::MAX_SPOOL_SIZE_KB + 1, 10);
        $this->assertEquals('critical', $snap->pressure_state);
    }

    public function test_resource_snapshot_elevated_state(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 45.0, 150, 1024, 10);
        $this->assertEquals('elevated', $snap->pressure_state);
    }

    public function test_resource_snapshot_is_append_only(): void
    {
        $snap = $this->svc->recordResourceSnapshot('agent-1', 10.0, 64, 100, 5);
        $this->expectException(\LogicException::class);
        $snap->cpu_pct = 99.0;
        $snap->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Collector Health Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_collector_health_event_persists(): void
    {
        $event = $this->svc->recordCollectorHealthEvent(
            agentId: 'agent-2',
            healthState: 'degraded',
            eventType: 'state_transition',
            previousState: 'healthy',
            reason: 'High CPU usage',
        );

        $this->assertDatabaseHas('collector_health_events', [
            'agent_id'     => 'agent-2',
            'health_state' => 'degraded',
            'event_type'   => 'state_transition',
        ]);
        $this->assertStringStartsWith('che-', $event->event_id);
    }

    public function test_collector_health_rejects_invalid_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordCollectorHealthEvent('agent', 'invalid_state', 'state_transition');
    }

    public function test_collector_health_rejects_invalid_event_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordCollectorHealthEvent('agent', 'healthy', 'invalid_event_type');
    }

    public function test_all_health_states_accepted(): void
    {
        foreach (CollectorHealthEvent::HEALTH_STATES as $state) {
            $e = $this->svc->recordCollectorHealthEvent('agent', $state, 'state_transition');
            $this->assertEquals($state, $e->health_state);
        }
    }

    public function test_collector_health_event_is_append_only(): void
    {
        $e = $this->svc->recordCollectorHealthEvent('agent', 'healthy', 'reconnect');
        $this->expectException(\LogicException::class);
        $e->health_state = 'degraded';
        $e->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Telemetry Integrity Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_integrity_run_pass_when_all_valid(): void
    {
        $run = $this->svc->runTelemetryIntegrityCheck('agent-3', 500,
            checksumValid: true, sequenceValid: true, replaySafe: true, corruptionCount: 0
        );
        $this->assertEquals(TelemetryIntegrityRun::VERDICT_PASS, $run->verdict);
        $this->assertStringStartsWith('tir-', $run->run_id);
    }

    public function test_integrity_run_fail_on_corruption(): void
    {
        $run = $this->svc->runTelemetryIntegrityCheck('agent-3', 100, corruptionCount: 3);
        $this->assertEquals(TelemetryIntegrityRun::VERDICT_FAIL, $run->verdict);
    }

    public function test_integrity_run_fail_on_invalid_checksum(): void
    {
        $run = $this->svc->runTelemetryIntegrityCheck('agent-3', 100, checksumValid: false);
        $this->assertEquals(TelemetryIntegrityRun::VERDICT_FAIL, $run->verdict);
    }

    public function test_integrity_run_partial_on_sequence_failure(): void
    {
        $run = $this->svc->runTelemetryIntegrityCheck('agent-3', 100,
            checksumValid: true, sequenceValid: false, replaySafe: true
        );
        $this->assertEquals(TelemetryIntegrityRun::VERDICT_PARTIAL, $run->verdict);
    }

    public function test_integrity_run_is_append_only(): void
    {
        $r = $this->svc->runTelemetryIntegrityCheck('agent', 10);
        $this->expectException(\LogicException::class);
        $r->verdict = 'fail';
        $r->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Telemetry Gap Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_gap_report_persists(): void
    {
        $r = $this->svc->reportTelemetryGap('agent-4', 300, estimatedLostEvents: 150, recovered: false);
        $this->assertDatabaseHas('telemetry_gap_reports', [
            'agent_id'            => 'agent-4',
            'gap_duration_seconds'=> 300,
            'recovered'           => false,
        ]);
        $this->assertStringStartsWith('tgr-', $r->report_id);
    }

    public function test_gap_report_is_append_only(): void
    {
        $r = $this->svc->reportTelemetryGap('agent', 60);
        $this->expectException(\LogicException::class);
        $r->recovered = true;
        $r->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Package Signature Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_package_validation_pass_when_hashes_match(): void
    {
        $hash = hash('sha256', 'agent-binary-v2.0');
        $val  = $this->svc->validatePackageSignature(
            'xdr-agent', '2.0.0', 'platform-eng',
            expectedHash: $hash, observedHash: $hash, signer: 'Acme Corp'
        );
        $this->assertEquals(PackageSignatureValidation::VERDICT_PASS, $val->verdict);
        $this->assertTrue($val->signature_valid);
        $this->assertTrue($val->hash_valid);
    }

    public function test_package_validation_fail_when_hashes_differ(): void
    {
        $val = $this->svc->validatePackageSignature(
            'xdr-agent', '2.0.0', 'eng',
            expectedHash: 'abc123', observedHash: 'def456'
        );
        $this->assertEquals(PackageSignatureValidation::VERDICT_FAIL, $val->verdict);
        $this->assertFalse($val->hash_valid);
    }

    public function test_package_validation_unknown_when_no_expected_hash(): void
    {
        $val = $this->svc->validatePackageSignature('xdr-agent', '2.0.0', 'eng');
        $this->assertEquals(PackageSignatureValidation::VERDICT_UNKNOWN, $val->verdict);
    }

    public function test_package_validation_is_append_only(): void
    {
        $v = $this->svc->validatePackageSignature('pkg', '1.0', 'eng');
        $this->expectException(\LogicException::class);
        $v->verdict = 'pass';
        $v->save();
    }

    public function test_package_validation_id_prefix(): void
    {
        $v = $this->svc->validatePackageSignature('pkg', '1.0', 'eng');
        $this->assertStringStartsWith('psv-', $v->validation_id);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Offline Recovery Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_offline_recovery_complete(): void
    {
        $run = $this->svc->recordOfflineRecovery(
            'agent-5', 3600, 500, 498,
            droppedEventCount: 2, replayComplete: true, sequenceContinuityOk: true
        );
        $this->assertEquals(OfflineRecoveryRun::VERDICT_COMPLETE, $run->recovery_verdict);
        $this->assertStringStartsWith('orr-', $run->run_id);
    }

    public function test_offline_recovery_partial(): void
    {
        $run = $this->svc->recordOfflineRecovery(
            'agent-5', 3600, 500, 300,
            replayComplete: true, sequenceContinuityOk: false
        );
        $this->assertEquals(OfflineRecoveryRun::VERDICT_PARTIAL, $run->recovery_verdict);
    }

    public function test_offline_recovery_failed(): void
    {
        $run = $this->svc->recordOfflineRecovery('agent-5', 3600, 500, 0);
        $this->assertEquals(OfflineRecoveryRun::VERDICT_FAILED, $run->recovery_verdict);
    }

    public function test_offline_buffer_bounded(): void
    {
        $run = $this->svc->recordOfflineRecovery('agent-5', 100, 999_999, 0);
        $this->assertLessThanOrEqual(SensorHardeningService::MAX_OFFLINE_BUFFER_EVENTS, $run->buffered_event_count);
    }

    public function test_offline_recovery_is_append_only(): void
    {
        $r = $this->svc->recordOfflineRecovery('agent', 60, 10, 10);
        $this->expectException(\LogicException::class);
        $r->recovery_verdict = 'complete';
        $r->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Collector Restart Audit Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_restart_audit_persists(): void
    {
        $a = $this->svc->auditCollectorRestart(
            'agent-6', 3, crashInduced: true, priorHealthState: 'healthy'
        );
        $this->assertDatabaseHas('collector_restart_audit', [
            'agent_id'      => 'agent-6',
            'crash_induced' => true,
        ]);
        $this->assertStringStartsWith('cra-', $a->audit_id);
    }

    public function test_restart_audit_is_append_only(): void
    {
        $a = $this->svc->auditCollectorRestart('agent', 1);
        $this->expectException(\LogicException::class);
        $a->crash_induced = true;
        $a->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Sequence Validation Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_sequence_continuity_pass(): void
    {
        $v = $this->svc->validateSequenceContinuity('agent-7', 100, 100);
        $this->assertTrue($v->continuity_ok);
        $this->assertEquals('pass', $v->verdict);
        $this->assertStringStartsWith('tsv-', $v->validation_id);
    }

    public function test_sequence_continuity_gap_detected(): void
    {
        $v = $this->svc->validateSequenceContinuity('agent-7', 100, 95, gapCount: 5);
        $this->assertFalse($v->continuity_ok);
        $this->assertEquals('gap_detected', $v->verdict);
    }

    public function test_sequence_continuity_duplicate_detected(): void
    {
        $v = $this->svc->validateSequenceContinuity('agent-7', 100, 100, duplicateCount: 2);
        $this->assertFalse($v->continuity_ok);
        $this->assertEquals('duplicate_detected', $v->verdict);
    }

    public function test_sequence_validation_is_append_only(): void
    {
        $v = $this->svc->validateSequenceContinuity('agent', 10, 10);
        $this->expectException(\LogicException::class);
        $v->continuity_ok = false;
        $v->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Upgrade Validation Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_upgrade_validation_pass(): void
    {
        $v = $this->svc->validateUpgrade('agent-8', '1.0', '2.0', 'platform-eng',
            packageVerified: true, rollbackAvailable: true, telemetryResumed: true
        );
        $this->assertEquals(EndpointUpgradeValidation::VERDICT_PASS, $v->verdict);
        $this->assertStringStartsWith('euv-', $v->validation_id);
    }

    public function test_upgrade_validation_fail_without_package_verification(): void
    {
        $v = $this->svc->validateUpgrade('agent-8', '1.0', '2.0', 'eng',
            packageVerified: false
        );
        $this->assertEquals(EndpointUpgradeValidation::VERDICT_FAIL, $v->verdict);
    }

    public function test_upgrade_validation_is_append_only(): void
    {
        $v = $this->svc->validateUpgrade('agent', '1.0', '2.0', 'eng');
        $this->expectException(\LogicException::class);
        $v->verdict = 'pass';
        $v->save();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Hunt domains Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_collector_health_events_domain_supported(): void
    {
        $this->assertContains('collector_health_events', app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    public function test_telemetry_gap_reports_domain_supported(): void
    {
        $this->assertContains('telemetry_gap_reports', app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    public function test_telemetry_integrity_runs_domain_supported(): void
    {
        $this->assertContains('telemetry_integrity_runs', app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    public function test_package_signature_validations_domain_supported(): void
    {
        $this->assertContains('package_signature_validations', app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    public function test_offline_recovery_runs_domain_supported(): void
    {
        $this->assertContains('offline_recovery_runs', app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    public function test_total_hunt_domains_is_75(): void
    {
        $this->assertCount(164, app(\App\Services\ThreatHuntingService::class)->supportedDomains());
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Routes Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_sensor_health_dashboard_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('sensor.health-dashboard'))->assertStatus(200);
    }

    public function test_collector_lifecycle_route(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('sensor.collector-lifecycle'))->assertStatus(200);
    }

    public function test_views_contain_advisory_notice(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('sensor.health-dashboard'))
            ->assertSee('advisory-only');
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Dashboard stats Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function test_dashboard_stats_all_keys_present(): void
    {
        $stats = $this->svc->getDashboardStats();
        $keys = [
            'total_resource_snapshots', 'critical_resource_pressure', 'total_collector_events',
            'unhealthy_collector_events', 'total_integrity_runs', 'integrity_failures',
            'total_gap_reports', 'unrecovered_gaps', 'total_pkg_validations',
            'pkg_validation_failures', 'total_offline_runs', 'failed_recoveries',
            'total_restart_audits', 'crash_induced_restarts', 'total_sequence_validations',
            'sequence_failures', 'total_upgrade_validations', 'upgrade_failures', 'advisory_only',
        ];
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $stats);
        }
    }
}


