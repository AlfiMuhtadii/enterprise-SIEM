<?php

namespace Tests\Feature;

use App\Models\AdvisoryFinding;
use App\Models\ShadowSoakAuditEvent;
use App\Models\ShadowSoakConfidenceBand;
use App\Models\ShadowSoakCoverageStat;
use App\Models\ShadowSoakDomainAssessment;
use App\Models\ShadowSoakEvidenceSnapshot;
use App\Models\ShadowSoakFindingSummary;
use App\Models\ShadowSoakGateCheck;
use App\Models\ShadowSoakRun;
use App\Models\ShadowSoakSuppressionStat;
use App\Services\DomainSoakHarnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DomainSoakHarnessTest extends TestCase
{
    use RefreshDatabase;

    private DomainSoakHarnessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DomainSoakHarnessService::class);
    }

    // =========================================================================
    // Constants & safety invariants
    // =========================================================================

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(DomainSoakHarnessService::ADVISORY_ONLY);
    }

    public function test_active_allowlist_mutation_is_forbidden(): void
    {
        $this->assertTrue(DomainSoakHarnessService::ACTIVE_ALLOWLIST_MUTATION_FORBIDDEN);
    }

    public function test_supported_domains_contains_endpoint(): void
    {
        $this->assertContains('endpoint', DomainSoakHarnessService::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_contains_network(): void
    {
        $this->assertContains('network', DomainSoakHarnessService::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_contains_ueba(): void
    {
        $this->assertContains('ueba', DomainSoakHarnessService::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_does_not_include_identity(): void
    {
        $this->assertNotContains('identity', DomainSoakHarnessService::SUPPORTED_DOMAINS);
    }

    public function test_window_hours_constants_are_sane(): void
    {
        $this->assertLessThan(DomainSoakHarnessService::MAX_WINDOW_HOURS, DomainSoakHarnessService::MIN_WINDOW_HOURS);
    }

    public function test_min_high_confidence_score_is_reasonable(): void
    {
        $this->assertGreaterThan(0.0, DomainSoakHarnessService::MIN_HIGH_CONFIDENCE_SCORE);
        $this->assertLessThan(1.0, DomainSoakHarnessService::MIN_HIGH_CONFIDENCE_SCORE);
    }

    public function test_promotion_evidence_pass_rate_is_defined(): void
    {
        $this->assertGreaterThan(0.5, DomainSoakHarnessService::PROMOTION_EVIDENCE_PASS_RATE);
        $this->assertLessThanOrEqual(1.0, DomainSoakHarnessService::PROMOTION_EVIDENCE_PASS_RATE);
    }

    // =========================================================================
    // startSoakRun
    // =========================================================================

    public function test_start_soak_run_creates_run_record(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24, false, 'test-actor');

        $this->assertDatabaseHas('shadow_soak_runs', [
            'run_id'       => $run->run_id,
            'domain'       => 'endpoint',
            'window_hours' => 24,
            'status'       => 'running',
            'dry_run'      => false,
        ]);
    }

    public function test_dry_run_creates_run_with_dry_run_status(): void
    {
        $run = $this->service->startSoakRun('network', 6, true, 'cli');

        $this->assertEquals('dry_run', $run->status);
        $this->assertTrue($run->dry_run);
    }

    public function test_start_soak_run_writes_audit_event(): void
    {
        $run = $this->service->startSoakRun('ueba', 12);

        $this->assertDatabaseHas('shadow_soak_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'run_started',
        ]);
    }

    public function test_invalid_domain_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->startSoakRun('identity', 6);
    }

    public function test_window_hours_clamped_to_max(): void
    {
        $run = $this->service->startSoakRun('endpoint', 9999);
        $this->assertEquals(DomainSoakHarnessService::MAX_WINDOW_HOURS, $run->window_hours);
    }

    public function test_window_hours_clamped_to_min(): void
    {
        $run = $this->service->startSoakRun('endpoint', 0);
        $this->assertEquals(DomainSoakHarnessService::MIN_WINDOW_HOURS, $run->window_hours);
    }

    // =========================================================================
    // computeEvidence
    // =========================================================================

    public function test_compute_evidence_creates_snapshot(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 15, 0.70, 'new');

        $snapshot = $this->service->computeEvidence($run);

        $this->assertInstanceOf(ShadowSoakEvidenceSnapshot::class, $snapshot);
        $this->assertEquals($run->run_id, $snapshot->run_id);
        $this->assertEquals(15, $snapshot->total_findings);
    }

    public function test_compute_evidence_with_no_findings(): void
    {
        $run = $this->service->startSoakRun('network', 24);

        $snapshot = $this->service->computeEvidence($run);

        $this->assertEquals(0, $snapshot->total_findings);
        $this->assertEquals(0.0, $snapshot->high_confidence_rate);
    }

    public function test_compute_evidence_calculates_high_confidence_rate(): void
    {
        $run = $this->service->startSoakRun('ueba', 24);
        $this->seedFindings('ueba', 10, 0.70, 'new');  // high confidence
        $this->seedFindings('ueba', 10, 0.40, 'new');  // low confidence

        $snapshot = $this->service->computeEvidence($run);

        $this->assertEquals(20, $snapshot->total_findings);
        $this->assertEquals(10, $snapshot->high_confidence_findings);
        $this->assertEqualsWithDelta(0.5, $snapshot->high_confidence_rate, 0.01);
    }

    public function test_compute_evidence_calculates_suppression_rate(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 8, 0.70, 'new');
        $this->seedFindings('endpoint', 2, 0.70, 'dismissed');

        $snapshot = $this->service->computeEvidence($run);

        $this->assertEquals(10, $snapshot->total_findings);
        $this->assertEquals(2, $snapshot->suppressed_findings);
        $this->assertEqualsWithDelta(0.2, $snapshot->suppression_rate, 0.01);
    }

    public function test_compute_evidence_writes_audit_event(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);

        $this->service->computeEvidence($run);

        $this->assertDatabaseHas('shadow_soak_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'evidence_computed',
        ]);
    }

    // =========================================================================
    // checkGates
    // =========================================================================

    public function test_check_gates_returns_array_of_gate_checks(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);

        $this->assertIsArray($gates);
        $this->assertNotEmpty($gates);
    }

    public function test_gate_min_findings_passes_when_enough_findings(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);
        $minFindingsGate = collect($gates)->firstWhere('gate_name', 'min_findings');

        $this->assertNotNull($minFindingsGate);
        $this->assertEquals('pass', $minFindingsGate->result);
    }

    public function test_gate_min_findings_fails_when_too_few(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 5, 0.60, 0.20, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);
        $gate  = collect($gates)->firstWhere('gate_name', 'min_findings');

        $this->assertEquals('fail', $gate->result);
    }

    public function test_gate_suppression_rate_fails_when_high(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.50, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);
        $gate  = collect($gates)->firstWhere('gate_name', 'suppression_rate');

        $this->assertEquals('fail', $gate->result);
    }

    public function test_gate_high_confidence_rate_fails_when_low(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.30, 0.20, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);
        $gate  = collect($gates)->firstWhere('gate_name', 'high_confidence_rate');

        $this->assertEquals('fail', $gate->result);
    }

    public function test_gates_write_audit_events(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);

        $gates = $this->service->checkGates($run, $snapshot);

        $auditCount = ShadowSoakAuditEvent::where('run_id', $run->run_id)
            ->where('event_type', 'gate_checked')
            ->count();

        $this->assertEquals(count($gates), $auditCount);
    }

    // =========================================================================
    // buildAssessment
    // =========================================================================

    public function test_assessment_pass_when_all_gates_pass(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);
        $gates    = $this->service->checkGates($run, $snapshot);

        $assessment = $this->service->buildAssessment($run, $gates);

        $this->assertInstanceOf(ShadowSoakDomainAssessment::class, $assessment);
        $this->assertTrue($assessment->evidence_pass);
    }

    public function test_assessment_never_auto_recommends_promotion(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);
        $gates    = $this->service->checkGates($run, $snapshot);

        $assessment = $this->service->buildAssessment($run, $gates);

        $this->assertFalse($assessment->promotion_recommended,
            "promotion_recommended must always be false — promotion requires explicit operator action");
    }

    public function test_assessment_fail_when_gates_fail(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 3, 0.20, 0.60, 0.50);
        $gates    = $this->service->checkGates($run, $snapshot);

        $assessment = $this->service->buildAssessment($run, $gates);

        $this->assertFalse($assessment->evidence_pass);
    }

    public function test_assessment_writes_audit_event(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $snapshot = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);
        $gates    = $this->service->checkGates($run, $snapshot);

        $this->service->buildAssessment($run, $gates);

        $this->assertDatabaseHas('shadow_soak_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'assessment_recorded',
        ]);
    }

    // =========================================================================
    // completeSoakRun / failSoakRun
    // =========================================================================

    public function test_complete_soak_run_sets_status_completed(): void
    {
        $run        = $this->service->startSoakRun('endpoint', 24);
        $snapshot   = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);
        $gates      = $this->service->checkGates($run, $snapshot);
        $assessment = $this->service->buildAssessment($run, $gates);

        $completed = $this->service->completeSoakRun($run, $assessment);

        $this->assertEquals('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_complete_soak_run_writes_audit(): void
    {
        $run        = $this->service->startSoakRun('endpoint', 24);
        $snapshot   = $this->makeSnapshot($run, 15, 0.60, 0.20, 0.50);
        $gates      = $this->service->checkGates($run, $snapshot);
        $assessment = $this->service->buildAssessment($run, $gates);

        $this->service->completeSoakRun($run, $assessment);

        $this->assertDatabaseHas('shadow_soak_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'run_completed',
        ]);
    }

    public function test_fail_soak_run_sets_status_failed(): void
    {
        $run    = $this->service->startSoakRun('network', 24);
        $failed = $this->service->failSoakRun($run, 'test error');

        $this->assertEquals('failed', $failed->status);
        $this->assertEquals('test error', $failed->failure_reason);
    }

    public function test_fail_soak_run_writes_audit(): void
    {
        $run = $this->service->startSoakRun('network', 24);
        $this->service->failSoakRun($run, 'test error');

        $this->assertDatabaseHas('shadow_soak_audit_events', [
            'run_id'     => $run->run_id,
            'event_type' => 'run_failed',
        ]);
    }

    // =========================================================================
    // Supplementary record methods
    // =========================================================================

    public function test_record_confidence_bands_creates_record(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 5, 0.30, 'new');
        $this->seedFindings('endpoint', 5, 0.55, 'new');
        $this->seedFindings('endpoint', 5, 0.70, 'new');
        $this->seedFindings('endpoint', 5, 0.85, 'new');
        $snapshot = $this->service->computeEvidence($run);

        $band = $this->service->recordConfidenceBands($run, $snapshot);

        $this->assertInstanceOf(ShadowSoakConfidenceBand::class, $band);
        $this->assertEquals($run->run_id, $band->run_id);
    }

    public function test_record_suppression_stats_creates_record(): void
    {
        $run      = $this->service->startSoakRun('ueba', 24);
        $snapshot = $this->makeSnapshot($run, 20, 0.60, 0.15, 0.50);

        $stat = $this->service->recordSuppressionStats($run, $snapshot);

        $this->assertInstanceOf(ShadowSoakSuppressionStat::class, $stat);
        $this->assertTrue($stat->within_threshold);
    }

    public function test_record_coverage_stats_creates_record(): void
    {
        $run      = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 5, 0.70, 'new');
        $snapshot = $this->service->computeEvidence($run);

        $stat = $this->service->recordCoverageStats($run, $snapshot);

        $this->assertInstanceOf(ShadowSoakCoverageStat::class, $stat);
        $this->assertEquals($run->run_id, $stat->run_id);
    }

    public function test_record_finding_summaries_creates_per_rule_records(): void
    {
        $run = $this->service->startSoakRun('network', 24);
        $this->seedFindings('network', 3, 0.70, 'new', 'rule-net-001');
        $this->seedFindings('network', 2, 0.50, 'new', 'rule-net-002');

        $summaries = $this->service->recordFindingSummaries($run);

        $this->assertCount(2, $summaries);
    }

    // =========================================================================
    // No alert/incident creation
    // =========================================================================

    public function test_no_security_alerts_created_during_soak(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 10, 0.70, 'new');
        $snapshot   = $this->service->computeEvidence($run);
        $gates      = $this->service->checkGates($run, $snapshot);
        $assessment = $this->service->buildAssessment($run, $gates);
        $this->service->completeSoakRun($run, $assessment);

        $this->assertDatabaseCount('security_alerts', 0);
    }

    public function test_no_incidents_created_during_soak(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 10, 0.70, 'new');
        $snapshot   = $this->service->computeEvidence($run);
        $gates      = $this->service->checkGates($run, $snapshot);
        $assessment = $this->service->buildAssessment($run, $gates);
        $this->service->completeSoakRun($run, $assessment);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    // =========================================================================
    // getSummary / getRuns
    // =========================================================================

    public function test_get_summary_returns_counts(): void
    {
        $this->service->startSoakRun('endpoint', 24);
        $this->service->startSoakRun('network', 12);

        $summary = $this->service->getSummary();

        $this->assertEquals(2, $summary['total']);
    }

    public function test_get_summary_filters_by_domain(): void
    {
        $this->service->startSoakRun('endpoint', 24);
        $this->service->startSoakRun('network', 12);

        $summary = $this->service->getSummary('endpoint');

        $this->assertEquals(1, $summary['total']);
    }

    public function test_get_runs_returns_paginator(): void
    {
        $this->service->startSoakRun('endpoint', 24);

        $runs = $this->service->getRuns();

        $this->assertGreaterThanOrEqual(1, $runs->total());
    }

    public function test_get_run_detail_returns_all_components(): void
    {
        $run        = $this->service->startSoakRun('endpoint', 24);
        $this->seedFindings('endpoint', 12, 0.70, 'new');
        $snapshot   = $this->service->computeEvidence($run);
        $gates      = $this->service->checkGates($run, $snapshot);
        $assessment = $this->service->buildAssessment($run, $gates);
        $this->service->completeSoakRun($run, $assessment);

        $detail = $this->service->getRunDetail($run->run_id);

        $this->assertArrayHasKey('run', $detail);
        $this->assertArrayHasKey('snapshot', $detail);
        $this->assertArrayHasKey('gates', $detail);
        $this->assertArrayHasKey('assessment', $detail);
        $this->assertArrayHasKey('audit', $detail);
    }

    // =========================================================================
    // Artisan command
    // =========================================================================

    public function test_shadow_soak_command_completes_successfully(): void
    {
        $this->seedFindings('endpoint', 15, 0.70, 'new');

        $this->artisan('xdr:shadow-soak', ['--domain' => 'endpoint', '--hours' => '24'])
             ->assertExitCode(0);
    }

    public function test_shadow_soak_command_dry_run_creates_no_evidence(): void
    {
        $this->artisan('xdr:shadow-soak', ['--domain' => 'endpoint', '--hours' => '6', '--dry-run' => true])
             ->assertExitCode(0);

        $this->assertDatabaseMissing('shadow_soak_evidence_snapshots', ['domain' => 'endpoint']);
    }

    public function test_shadow_soak_command_list_returns_zero(): void
    {
        $this->artisan('xdr:shadow-soak', ['--list' => true])
             ->assertExitCode(0);
    }

    public function test_shadow_soak_command_fails_without_domain(): void
    {
        $this->artisan('xdr:shadow-soak')
             ->assertExitCode(1);
    }

    public function test_shadow_soak_command_fails_with_invalid_domain(): void
    {
        $this->artisan('xdr:shadow-soak', ['--domain' => 'identity', '--hours' => '6'])
             ->assertExitCode(1);
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_shadow_soak_index_requires_auth(): void
    {
        $response = $this->get('/shadow-soak');
        $response->assertRedirect('/login');
    }

    public function test_shadow_soak_show_requires_auth(): void
    {
        $response = $this->get('/shadow-soak/some-run-id');
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // Append-only guard (audit events never deleted)
    // =========================================================================

    public function test_audit_events_are_not_deleted_on_run_failure(): void
    {
        $run = $this->service->startSoakRun('endpoint', 24);
        $this->service->failSoakRun($run, 'test');

        $this->assertGreaterThanOrEqual(2, ShadowSoakAuditEvent::where('run_id', $run->run_id)->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedFindings(string $domain, int $count, float $confidence, string $status, string $ruleId = null): void
    {
        $ruleId ??= 'rule-' . $domain . '-001';
        for ($i = 0; $i < $count; $i++) {
            AdvisoryFinding::create([
                'finding_id'          => 'af-' . Str::uuid(),
                'rule_id'             => $ruleId,
                'domain'              => $domain,
                'source_topic'        => 'xdr.alerts.shadow.' . $domain,
                'alert_type'          => 'test_alert',
                'severity'            => 'medium',
                'confidence'          => $confidence,
                'actor_key'           => 'host-' . $i,
                'evidence'            => ['test' => true],
                'promotion_candidate' => false,
                'status'              => $status,
                'fingerprint'         => 'fp-' . Str::random(16),
                'occurrence_count'    => 1,
                'first_seen_at'       => now()->format('Y-m-d H:i:sP'),
                'last_seen_at'        => now()->format('Y-m-d H:i:sP'),
            ]);
        }
    }

    private function makeSnapshot(
        ShadowSoakRun $run,
        int   $total,
        float $highConfRate,
        float $suppressionRate,
        float $coverageRate
    ): ShadowSoakEvidenceSnapshot {
        $highConf   = (int) round($total * $highConfRate);
        $suppressed = (int) round($total * $suppressionRate);

        return ShadowSoakEvidenceSnapshot::create([
            'snapshot_id'              => 'sse-' . Str::uuid(),
            'run_id'                   => $run->run_id,
            'domain'                   => $run->domain,
            'total_findings'           => $total,
            'high_confidence_findings' => $highConf,
            'high_confidence_rate'     => round($highConfRate, 4),
            'suppressed_findings'      => $suppressed,
            'suppression_rate'         => round($suppressionRate, 4),
            'rules_covered'            => 5,
            'rule_coverage_rate'       => round($coverageRate, 4),
            'tenant_id'                => $run->tenant_id,
        ]);
    }
}
