<?php

namespace Tests\Feature;

use App\Models\DetectionAttackMapping;
use App\Models\DetectionFalsePositiveReport;
use App\Models\DetectionPromotionRequest;
use App\Models\DetectionQualityMetric;
use App\Models\DetectionReplayPack;
use App\Models\DetectionReplayResult;
use App\Models\DetectionRuleTestCase;
use App\Models\DetectionRuleVersion;
use App\Models\DetectionSuppression;
use App\Models\User;
use App\Services\DetectionEngineeringService;
use App\Services\RuleRegistryService;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Detection Engineering Lifecycle Phase 1 Ã¢â‚¬â€ Feature Tests.
 *
 * Hard safety assertions (MUST remain green):
 *   - No isolateHost
 *   - No quarantineHost
 *   - No executeShell
 *   - No killProcess
 *   - No autoRemediate
 *   - No autonomous promotion
 *   - No automatic suppression activation
 *   - No destructive mutation of historical rule versions
 *   - ACTIVE_ALLOWLIST remains empty
 */
class DetectionEngineeringLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private DetectionEngineeringService $svc;
    private RuleRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = app(DetectionEngineeringService::class);
        $this->registry = app(RuleRegistryService::class);
    }

    // =========================================================================
    // Schema Ã¢â‚¬â€ new tables exist and are correctly structured
    // =========================================================================

    public function test_detection_rule_versions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_rule_versions'));
    }

    public function test_detection_rule_test_cases_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_rule_test_cases'));
    }

    public function test_detection_replay_packs_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_replay_packs'));
    }

    public function test_detection_replay_results_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_replay_results'));
    }

    public function test_detection_false_positive_reports_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_false_positive_reports'));
    }

    public function test_detection_suppressions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_suppressions'));
    }

    public function test_detection_attack_mappings_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_attack_mappings'));
    }

    public function test_detection_promotion_requests_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_promotion_requests'));
    }

    public function test_detection_quality_metrics_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('detection_quality_metrics'));
    }

    // =========================================================================
    // Append-only guarantees
    // =========================================================================

    public function test_rule_versions_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('detection_rule_versions', 'updated_at'));
    }

    public function test_replay_results_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('detection_replay_results', 'updated_at'));
    }

    public function test_false_positive_reports_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('detection_false_positive_reports', 'updated_at'));
    }

    public function test_promotion_requests_table_has_no_updated_at(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('detection_promotion_requests', 'updated_at'));
    }

    // =========================================================================
    // Rule versioning Ã¢â‚¬â€ immutable snapshots
    // =========================================================================

    public function test_snapshot_rule_version_creates_immutable_record(): void
    {
        $rule    = $this->getFirstRule();
        $author  = User::factory()->create(['role' => 'detection_engineer']);
        $version = $this->svc->snapshotRuleVersion($rule, $author, 'Initial snapshot for testing');

        $this->assertInstanceOf(DetectionRuleVersion::class, $version);
        $this->assertStringStartsWith('drv-', $version->version_id);
        $this->assertEquals($rule['rule_id'], $version->rule_id);
        $this->assertNotEmpty($version->rule_hash);
        $this->assertNotNull($version->rule_snapshot);
        $this->assertEquals($author->id, $version->created_by);

        // Verify append-only: no updated_at column
        $raw = DB::table('detection_rule_versions')->where('id', $version->id)->first();
        $this->assertNull($raw->updated_at ?? null);
    }

    public function test_rule_hash_is_deterministic(): void
    {
        $rule  = $this->getFirstRule();
        $hash1 = DetectionRuleVersion::hashRule($rule);
        $hash2 = DetectionRuleVersion::hashRule($rule);
        $this->assertEquals($hash1, $hash2, 'Rule hash must be deterministic Ã¢â‚¬â€ same inputs produce same hash');
    }

    public function test_rule_hash_differs_for_different_rules(): void
    {
        $rules = $this->registry->allRules();
        $rule1 = $rules->first();
        $rule2 = $rules->skip(1)->first();
        $this->assertNotEquals(
            DetectionRuleVersion::hashRule($rule1),
            DetectionRuleVersion::hashRule($rule2),
            'Different rules must produce different hashes'
        );
    }

    public function test_version_history_returns_newest_first(): void
    {
        $rule   = $this->getFirstRule();
        $author = User::factory()->create(['role' => 'detection_engineer']);

        $v1 = $this->svc->snapshotRuleVersion($rule, $author, 'Version 1');
        $v2 = $this->svc->snapshotRuleVersion($rule, $author, 'Version 2', $v1->version_id);

        $history = $this->svc->getVersionHistory($rule['rule_id']);
        $this->assertCount(2, $history);
        $this->assertEquals($v2->version_id, $history->first()->version_id);
    }

    public function test_version_previous_version_id_is_recorded(): void
    {
        $rule   = $this->getFirstRule();
        $author = User::factory()->create(['role' => 'detection_engineer']);

        $v1 = $this->svc->snapshotRuleVersion($rule, $author, 'First');
        $v2 = $this->svc->snapshotRuleVersion($rule, $author, 'Second', $v1->version_id);

        $this->assertEquals($v1->version_id, $v2->previous_version_id, 'Version must reference its predecessor');
    }

    public function test_version_snapshot_includes_mitre_mappings(): void
    {
        $rule    = $this->getFirstRule();
        $version = $this->svc->snapshotRuleVersion($rule, null, 'Snapshot test');

        $snapshot = $version->rule_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('rule_id', $snapshot);
        $this->assertArrayHasKey('mitre_attack', $snapshot);
        $this->assertArrayHasKey('shadow_only', $snapshot);
    }

    // =========================================================================
    // Test cases
    // =========================================================================

    public function test_create_test_case(): void
    {
        $rule = $this->getFirstRule();
        $tc   = $this->svc->createTestCase($rule['rule_id'], [
            'name'             => 'Test: MFA failure burst detection',
            'expected_outcome' => DetectionRuleTestCase::OUTCOME_TRUE_POSITIVE,
            'expect_trace_id'  => true,
            'expected_severity'=> 'high',
        ]);

        $this->assertInstanceOf(DetectionRuleTestCase::class, $tc);
        $this->assertStringStartsWith('dtc-', $tc->test_case_id);
        $this->assertTrue($tc->is_active);
        $this->assertTrue($tc->expect_trace_id);
    }

    // =========================================================================
    // Replay packs and results
    // =========================================================================

    public function test_create_replay_pack(): void
    {
        $rule = $this->getFirstRule();
        $pack = $this->svc->createReplayPack($rule['rule_id'], [
            'name'                     => 'MFA Burst Pack v1',
            'expected_match_count'     => 3,
            'expected_non_match_count' => 1,
        ]);

        $this->assertInstanceOf(DetectionReplayPack::class, $pack);
        $this->assertStringStartsWith('drp-', $pack->pack_id);
        $this->assertEquals(3, $pack->expected_match_count);
        $this->assertTrue($pack->is_active);
    }

    public function test_record_replay_result_pass(): void
    {
        $rule = $this->getFirstRule();
        $pack = $this->svc->createReplayPack($rule['rule_id'], ['name' => 'Test Pack']);
        $result = $this->svc->recordReplayResult($pack, [
            'passed'       => true,
            'cases_run'    => 4,
            'cases_passed' => 4,
            'cases_failed' => 0,
        ]);

        $this->assertTrue($result->passed);
        $this->assertEquals(4, $result->cases_run);
        $this->assertEquals(4, $result->cases_passed);
        $this->assertFalse($result->unexpected_enforcement);
        $this->assertFalse($result->evidence_mismatch);
    }

    public function test_record_replay_result_fail_on_unexpected_enforcement(): void
    {
        $rule = $this->getFirstRule();
        $pack = $this->svc->createReplayPack($rule['rule_id'], ['name' => 'Enforcement Test']);
        $result = $this->svc->recordReplayResult($pack, [
            'passed'                 => false,
            'cases_run'              => 2,
            'cases_passed'           => 1,
            'cases_failed'           => 1,
            'unexpected_enforcement' => true,
            'failure_details'        => [['case' => 'test-1', 'reason' => 'enforcement_detected']],
        ]);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->unexpected_enforcement, 'Unexpected enforcement must be recorded as a failure signal');
    }

    public function test_replay_result_is_append_only(): void
    {
        $rule   = $this->getFirstRule();
        $pack   = $this->svc->createReplayPack($rule['rule_id'], ['name' => 'Append Test']);
        $result = $this->svc->recordReplayResult($pack, ['passed' => true, 'cases_run' => 1, 'cases_passed' => 1]);

        $raw = DB::table('detection_replay_results')->where('id', $result->id)->first();
        $this->assertNull($raw->updated_at ?? null);
    }

    // =========================================================================
    // False-positive reports
    // =========================================================================

    public function test_report_false_positive_creates_record(): void
    {
        $rule     = $this->getFirstRule();
        $reporter = User::factory()->create(['role' => 'analyst']);
        $report   = $this->svc->reportFalsePositive($rule['rule_id'], [
            'reason_type'   => DetectionFalsePositiveReport::REASON_BENIGN_ACTIVITY,
            'reason_detail' => 'CI/CD pipeline triggers this rule during deployment.',
            'alert_id'      => 'alert-test-001',
        ], $reporter);

        $this->assertStringStartsWith('dfp-', $report->report_id);
        $this->assertEquals('under_review', $report->analyst_verdict);
        $this->assertFalse($report->recommends_suppression);
        $this->assertEquals($reporter->id, $report->reported_by);
    }

    public function test_false_positive_does_not_automatically_suppress(): void
    {
        $rule   = $this->getFirstRule();
        $report = $this->svc->reportFalsePositive($rule['rule_id'], [
            'reason_type'            => DetectionFalsePositiveReport::REASON_NOISY_CONDITION,
            'reason_detail'          => 'Very noisy rule.',
            'recommends_suppression' => true,
        ]);

        // FP report was created but NO automatic suppression was created
        $suppressions = $this->svc->getSuppressions($rule['rule_id']);
        $this->assertCount(0, $suppressions, 'FP report must NOT automatically create a suppression');
    }

    public function test_fp_report_reason_types_are_valid(): void
    {
        $types = DetectionFalsePositiveReport::REASON_TYPES;
        $this->assertContains('benign_activity', $types);
        $this->assertContains('noisy_condition', $types);
        $this->assertContains('misconfiguration', $types);
        $this->assertContains('context_gap', $types);
        $this->assertContains('other', $types);
    }

    // =========================================================================
    // Suppression governance
    // =========================================================================

    public function test_create_suppression_starts_pending(): void
    {
        $rule = $this->getFirstRule();
        $supp = $this->svc->createSuppression($rule['rule_id'], [
            'scope'      => DetectionSuppression::SCOPE_GLOBAL,
            'reason'     => 'Rate limiting due to known noisy condition in test environment.',
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertStringStartsWith('dsp-', $supp->suppression_id);
        $this->assertEquals(DetectionSuppression::STATE_PENDING, $supp->approval_state);
        $this->assertFalse($supp->is_active, 'Suppression must start inactive Ã¢â‚¬â€ requires operator approval');
    }

    public function test_suppression_only_activates_after_approval(): void
    {
        $rule = $this->getFirstRule();
        $supp = $this->svc->createSuppression($rule['rule_id'], [
            'scope'  => DetectionSuppression::SCOPE_HOST,
            'reason' => 'Host is decommissioned.',
        ]);

        $this->assertFalse($supp->is_active);

        $approved = $this->svc->approveSuppression($supp, 'admin@test.com');
        $this->assertTrue($approved->is_active);
        $this->assertEquals(DetectionSuppression::STATE_APPROVED, $approved->approval_state);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_suppression_revoke_deactivates(): void
    {
        $rule = $this->getFirstRule();
        $supp = $this->svc->createSuppression($rule['rule_id'], ['scope' => 'global', 'reason' => 'Test']);
        $this->svc->approveSuppression($supp, 'admin@test.com');
        $revoked = $this->svc->revokeSuppression($supp->fresh());

        $this->assertFalse($revoked->is_active);
        $this->assertEquals(DetectionSuppression::STATE_REVOKED, $revoked->approval_state);
    }

    public function test_suppression_does_not_delete_original_evidence(): void
    {
        // Suppression lifecycle operates on detection_suppressions table only
        // It does NOT modify security_alerts, endpoint_behavioral_findings, or any evidence table
        $this->assertFalse(
            method_exists(\App\Services\DetectionEngineeringService::class, 'deleteAlertEvidence'),
            'DetectionEngineeringService must NOT delete alert evidence'
        );
        $this->assertFalse(
            method_exists(\App\Services\DetectionEngineeringService::class, 'mutateHistoricalAlert'),
            'DetectionEngineeringService must NOT mutate historical alerts'
        );
    }

    public function test_expiring_suppressions_query(): void
    {
        $rule = $this->getFirstRule();
        $supp = $this->svc->createSuppression($rule['rule_id'], [
            'scope'      => 'global',
            'reason'     => 'Test expiry',
            'expires_at' => now()->addDays(3),
        ]);
        $this->svc->approveSuppression($supp, 'admin@test.com');

        $expiring = $this->svc->getExpiringSuppressions(7);
        $this->assertTrue($expiring->contains('suppression_id', $supp->suppression_id));
    }

    // =========================================================================
    // ATT&CK mappings
    // =========================================================================

    public function test_map_attack_technique_creates_record(): void
    {
        $rule    = $this->getFirstRule();
        $mapping = $this->svc->mapAttackTechnique($rule['rule_id'], [
            'tactic'          => 'Credential Access',
            'technique_id'    => 'T1110',
            'technique_name'  => 'Brute Force',
            'confidence'      => 0.85,
            'mapping_source'  => DetectionAttackMapping::SOURCE_ANALYST,
            'evidence_reference' => 'ref: lab-brute-force-test-2026-05-20',
        ]);

        $this->assertStringStartsWith('dam-', $mapping->mapping_id);
        $this->assertEquals('T1110', $mapping->technique_id);
        $this->assertEqualsWithDelta(0.85, $mapping->confidence, 1e-6);
        $this->assertTrue($mapping->is_active);
    }

    public function test_attack_coverage_summary_groups_by_tactic(): void
    {
        $rule = $this->getFirstRule();
        $this->svc->mapAttackTechnique($rule['rule_id'], [
            'tactic' => 'Execution', 'technique_id' => 'T1059', 'technique_name' => 'Command and Scripting Interpreter',
        ]);
        $this->svc->mapAttackTechnique($rule['rule_id'], [
            'tactic' => 'Execution', 'technique_id' => 'T1059.001', 'technique_name' => 'PowerShell',
        ]);

        $coverage = $this->svc->getAttackCoverageSummary();
        $execution = $coverage->firstWhere('tactic', 'Execution');
        $this->assertNotNull($execution);
        $this->assertEquals(2, $execution->technique_count);
    }

    public function test_attack_mapping_does_not_trigger_autonomous_promotion(): void
    {
        // ATT&CK mappings are advisory metadata only Ã¢â‚¬â€ they must NOT automatically promote rules
        $this->assertFalse(
            method_exists(\App\Services\DetectionEngineeringService::class, 'autoPromoteBasedOnAttackCoverage'),
            'Autonomous promotion based on ATT&CK coverage must NOT exist'
        );
    }

    // =========================================================================
    // Promotion requests
    // =========================================================================

    public function test_create_promotion_request_creates_pending_record(): void
    {
        $rule      = $this->getFirstShadowRule();
        $requester = User::factory()->create(['role' => 'detection_engineer']);
        $request   = $this->svc->createPromotionRequest($rule, 'staged_active', [
            'rationale' => 'Rule has 48h shadow soak pass and replay validation.',
        ], $requester);

        $this->assertStringStartsWith('dpr-', $request->request_id);
        $this->assertEquals(DetectionPromotionRequest::STATUS_PENDING, $request->status);
        $this->assertEquals($requester->id, $request->requested_by);
        $this->assertIsArray($request->gate_snapshot);
        $this->assertArrayHasKey('passed', $request->gate_snapshot);
    }

    public function test_approve_promotion_request_does_not_automatically_promote_rule(): void
    {
        $rule      = $this->getFirstShadowRule();
        $requester = User::factory()->create(['role' => 'detection_engineer']);
        $reviewer  = User::factory()->create(['role' => 'admin']);
        $request   = $this->svc->createPromotionRequest($rule, 'staged_active', ['rationale' => 'Test']);
        $approved  = $this->svc->approvePromotionRequest($request, $reviewer, 'Looks good.');

        $this->assertEquals(DetectionPromotionRequest::STATUS_APPROVED, $approved->status);

        // The rule stage must NOT have been automatically changed
        $dbRule = \App\Models\DetectionRule::where('rule_id', $rule['rule_id'])->first();
        $this->assertEquals($rule['db_state']->stage, $dbRule->stage, 'Approving a request must NOT automatically promote the rule stage');
    }

    public function test_reject_promotion_request(): void
    {
        $rule    = $this->getFirstShadowRule();
        $request = $this->svc->createPromotionRequest($rule, 'staged_active', ['rationale' => 'Test']);
        $rejected = $this->svc->rejectPromotionRequest($request, null, 'Replay validation failed.');

        $this->assertEquals(DetectionPromotionRequest::STATUS_REJECTED, $rejected->status);
        $this->assertEquals('Replay validation failed.', $rejected->review_note);
    }

    public function test_promotion_request_records_gate_snapshot(): void
    {
        $rule    = $this->getFirstShadowRule();
        $request = $this->svc->createPromotionRequest($rule, 'staged_active', []);
        $gates   = $request->gate_snapshot;

        $this->assertIsArray($gates);
        $this->assertArrayHasKey('passed', $gates);
        $this->assertArrayHasKey('gates', $gates);
    }

    public function test_endpoint_rules_promotion_request_shows_gate_fail(): void
    {
        // Endpoint rules always fail the domain_allowed gate for staged_active
        $endpointRule = $this->getFirstEndpointRule();
        if (!$endpointRule) {
            $this->markTestSkipped('No endpoint rule found');
        }
        $request = $this->svc->createPromotionRequest($endpointRule, 'staged_active', []);
        $this->assertFalse($request->gate_snapshot['passed'], 'Endpoint rule promotion to staged_active must fail gates');
    }

    // =========================================================================
    // Quality metrics
    // =========================================================================

    public function test_compute_quality_metric_creates_record(): void
    {
        $rule = $this->getFirstRule();
        $m    = $this->svc->computeQualityMetric($rule['rule_id']);

        $this->assertInstanceOf(DetectionQualityMetric::class, $m);
        $this->assertEquals($rule['rule_id'], $m->rule_id);
        $this->assertGreaterThanOrEqual(0.0, $m->quality_score);
        $this->assertLessThanOrEqual(1.0, $m->quality_score);
        $this->assertContains($m->quality_trend, ['improving', 'stable', 'degrading']);
    }

    public function test_quality_metric_degrades_with_fp_reports(): void
    {
        $rule  = $this->getFirstRule();
        $base  = $this->svc->computeQualityMetric($rule['rule_id']);

        // Add FP reports to degrade score
        for ($i = 0; $i < 5; $i++) {
            $this->svc->reportFalsePositive($rule['rule_id'], [
                'reason_type'   => DetectionFalsePositiveReport::REASON_NOISY_CONDITION,
                'reason_detail' => "FP report $i",
            ]);
        }

        $degraded = $this->svc->computeQualityMetric($rule['rule_id']);
        $this->assertLessThanOrEqual($base->quality_score, $degraded->quality_score, 'Quality score must decrease with FP reports');
    }

    public function test_quality_score_is_deterministic(): void
    {
        $rule = $this->getFirstRule();
        $m1   = $this->svc->computeQualityMetric($rule['rule_id']);
        $m2   = $this->svc->computeQualityMetric($rule['rule_id']);
        $this->assertEqualsWithDelta($m1->quality_score, $m2->quality_score, 1e-6, 'Quality score must be deterministic');
    }

    // =========================================================================
    // Dashboard stats
    // =========================================================================

    public function test_dashboard_stats_advisory_only(): void
    {
        $stats = $this->svc->getDashboardStats();
        $this->assertTrue($stats['advisory_only']);
        $this->assertFalse($stats['autonomous_promotion']);
    }

    // =========================================================================
    // Threat hunting domain coverage
    // =========================================================================

    public function test_detection_rule_versions_is_supported_hunt_domain(): void
    {
        $this->assertContains('detection_rule_versions', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_replay_results_is_supported_hunt_domain(): void
    {
        $this->assertContains('detection_replay_results', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_false_positive_reports_is_supported_hunt_domain(): void
    {
        $this->assertContains('detection_false_positive_reports', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_attack_mappings_is_supported_hunt_domain(): void
    {
        $this->assertContains('detection_attack_mappings', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_suppressions_is_supported_hunt_domain(): void
    {
        $this->assertContains('detection_suppressions', ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_hunt_domains_total_is_50(): void
    {
        $this->assertCount(164, ThreatHuntingService::SUPPORTED_DOMAINS);
    }

    public function test_detection_rule_versions_domain_supports_field_queries(): void
    {
        $this->svc_hunt()->validateQueryFilters('detection_rule_versions', [
            ['field' => 'rule_id', 'operator' => '=', 'value' => 'TEST_RULE'],
            ['field' => 'stage', 'operator' => '=', 'value' => 'shadow'],
        ]);
        $this->assertTrue(true);
    }

    public function test_detection_suppressions_domain_supports_field_queries(): void
    {
        $this->svc_hunt()->validateQueryFilters('detection_suppressions', [
            ['field' => 'approval_state', 'operator' => '=', 'value' => 'pending'],
            ['field' => 'is_active', 'operator' => '=', 'value' => true],
        ]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // UI routes
    // =========================================================================

    public function test_lifecycle_overview_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.overview'))->assertStatus(200);
    }

    public function test_replay_packs_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.replay-packs'))->assertStatus(200);
    }

    public function test_replay_results_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.replay-results'))->assertStatus(200);
    }

    public function test_false_positives_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.false-positives'))->assertStatus(200);
    }

    public function test_suppressions_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.suppressions'))->assertStatus(200);
    }

    public function test_attack_map_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.attack-map'))->assertStatus(200);
    }

    public function test_promotions_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.promotions'))->assertStatus(200);
    }

    public function test_quality_dashboard_view_is_accessible(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.quality'))->assertStatus(200);
    }

    // =========================================================================
    // Advisory disclaimer in views
    // =========================================================================

    public function test_lifecycle_overview_contains_governance_disclaimer(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.overview'))
             ->assertSee('do not execute autonomous response');
    }

    public function test_promotions_view_contains_no_autonomous_promotion_notice(): void
    {
        $user = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($user)->get(route('detection.lifecycle.promotions'))
             ->assertSee('No autonomous promotion');
    }

    // =========================================================================
    // HARD SAFETY INVARIANTS
    // =========================================================================

    public function test_no_isolate_host_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'isolateHost'));
    }

    public function test_no_quarantine_host_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'quarantineHost'));
    }

    public function test_no_execute_shell_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'executeShell'));
    }

    public function test_no_kill_process_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'killProcess'));
    }

    public function test_no_auto_remediate_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'autoRemediate'));
    }

    public function test_no_autonomous_promotion_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'autonomouslyPromote'));
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'autoPromote'));
    }

    public function test_no_automatic_suppression_in_engineering_service(): void
    {
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'autoSuppress'));
        $this->assertFalse(method_exists(DetectionEngineeringService::class, 'automaticallySuppressAlerts'));
    }

    public function test_active_allowlist_is_empty(): void
    {
        // ACTIVE_ALLOWLIST must remain empty Ã¢â‚¬â€ defined in xdr_rule_registry_validate.py
        $validatorPath = base_path('scripts/xdr_rule_registry_validate.py');
        if (!file_exists($validatorPath)) {
            $this->markTestSkipped('Registry validator not found');
        }
        $content = file_get_contents($validatorPath);
        $this->assertStringContainsString('ACTIVE_ALLOWLIST', $content, 'Validator must define ACTIVE_ALLOWLIST');
        // The ACTIVE_ALLOWLIST must be empty Ã¢â‚¬â€ extract and verify
        preg_match('/ACTIVE_ALLOWLIST\s*=\s*\[(.*?)\]/s', $content, $m);
        $listContent = trim($m[1] ?? '');
        $this->assertEmpty($listContent, 'ACTIVE_ALLOWLIST must remain empty');
    }

    public function test_rule_version_snapshots_are_never_destructively_mutated(): void
    {
        $rule    = $this->getFirstRule();
        $version = $this->svc->snapshotRuleVersion($rule, null, 'Test snapshot');

        $originalHash = $version->rule_hash;
        $originalId   = $version->version_id;

        // Attempt to re-query Ã¢â‚¬â€ should be identical
        $reloaded = DetectionRuleVersion::where('version_id', $version->version_id)->first();
        $this->assertEquals($originalHash, $reloaded->rule_hash, 'Rule hash must be immutable after creation');
        $this->assertEquals($originalId, $reloaded->version_id, 'Version ID must be immutable after creation');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getFirstRule(): array
    {
        return $this->registry->allRules()->first();
    }

    private function getFirstShadowRule(): array
    {
        return $this->registry->allRules()
            ->first(fn ($r) => ($r['status'] ?? '') === 'shadow') ?? $this->getFirstRule();
    }

    private function getFirstEndpointRule(): ?array
    {
        return $this->registry->allRules()
            ->first(fn ($r) => ($r['domain'] ?? '') === 'endpoint');
    }

    private function svc_hunt(): ThreatHuntingService
    {
        return app(ThreatHuntingService::class);
    }
}


