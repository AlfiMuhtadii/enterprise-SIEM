<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the security:alerts-report --demo-run command.
 *
 * These tests verify the FIELD_MATCH reporting behavior: PASS when
 * evidence contains demo_run_id (field-level lineage from Go pipeline),
 * WARN when only time-window fallback matches, and that non-demo alerts
 * continue to work without demo fields.
 */
class SecurityAlertsReportCommandTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function insertAlert(string $alertId, array $evidence = [], array $rawEvent = []): void
    {
        // Use format with timezone offset so PostgreSQL stores UTC correctly regardless
        // of the server session timezone (which may not be UTC in development).
        $ts = now()->format('Y-m-d H:i:sP');

        DB::table('security_alerts')->insert([
            'alert_id'       => $alertId,
            'detected_at'    => $ts,
            'alert_type'     => 'TEST_ALERT_TYPE',
            'severity'       => 'medium',
            'detector_name'  => 'TEST_RULE',
            'detector_version' => 'v1',
            'score'          => 0.75,
            'evidence'       => json_encode($evidence),
            'raw_event'      => json_encode($rawEvent),
            'created_at'     => $ts,
            'updated_at'     => $ts,
        ]);
    }

    private function writeManifest(string $demoRunId): void
    {
        $manifestPath = base_path("storage/logs/{$demoRunId}-manifest.json");
        file_put_contents($manifestPath, json_encode([
            'demo_run_id' => $demoRunId,
            'started_at'  => now()->subMinutes(5)->toISOString(),
            'ended_at'    => now()->toISOString(),
            'status'      => 'ready',
        ]));
    }

    protected function tearDown(): void
    {
        // Remove any manifest files written by tests.
        foreach (glob(base_path('storage/logs/test-demo-*.json')) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // FIELD_MATCH=PASS: evidence contains demo_run_id (scalar)
    // -----------------------------------------------------------------------

    public function test_field_match_pass_when_evidence_has_scalar_demo_run_id(): void
    {
        $demoRunId = 'test-demo-field-pass-001';
        $this->writeManifest($demoRunId);

        $this->insertAlert('alert-fp-001', [
            'evidence_ids'         => ['ev-001'],
            'demo_lineage_present' => true,
            'demo_run_id'          => $demoRunId,
            'demo_run_ids'         => [$demoRunId],
            'trace_ids'            => [$demoRunId . '-trace-0001'],
            'source_event_ids'     => ['atk-mfa-001'],
        ]);

        $this->artisan('security:alerts-report', ['--demo-run' => $demoRunId])
            ->expectsOutputToContain('FIELD_MATCH=PASS')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // FIELD_MATCH=PASS: evidence.demo_run_ids array contains the id
    // -----------------------------------------------------------------------

    public function test_field_match_pass_when_evidence_has_demo_run_ids_array(): void
    {
        $demoRunId = 'test-demo-field-pass-002';
        $this->writeManifest($demoRunId);

        // Only demo_run_ids array present (multiple contributors — no scalar).
        $this->insertAlert('alert-fp-002', [
            'evidence_ids'         => ['ev-001', 'ev-002'],
            'demo_lineage_present' => true,
            'demo_run_ids'         => [$demoRunId],
            'trace_ids'            => [$demoRunId . '-trace-0001', $demoRunId . '-trace-0002'],
            'source_event_ids'     => ['src-001', 'src-002'],
        ]);

        $this->artisan('security:alerts-report', ['--demo-run' => $demoRunId])
            ->expectsOutputToContain('FIELD_MATCH=PASS')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // FIELD_MATCH=WARN: time-window only (no field-level match)
    // -----------------------------------------------------------------------

    public function test_field_match_warn_when_only_time_window_matches(): void
    {
        $demoRunId = 'test-demo-field-warn-001';
        $this->writeManifest($demoRunId);

        // Alert without demo lineage fields — simulates pre-propagation Go alert.
        $this->insertAlert('alert-warn-001', [
            'evidence_ids' => ['ev-old-001'],
        ]);

        $this->artisan('security:alerts-report', ['--demo-run' => $demoRunId])
            ->expectsOutputToContain('FIELD_MATCH=WARN')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // FIELD_MATCH=FAIL: no alerts found at all
    // -----------------------------------------------------------------------

    public function test_field_match_fail_when_no_alerts_found(): void
    {
        $demoRunId = 'test-demo-field-fail-001';
        $this->writeManifest($demoRunId);

        // No alerts inserted — nothing should match.
        $this->artisan('security:alerts-report', ['--demo-run' => $demoRunId])
            ->expectsOutputToContain('FIELD_MATCH=FAIL')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // Non-demo usage (no --demo-run): command still works, no FIELD_MATCH output
    // -----------------------------------------------------------------------

    public function test_non_demo_mode_runs_without_field_match(): void
    {
        // Plain alert without demo fields.
        $this->insertAlert('alert-nodemo-001', ['evidence_ids' => ['ev-001']]);

        $this->artisan('security:alerts-report', ['--minutes' => 5])
            ->doesntExpectOutputToContain('FIELD_MATCH')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // No demo_run_id field in non-demo alerts (structural test)
    // -----------------------------------------------------------------------

    public function test_inserting_alert_without_demo_fields_does_not_include_lineage(): void
    {
        $this->insertAlert('alert-nodemo-struct-001', [
            'evidence_ids'    => ['ev-001'],
            'involved_users'  => ['alice'],
            'telemetry_domains' => ['identity'],
        ]);

        $row = DB::table('security_alerts')
            ->where('alert_id', 'alert-nodemo-struct-001')
            ->first();

        $evidence = json_decode($row->evidence, true);
        $this->assertArrayNotHasKey('demo_run_id', $evidence);
        $this->assertArrayNotHasKey('demo_lineage_present', $evidence);
    }
}
