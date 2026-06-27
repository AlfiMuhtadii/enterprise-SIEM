<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Phase1SoakExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ENTERPRISE-062: Phase 1 Gate Evidence Wiring
 *
 * Validates the fixes to P1G-03..P1G-08:
 *  - P1G-03 discovers tier1_batch1 fixture files via base_path
 *  - P1G-04 checks both rule_fixture_backlogs and confidence_source_audit_events
 *  - P1G-06 includes operator guidance when no alerts found
 *  - P1G-07/P1G-08 evaluate from soak report when present; advisory warn when absent
 */
class Phase1SoakGateWiringTest extends TestCase
{
    use RefreshDatabase;

    private Phase1SoakExecutionService $service;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(Phase1SoakExecutionService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    // ── Constants wired correctly ─────────────────────────────────────────────

    public function test_fixture_dir_constant_matches_detection_replay_fixture_service(): void
    {
        // DetectionReplayFixtureService::FIXTURE_DIR = 'tests/fixtures/detection/tier1_batch1'
        $reflection = new \ReflectionClass(Phase1SoakExecutionService::class);
        // Verify via gate evidence rather than accessing private const directly
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-03');
        $this->assertStringContainsString('tier1_batch1', $gate['evidence']);
    }

    public function test_soak_report_path_constant_is_public(): void
    {
        $this->assertSame('reports/xdr_correlation_soak_6h.json', Phase1SoakExecutionService::SOAK_REPORT_PATH);
    }

    // ── P1G-03: fixture path fix ──────────────────────────────────────────────

    public function test_p1g03_passes_because_12_fixture_files_exist(): void
    {
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-03');
        $this->assertNotNull($gate);
        $this->assertSame('pass', $gate['status'], 'P1G-03 must pass — 12 tier1_batch1 fixtures present from E056');
        $this->assertFalse($gate['is_advisory']);
    }

    public function test_p1g03_evidence_reports_correct_count(): void
    {
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-03');
        $this->assertStringContainsString('12', $gate['evidence']);
    }

    // ── P1G-04: multi-source empirical lookup ─────────────────────────────────

    public function test_p1g04_passes_when_backlogs_has_empirical_entry(): void
    {
        DB::table('rule_fixture_backlogs')->insert([
            'rule_id'                 => 'IDENTITY_MFA_FAILURE_BURST',
            'domain'                  => 'identity',
            'title'                   => 'Test Rule',
            'status'                  => 'staged_active',
            'confidence'              => 0.90,
            'confidence_source'       => 'empirical',
            'has_replay_fixture'      => true,
            'has_validation_evidence' => true,
            'is_advisory'             => true,
        ]);

        $gates = $this->service->runGates(false);
        $gate  = $this->findGate($gates, 'P1G-04');
        $this->assertSame('pass', $gate['status']);
        $this->assertStringContainsString('rule_fixture_backlogs', $gate['evidence']);
    }

    public function test_p1g04_passes_via_audit_events_when_backlogs_empty(): void
    {
        // No backlog row — but confidence_source_audit_events has empirical entry
        DB::table('confidence_source_audit_events')->insert([
            'refresh_run_id'        => Str::uuid()->toString(),
            'rule_id'               => 'IDENTITY_MFA_FAILURE_BURST',
            'domain'                => 'identity',
            'old_confidence_source' => 'manual',
            'new_confidence_source' => 'empirical',
            'changed'               => true,
            'has_fixture'           => true,
            'has_evidence'          => true,
            'is_advisory'           => true,
        ]);

        $gates = $this->service->runGates(false);
        $gate  = $this->findGate($gates, 'P1G-04');
        $this->assertSame('pass', $gate['status']);
        $this->assertStringContainsString('confidence_source_audit_events', $gate['evidence']);
    }

    public function test_p1g04_evidence_includes_command_guidance_when_no_data(): void
    {
        // Empty DB — should warn with guidance
        $gates = $this->service->runGates(false);
        $gate  = $this->findGate($gates, 'P1G-04');
        $this->assertSame('warn', $gate['status']);
        $this->assertStringContainsString('rule:run-fixtures', $gate['evidence']);
        $this->assertStringContainsString('rule:refresh-confidence', $gate['evidence']);
    }

    public function test_p1g04_dry_run_includes_command_guidance(): void
    {
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-04');
        $this->assertStringContainsString('rule:run-fixtures', $gate['evidence']);
    }

    // ── P1G-06: operator guidance ─────────────────────────────────────────────

    public function test_p1g06_evidence_includes_demo_feed_guidance_when_no_alerts(): void
    {
        $gates = $this->service->runGates(false);
        $gate  = $this->findGate($gates, 'P1G-06');
        // No alerts seeded — should warn with demo feed guidance
        $this->assertSame('warn', $gate['status']);
        $this->assertStringContainsString('demo_feed', $gate['evidence']);
    }

    public function test_p1g06_passes_when_recent_alert_exists(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'test-alert-062',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'    => 'high',
        ]);

        $gates = $this->service->runGates(false);
        $gate  = $this->findGate($gates, 'P1G-06');
        $this->assertSame('pass', $gate['status']);
    }

    // ── P1G-07: soak report evaluation ───────────────────────────────────────

    public function test_p1g07_passes_when_real_soak_report_shows_p95_under_300(): void
    {
        // Real soak report (reports/xdr_correlation_soak_6h.json) has p95=80.65ms
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-07');
        $this->assertSame('pass', $gate['status'], 'Real soak report shows p95=80.65ms < 300ms');
        $this->assertFalse($gate['is_advisory'], 'P1G-07 is_advisory must be false when report found');
    }

    public function test_p1g07_evidence_cites_soak_report_path(): void
    {
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-07');
        $this->assertStringContainsString(Phase1SoakExecutionService::SOAK_REPORT_PATH, $gate['evidence']);
    }

    public function test_p1g07_fails_when_p95_exceeds_300(): void
    {
        $tmp = $this->makeTempReport(['p95_latency_ms' => 350.0, 'fallback_count' => 0]);
        $this->service->setSoakReportOverride($tmp);

        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-07');
        $this->assertSame('fail', $gate['status']);
        $this->assertStringContainsString('350', $gate['evidence']);
    }

    public function test_p1g07_warns_when_no_soak_report(): void
    {
        $this->service->setSoakReportOverride('/no/such/soak/report.json');

        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-07');
        $this->assertSame('warn', $gate['status']);
        $this->assertTrue($gate['is_advisory']);
    }

    // ── P1G-08: soak report evaluation + env var ─────────────────────────────

    public function test_p1g08_passes_when_soak_report_shows_zero_fallback(): void
    {
        // Real soak report has fallback_count=0
        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-08');
        $this->assertSame('pass', $gate['status'], 'Real soak report shows fallback_count=0');
        $this->assertFalse($gate['is_advisory'], 'P1G-08 is_advisory must be false when report found');
    }

    public function test_p1g08_fails_when_fallback_count_nonzero(): void
    {
        $tmp = $this->makeTempReport(['p95_latency_ms' => 80.0, 'fallback_count' => 3]);
        $this->service->setSoakReportOverride($tmp);

        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-08');
        $this->assertSame('fail', $gate['status']);
        $this->assertStringContainsString('fallback_count=3', $gate['evidence']);
    }

    public function test_p1g08_warns_when_no_soak_report(): void
    {
        $this->service->setSoakReportOverride('/no/such/soak/report.json');

        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-08');
        $this->assertSame('warn', $gate['status']);
        $this->assertTrue($gate['is_advisory']);
    }

    public function test_p1g08_evidence_includes_fallback_env_var_when_no_report(): void
    {
        $this->service->setSoakReportOverride('/no/such/soak/report.json');

        $result = $this->service->buildRun(true);
        $gate   = $this->findGate($result['gates'], 'P1G-08');
        $this->assertStringContainsString('XDR_CORRELATION_FALLBACK_TO_LEGACY', $gate['evidence']);
    }

    // ── Combined: decision when report present ────────────────────────────────

    public function test_decision_is_not_fail_when_soak_report_present(): void
    {
        // With real soak report + empty DB (advisory gates warn but nothing fails)
        $result = $this->service->buildRun(true);
        $this->assertNotSame('FAIL', $result['plan']['decision']);
    }

    public function test_more_gates_pass_with_report_than_without(): void
    {
        $withReport = $this->service->buildRun(true);
        $passedWith = $withReport['plan']['gates_passed'];

        // Remove soak report access
        $this->service->setSoakReportOverride('/no/such/soak/report.json');
        $withoutReport = $this->service->buildRun(true);
        $passedWithout = $withoutReport['plan']['gates_passed'];

        $this->assertGreaterThan($passedWithout, $passedWith, 'More gates should pass when soak report is present');
    }

    public function test_set_soak_report_override_affects_gate_evaluation(): void
    {
        // Default: real report present (P1G-07 = pass)
        $default = $this->service->buildRun(true);
        $gateDefault = $this->findGate($default['gates'], 'P1G-07');
        $this->assertSame('pass', $gateDefault['status']);

        // Override to high-latency report (P1G-07 = fail)
        $tmp = $this->makeTempReport(['p95_latency_ms' => 400.0, 'fallback_count' => 0]);
        $this->service->setSoakReportOverride($tmp);
        $overridden = $this->service->buildRun(true);
        $gateOverridden = $this->findGate($overridden['gates'], 'P1G-07');
        $this->assertSame('fail', $gateOverridden['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findGate(array $gates, string $gateId): ?array
    {
        return array_values(array_filter($gates, fn ($g) => $g['gate_id'] === $gateId))[0] ?? null;
    }

    private function makeTempReport(array $metrics): string
    {
        $file = tempnam(sys_get_temp_dir(), 'soak_wiring_');
        file_put_contents($file, json_encode(['metrics' => $metrics]));
        $this->tempFiles[] = $file;
        return $file;
    }
}
