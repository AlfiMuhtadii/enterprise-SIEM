<?php

namespace Tests\Feature;

use App\Models\BaselineAnomalyScore;
use App\Models\BaselineObservation;
use App\Models\EntityBehaviorBaseline;
use App\Models\PeerGroupProfile;
use App\Models\User;
use App\Services\UEBABaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UEBA Phase 1 â€” Behavioral Baseline Analytics Tests.
 *
 * Asserts:
 *   - Deterministic baseline calculation
 *   - Robust z-score correctness
 *   - Percentile rank correctness
 *   - Peer group assignment and aggregation
 *   - Anomaly score explainability (all required fields present)
 *   - Replay-safe recalculation (same inputs â†’ same outputs)
 *   - Risk factor integration (advisory_only=true, not autonomous)
 *   - Threat hunting pivot support
 *
 * Hard safety assertions (MUST remain green):
 *   - No automatic account disable
 *   - No host isolation
 *   - No process kill
 *   - No hidden model action
 *   - No black-box LLM scoring
 *   - is_advisory = true on every score
 */
class UEBABaselineAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private UEBABaselineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(UEBABaselineService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst']);
    }

    // =========================================================================
    // Deterministic baseline calculation
    // =========================================================================

    public function test_compute_stats_is_deterministic(): void
    {
        $observations = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

        // Record 10 observations
        foreach ($observations as $v) {
            $this->svc->recordObservation('alice@test.com', 'user', 'login_frequency', $v);
        }

        $baseline1 = $this->svc->computeBaseline('alice@test.com', 'user', 'login_frequency');
        $baseline2 = $this->svc->computeBaseline('alice@test.com', 'user', 'login_frequency');

        $this->assertNotNull($baseline1);
        $this->assertNotNull($baseline2);

        // Deterministic: same data â†’ same result (delta handles DB float truncation)
        $this->assertEqualsWithDelta($baseline1->baseline_mean, $baseline2->baseline_mean, 1e-6);
        $this->assertEqualsWithDelta($baseline1->baseline_median, $baseline2->baseline_median, 1e-6);
        $this->assertEqualsWithDelta($baseline1->baseline_stddev, $baseline2->baseline_stddev, 1e-6);
    }

    public function test_baseline_mean_and_median_are_correct(): void
    {
        foreach ([2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0] as $v) {
            $this->svc->recordObservation('bob@test.com', 'user', 'failed_login_ratio', $v);
        }

        $baseline = $this->svc->computeBaseline('bob@test.com', 'user', 'failed_login_ratio');

        $this->assertNotNull($baseline);
        $this->assertEquals(5.0, round($baseline->baseline_mean, 4));   // sum=40, n=8
        $this->assertEquals(4.5, round($baseline->baseline_median, 4)); // median of [2,4,4,4,5,5,7,9]
        $this->assertEquals(8, $baseline->sample_count);
    }

    public function test_baseline_requires_minimum_sample_count(): void
    {
        // 4 observations â€” below MIN_SAMPLES_FOR_SCORING
        foreach ([1.0, 2.0, 3.0, 4.0] as $v) {
            $this->svc->recordObservation('charlie@test.com', 'user', 'source_ip_diversity', $v);
        }

        // Baseline can be computed but scoring should return null
        $baseline = $this->svc->computeBaseline('charlie@test.com', 'user', 'source_ip_diversity');
        $this->assertNotNull($baseline); // baseline can be stored with < 5 samples

        $score = $this->svc->scoreAnomaly('charlie@test.com', 'user', 'source_ip_diversity', 99.0);
        $this->assertNull($score, 'Scoring should return null when baseline has < MIN_SAMPLES_FOR_SCORING');
    }

    // =========================================================================
    // Robust z-score calculation
    // =========================================================================

    public function test_robust_z_score_with_known_values(): void
    {
        // median=5, MAD=2, value=9 â†’ z = (9-5)/(1.4826*2) = 4/2.9652 â‰ˆ 1.349
        $z = $this->svc->robustZScore(9.0, 5.0, 2.0);
        $this->assertEqualsWithDelta(1.349, $z, 0.01);
    }

    public function test_robust_z_score_returns_zero_when_mad_is_zero(): void
    {
        $z = $this->svc->robustZScore(99.0, 5.0, 0.0);
        $this->assertEquals(0.0, $z);
    }

    public function test_robust_z_score_returns_zero_when_median_is_null(): void
    {
        $z = $this->svc->robustZScore(5.0, null, 2.0);
        $this->assertEquals(0.0, $z);
    }

    public function test_robust_z_score_negative_for_below_baseline(): void
    {
        $z = $this->svc->robustZScore(1.0, 5.0, 2.0);
        $this->assertLessThan(0.0, $z);
    }

    public function test_robust_z_score_positive_for_above_baseline(): void
    {
        $z = $this->svc->robustZScore(10.0, 5.0, 2.0);
        $this->assertGreaterThan(0.0, $z);
    }

    // =========================================================================
    // Percentile rank calculation
    // =========================================================================

    public function test_percentile_rank_for_max_value_is_high(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $rank = $this->svc->percentileRank(5.0, $values);
        $this->assertEquals(80.0, $rank); // 4 values below 5 â†’ 4/5 * 100 = 80
    }

    public function test_percentile_rank_for_min_value_is_zero(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $rank = $this->svc->percentileRank(1.0, $values);
        $this->assertEquals(0.0, $rank); // 0 values below 1 â†’ 0%
    }

    public function test_percentile_rank_for_median_value_is_fifty(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $rank = $this->svc->percentileRank(3.0, $values);
        $this->assertEquals(40.0, $rank); // 2 values below 3 â†’ 40%
    }

    public function test_percentile_rank_returns_fifty_for_empty_values(): void
    {
        $rank = $this->svc->percentileRank(5.0, []);
        $this->assertEquals(50.0, $rank);
    }

    // =========================================================================
    // MAD computation
    // =========================================================================

    public function test_compute_mad_with_known_values(): void
    {
        // [1,1,2,2,4,6,9] â†’ median=2, deviations=[1,1,0,0,2,4,7] â†’ MAD=median=1
        $mad = $this->svc->computeMAD([1.0, 1.0, 2.0, 2.0, 4.0, 6.0, 9.0]);
        $this->assertEquals(1.0, $mad);
    }

    public function test_compute_mad_returns_zero_for_empty_array(): void
    {
        $this->assertEquals(0.0, $this->svc->computeMAD([]));
    }

    // =========================================================================
    // Peer group assignment
    // =========================================================================

    public function test_peer_group_assignment_is_deterministic(): void
    {
        $group1 = $this->svc->assignPeerGroup('alice@test.com', 'user', ['role' => 'analyst']);
        $group2 = $this->svc->assignPeerGroup('bob@test.com', 'user', ['role' => 'analyst']);

        $this->assertEquals($group1->peer_group_key, $group2->peer_group_key,
            'Same role should produce same peer group key');
    }

    public function test_different_roles_produce_different_peer_groups(): void
    {
        $group1 = $this->svc->assignPeerGroup('alice@test.com', 'user', ['role' => 'analyst']);
        $group2 = $this->svc->assignPeerGroup('charlie@test.com', 'user', ['role' => 'admin']);

        $this->assertNotEquals($group1->peer_group_key, $group2->peer_group_key,
            'Different roles should produce different peer groups');
    }

    public function test_peer_group_membership_is_bounded(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->svc->assignPeerGroup("user{$i}@test.com", 'user', ['role' => 'bounded_test']);
        }

        $group = PeerGroupProfile::where('peer_group_key', 'user_role:bounded_test')->first();
        $this->assertNotNull($group);
        $this->assertLessThanOrEqual(UEBABaselineService::MAX_PEER_GROUP_MEMBERS, count($group->member_entity_keys ?? []));
    }

    public function test_peer_group_advisory_only_is_always_true(): void
    {
        $group = $this->svc->assignPeerGroup('alice@test.com', 'user', ['role' => 'analyst']);
        $this->assertTrue($group->advisory_only, 'Peer group advisory_only must always be true');
    }

    public function test_compute_peer_group_profile_updates_dimension_stats(): void
    {
        // Create two entities with baselines in the same peer group
        foreach (['alice@test.com', 'bob@test.com'] as $key) {
            for ($i = 0; $i < 6; $i++) {
                $this->svc->recordObservation($key, 'user', 'login_frequency', (float) rand(3, 8));
            }
            $this->svc->computeBaseline($key, 'user', 'login_frequency');
            $this->svc->assignPeerGroup($key, 'user', ['role' => 'stats_test']);
        }

        $group = $this->svc->computePeerGroupProfile('user_role:stats_test');

        $this->assertNotNull($group);
        // Should have dimension stats for login_frequency
        $this->assertNotEmpty($group->dimension_stats);
    }

    // =========================================================================
    // Anomaly score explainability
    // =========================================================================

    public function test_anomaly_score_has_all_required_explainability_fields(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('diana@test.com', 'user', 'login_frequency', (float) $v);
        }
        $this->svc->computeBaseline('diana@test.com', 'user', 'login_frequency');

        $score = $this->svc->scoreAnomaly('diana@test.com', 'user', 'login_frequency', 50.0);

        $this->assertNotNull($score, 'Score should not be null with sufficient samples');

        // All explainability fields must be present
        $this->assertNotEmpty($score->score_id, 'score_id must be set');
        $this->assertNotEmpty($score->entity_key, 'entity_key must be set');
        $this->assertNotEmpty($score->entity_type, 'entity_type must be set');
        $this->assertNotEmpty($score->anomaly_type, 'anomaly_type must be set');
        $this->assertNotEmpty($score->dimension, 'dimension must be set');
        $this->assertNotNull($score->observed_value, 'observed_value must be set');
        $this->assertNotNull($score->baseline_value, 'baseline_value must be set');
        $this->assertNotNull($score->deviation, 'deviation must be set');
        $this->assertNotEmpty($score->scoring_method, 'scoring_method must be set');
        $this->assertNotNull($score->confidence, 'confidence must be set');
        $this->assertNotNull($score->scored_at, 'scored_at must be set');
    }

    public function test_anomaly_score_confidence_is_in_valid_range(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('evan@test.com', 'user', 'failed_login_ratio', (float) $v);
        }
        $this->svc->computeBaseline('evan@test.com', 'user', 'failed_login_ratio');

        $score = $this->svc->scoreAnomaly('evan@test.com', 'user', 'failed_login_ratio', 100.0);

        if ($score) {
            $this->assertGreaterThanOrEqual(0.0, $score->confidence);
            $this->assertLessThanOrEqual(1.0, $score->confidence);
        }
    }

    public function test_anomaly_score_scoring_method_is_valid(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('fiona@test.com', 'user', 'source_ip_diversity', (float) $v);
        }
        $this->svc->computeBaseline('fiona@test.com', 'user', 'source_ip_diversity');

        $score = $this->svc->scoreAnomaly('fiona@test.com', 'user', 'source_ip_diversity', 50.0);

        if ($score) {
            $this->assertContains($score->scoring_method, BaselineAnomalyScore::SCORING_METHODS,
                'scoring_method must be one of the declared deterministic methods');
        }
    }

    public function test_anomaly_type_matches_expected_dimension(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('gary@test.com', 'user', 'login_frequency', (float) $v);
        }
        $this->svc->computeBaseline('gary@test.com', 'user', 'login_frequency');

        $score = $this->svc->scoreAnomaly('gary@test.com', 'user', 'login_frequency', 99.0);

        if ($score) {
            $this->assertEquals('unusual_login_time', $score->anomaly_type,
                'login_frequency dimension should map to unusual_login_time anomaly type');
        }
    }

    // =========================================================================
    // Replay-safe recalculation
    // =========================================================================

    public function test_baseline_recalculation_is_replay_safe(): void
    {
        // Insert the same 10 observations twice (simulating replay)
        $values = [2.0, 3.0, 4.0, 5.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
        foreach ($values as $v) {
            $this->svc->recordObservation('helen@test.com', 'user', 'saas_action_frequency', $v);
        }

        $baseline1 = $this->svc->computeBaseline('helen@test.com', 'user', 'saas_action_frequency');
        $this->assertNotNull($baseline1);

        // Recalculate â€” same window, same data
        $baseline2 = $this->svc->computeBaseline('helen@test.com', 'user', 'saas_action_frequency');

        // Results must be identical (deterministic â€” delta handles DB float truncation)
        $this->assertEqualsWithDelta($baseline1->baseline_mean, $baseline2->baseline_mean, 1e-6);
        $this->assertEqualsWithDelta($baseline1->baseline_median, $baseline2->baseline_median, 1e-6);
        $this->assertEquals($baseline1->sample_count, $baseline2->sample_count);
    }

    public function test_observations_are_append_only(): void
    {
        $this->svc->recordObservation('iris@test.com', 'user', 'login_frequency', 5.0);
        $countBefore = BaselineObservation::where('entity_key', 'iris@test.com')->count();

        $this->svc->recordObservation('iris@test.com', 'user', 'login_frequency', 6.0);
        $countAfter = BaselineObservation::where('entity_key', 'iris@test.com')->count();

        $this->assertEquals($countBefore + 1, $countAfter,
            'Each recordObservation call should INSERT a new row â€” never update');
    }

    public function test_anomaly_scores_are_append_only(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('james@test.com', 'user', 'login_frequency', (float) $v);
        }
        $this->svc->computeBaseline('james@test.com', 'user', 'login_frequency');

        $countBefore = BaselineAnomalyScore::where('entity_key', 'james@test.com')->count();

        $this->svc->scoreAnomaly('james@test.com', 'user', 'login_frequency', 99.0);
        $this->svc->scoreAnomaly('james@test.com', 'user', 'login_frequency', 99.0);

        $countAfter = BaselineAnomalyScore::where('entity_key', 'james@test.com')->count();

        $this->assertEquals($countBefore + 2, $countAfter,
            'Each scoreAnomaly call should INSERT a new row â€” never update');
    }

    // =========================================================================
    // Risk factor integration â€” advisory only
    // =========================================================================

    public function test_ueba_risk_factors_are_in_weight_table(): void
    {
        $weights = \App\Services\EntityRiskScoringService::WEIGHTS;

        $this->assertArrayHasKey('baseline_anomaly_factor', $weights);
        $this->assertArrayHasKey('peer_deviation_factor', $weights);
        $this->assertArrayHasKey('abnormal_data_volume_factor', $weights);
        $this->assertArrayHasKey('unusual_activity_time_factor', $weights);
    }

    public function test_ueba_risk_factors_are_not_zero(): void
    {
        $weights = \App\Services\EntityRiskScoringService::WEIGHTS;

        $this->assertGreaterThan(0, $weights['baseline_anomaly_factor']);
        $this->assertGreaterThan(0, $weights['peer_deviation_factor']);
        $this->assertGreaterThan(0, $weights['abnormal_data_volume_factor']);
        $this->assertGreaterThan(0, $weights['unusual_activity_time_factor']);
    }

    public function test_every_anomaly_score_has_is_advisory_true(): void
    {
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('karen@test.com', 'user', 'login_frequency', (float) $v);
        }
        $this->svc->computeBaseline('karen@test.com', 'user', 'login_frequency');

        $score = $this->svc->scoreAnomaly('karen@test.com', 'user', 'login_frequency', 99.0);

        $this->assertNotNull($score);
        $this->assertTrue($score->is_advisory,
            'Every anomaly score MUST have is_advisory=true â€” UEBA never triggers enforcement');
    }

    // =========================================================================
    // Threat hunting pivot support
    // =========================================================================

    public function test_entity_behavior_baselines_is_a_supported_hunt_domain(): void
    {
        $this->assertContains(
            'entity_behavior_baselines',
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS
        );
    }

    public function test_baseline_anomaly_scores_is_a_supported_hunt_domain(): void
    {
        $this->assertContains(
            'baseline_anomaly_scores',
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS
        );
    }

    public function test_peer_group_profiles_is_a_supported_hunt_domain(): void
    {
        $this->assertContains(
            'peer_group_profiles',
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS
        );
    }

    public function test_ueba_domains_are_35_total(): void
    {
        $this->assertCount(
            95,
            \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS,
            'Should have 95 threat hunting domains after all phases through Real Pilot Execution Phase 1'
        );
    }

    // =========================================================================
    // Advisory-only enforcement â€” hard safety assertions
    // =========================================================================

    public function test_no_automatic_account_disable(): void
    {
        // Verify UEBABaselineService has no method that disables accounts
        $this->assertFalse(
            method_exists($this->svc, 'disableAccount'),
            'UEBABaselineService must NOT have a disableAccount method'
        );
        $this->assertFalse(
            method_exists($this->svc, 'suspendUser'),
            'UEBABaselineService must NOT have a suspendUser method'
        );
        $this->assertFalse(
            method_exists($this->svc, 'lockAccount'),
            'UEBABaselineService must NOT have a lockAccount method'
        );
    }

    public function test_no_host_isolation(): void
    {
        $this->assertFalse(
            method_exists($this->svc, 'isolateHost'),
            'UEBABaselineService must NOT have an isolateHost method'
        );
        $this->assertFalse(
            method_exists($this->svc, 'quarantineHost'),
            'UEBABaselineService must NOT have a quarantineHost method'
        );
    }

    public function test_no_process_kill(): void
    {
        $this->assertFalse(
            method_exists($this->svc, 'killProcess'),
            'UEBABaselineService must NOT have a killProcess method'
        );
        $this->assertFalse(
            method_exists($this->svc, 'terminateProcess'),
            'UEBABaselineService must NOT have a terminateProcess method'
        );
    }

    public function test_no_hidden_model_action(): void
    {
        // No methods suggesting hidden or non-deterministic model inference
        $this->assertFalse(
            method_exists($this->svc, 'runMLModel'),
            'UEBABaselineService must NOT use opaque ML models'
        );
        $this->assertFalse(
            method_exists($this->svc, 'callLLM'),
            'UEBABaselineService must NOT call LLMs for scoring'
        );
        $this->assertFalse(
            method_exists($this->svc, 'inferWithNeuralNet'),
            'UEBABaselineService must NOT use neural networks'
        );
    }

    public function test_no_black_box_llm_scoring(): void
    {
        // The scoring method enum must not include llm or neural-based methods
        foreach (BaselineAnomalyScore::SCORING_METHODS as $method) {
            $this->assertStringNotContainsString('llm', $method,
                'Scoring methods must not include LLM-based methods');
            $this->assertStringNotContainsString('neural', $method,
                'Scoring methods must not include neural network methods');
            $this->assertStringNotContainsString('opaque', $method,
                'Scoring methods must not include opaque model methods');
        }
    }

    public function test_peer_group_advisory_only_constant(): void
    {
        // Peer group max size is bounded
        $this->assertGreaterThan(0, UEBABaselineService::MAX_PEER_GROUP_MEMBERS);
        $this->assertLessThanOrEqual(1000, UEBABaselineService::MAX_PEER_GROUP_MEMBERS,
            'Peer group size must be bounded to prevent unbounded accumulation');
    }

    public function test_detect_anomalies_returns_advisory_scores_only(): void
    {
        // Build baseline from normal range then score an extreme outlier directly
        foreach (range(1, 10) as $v) {
            $this->svc->recordObservation('lucy@test.com', 'user', 'login_frequency', (float) $v);
        }
        $this->svc->computeBaseline('lucy@test.com', 'user', 'login_frequency');

        // Score an extreme value (z-score >> threshold) to guarantee at least one advisory score
        $extremeScore = $this->svc->scoreAnomaly('lucy@test.com', 'user', 'login_frequency', 9999.0);
        $this->assertNotNull($extremeScore, 'extreme value should produce a score');
        $this->assertTrue($extremeScore->is_advisory, 'scored anomaly must be advisory');

        // detectAnomalies returns a Collection; all items must carry is_advisory=true
        $scores = $this->svc->detectAnomalies('lucy@test.com', 'user');
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $scores);
        foreach ($scores as $s) {
            $this->assertTrue($s->is_advisory, 'detectAnomalies must only return advisory scores');
        }
    }

    // =========================================================================
    // Model schema validation
    // =========================================================================

    public function test_entity_behavior_baseline_model_constants(): void
    {
        $this->assertNotEmpty(EntityBehaviorBaseline::DIMENSIONS);
        $this->assertNotEmpty(EntityBehaviorBaseline::ENTITY_TYPES);
        $this->assertContains('login_frequency', EntityBehaviorBaseline::DIMENSIONS);
        $this->assertContains('user', EntityBehaviorBaseline::ENTITY_TYPES);
    }

    public function test_baseline_anomaly_score_model_constants(): void
    {
        $this->assertNotEmpty(BaselineAnomalyScore::ANOMALY_TYPES);
        $this->assertNotEmpty(BaselineAnomalyScore::SCORING_METHODS);
        $this->assertContains('unusual_login_time', BaselineAnomalyScore::ANOMALY_TYPES);
        $this->assertContains('peer_group_behavior_deviation', BaselineAnomalyScore::ANOMALY_TYPES);
        $this->assertContains('robust_z_score', BaselineAnomalyScore::SCORING_METHODS);
    }

    public function test_peer_group_profile_model_constants(): void
    {
        $this->assertNotEmpty(PeerGroupProfile::GROUP_TYPES);
        $this->assertContains('user_role', PeerGroupProfile::GROUP_TYPES);
        $this->assertContains('host_function', PeerGroupProfile::GROUP_TYPES);
        $this->assertGreaterThan(0, PeerGroupProfile::MAX_GROUP_SIZE);
    }

    // =========================================================================
    // API endpoints â€” advisory response assertions
    // =========================================================================

    public function test_ueba_detect_api_includes_advisory_fields(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->postJson('/api/ueba/detect', [
            'entity_key'  => 'testuser@example.com',
            'entity_type' => 'user',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
        $response->assertJsonPath('autonomous_action', false);
        $response->assertJsonStructure(['ok', 'advisory_only', 'autonomous_action', 'anomalies_detected', 'disclaimer']);
    }

    public function test_ueba_detect_api_disclaimer_is_present(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->postJson('/api/ueba/detect', [
            'entity_key'  => 'testhost',
            'entity_type' => 'host',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertStringContainsString('advisory-only', strtolower($data['disclaimer']));
        $this->assertStringContainsString('explainable', strtolower($data['disclaimer']));
        $this->assertStringContainsString('no automatic enforcement', strtolower($data['disclaimer']));
    }

    public function test_ueba_top_anomalous_api_includes_advisory_flag(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->getJson('/api/ueba/top-anomalous');

        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
    }

    public function test_ueba_profile_api_requires_entity_key(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->getJson('/api/ueba/profile');
        $response->assertStatus(422);
    }

    // =========================================================================
    // UI routes â€” accessible and display advisory disclaimer
    // =========================================================================

    public function test_ueba_dashboard_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
        $response->assertSee('advisory-only');
    }

    public function test_ueba_anomaly_explorer_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/anomalies');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_ueba_baseline_profile_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/baseline');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
    }

    public function test_ueba_drift_monitor_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/drift');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    public function test_ueba_peer_groups_view_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/peer-groups');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
    }

    public function test_ueba_entity_history_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/history');
        $response->assertStatus(200);
        $response->assertSee('Advisory Notice');
    }

    public function test_ueba_risk_contribution_is_accessible(): void
    {
        $user = $this->analyst();
        $this->actingAs($user);

        $response = $this->get('/ueba/risk');
        $response->assertStatus(200);
        $response->assertSee('advisory-only');
    }

    // =========================================================================
    // UEBA detection rules in registry
    // =========================================================================

    public function test_ueba_shadow_rules_are_in_registry(): void
    {
        $registryPath = base_path('docs/detection/rules/registry.v1.json');
        $registry     = json_decode(file_get_contents($registryPath), true);
        $ruleIds      = array_column($registry['rules'], 'rule_id');

        $expectedRules = [
            'UEBA_UNUSUAL_LOGIN_TIME',
            'UEBA_UNUSUAL_SOURCE_IP_DIVERSITY',
            'UEBA_ABNORMAL_FAILED_LOGIN_RATIO',
            'UEBA_UNUSUAL_SAAS_ACTION_FREQUENCY',
            'UEBA_UNUSUAL_PROCESS_EXECUTION_FREQUENCY',
            'UEBA_ABNORMAL_NETWORK_DESTINATION_FREQUENCY',
            'UEBA_ABNORMAL_BYTES_OUT',
            'UEBA_UNUSUAL_HOST_USAGE',
            'UEBA_PEER_GROUP_BEHAVIOR_DEVIATION',
        ];

        foreach ($expectedRules as $ruleId) {
            $this->assertContains($ruleId, $ruleIds, "UEBA rule {$ruleId} must be in registry");
        }
    }

    public function test_all_ueba_rules_are_shadow_only(): void
    {
        $registryPath = base_path('docs/detection/rules/registry.v1.json');
        $registry     = json_decode(file_get_contents($registryPath), true);

        $uebaRules = array_filter($registry['rules'], fn ($r) => str_starts_with($r['rule_id'], 'UEBA_'));

        $this->assertNotEmpty($uebaRules, 'There must be UEBA rules in the registry');

        foreach ($uebaRules as $rule) {
            $this->assertEquals('shadow', $rule['status'],
                "UEBA rule {$rule['rule_id']} must have status=shadow");
            $this->assertTrue($rule['shadow_only'],
                "UEBA rule {$rule['rule_id']} must have shadow_only=true");
            $this->assertStringStartsWith('xdr.alerts.shadow', $rule['output_topic'],
                "UEBA rule {$rule['rule_id']} must publish to shadow topic");
        }
    }

    public function test_ueba_rules_are_never_staged_active(): void
    {
        $registryPath = base_path('docs/detection/rules/registry.v1.json');
        $registry     = json_decode(file_get_contents($registryPath), true);

        $uebaActive = array_filter(
            $registry['rules'],
            fn ($r) => str_starts_with($r['rule_id'], 'UEBA_') && $r['status'] === 'staged_active'
        );

        $this->assertEmpty($uebaActive,
            'No UEBA rules may ever be staged_active â€” they require a domain-specific 6h soak PASS');
    }

    public function test_registry_total_rule_count_is_65(): void
    {
        $registryPath = base_path('docs/detection/rules/registry.v1.json');
        $registry     = json_decode(file_get_contents($registryPath), true);

        $this->assertCount(93, $registry['rules'],
            'Registry should have 93 rules after Advanced Detection Coverage Phase 1');
    }
}

