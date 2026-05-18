<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRiskSnapshot;
use App\Models\User;
use App\Services\EntityRiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EntityRiskScoringTest extends TestCase
{
    use RefreshDatabase;

    private EntityRiskScoringService $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new EntityRiskScoringService();
    }

    // -------------------------------------------------------------------------
    // Schema integrity
    // -------------------------------------------------------------------------

    public function test_risk_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('entity_risk_snapshots'));

        foreach (['risk_level', 'risk_factors', 'last_risk_calculated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('entities', $col),
                "entities missing column: {$col}"
            );
        }

        foreach (['entity_id', 'entity_type', 'entity_key', 'risk_score', 'risk_level', 'risk_factors', 'alert_ids', 'incident_ids', 'trace_ids', 'calculated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('entity_risk_snapshots', $col),
                "entity_risk_snapshots missing column: {$col}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // scoreToLevel — level threshold mapping
    // -------------------------------------------------------------------------

    public function test_score_to_level_maps_correctly(): void
    {
        $this->assertSame('low',      EntityRiskScoringService::scoreToLevel(0.0));
        $this->assertSame('low',      EntityRiskScoringService::scoreToLevel(2.49));
        $this->assertSame('medium',   EntityRiskScoringService::scoreToLevel(2.5));
        $this->assertSame('medium',   EntityRiskScoringService::scoreToLevel(4.99));
        $this->assertSame('high',     EntityRiskScoringService::scoreToLevel(5.0));
        $this->assertSame('high',     EntityRiskScoringService::scoreToLevel(7.49));
        $this->assertSame('critical', EntityRiskScoringService::scoreToLevel(7.5));
        $this->assertSame('critical', EntityRiskScoringService::scoreToLevel(10.0));
    }

    // -------------------------------------------------------------------------
    // Deterministic scoring
    // -------------------------------------------------------------------------

    public function test_risk_scoring_is_deterministic(): void
    {
        $entityId = $this->makeEntity('user', 'det-user@test.local');
        $this->insertAlert('det-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high', '2026-05-16 10:00:00',
            'det-user@test.local', null, 'trace-det-001');

        $result1 = $this->scorer->calculateRisk($entityId);
        $result2 = $this->scorer->calculateRisk($entityId);

        $this->assertSame($result1['score'], $result2['score']);
        $this->assertSame($result1['level'], $result2['level']);
        $this->assertSame($result1['factors'], $result2['factors']);
    }

    // -------------------------------------------------------------------------
    // Score aggregation
    // -------------------------------------------------------------------------

    public function test_aggregated_score_is_weighted_sum_of_factors(): void
    {
        $factors = [
            ['factor' => 'critical_alerts', 'value' => 1, 'weight' => 3.0, 'contribution' => 3.0],
            ['factor' => 'high_alerts',     'value' => 1, 'weight' => 2.0, 'contribution' => 2.0],
        ];

        $score = $this->scorer->aggregateScore($factors);

        $this->assertEqualsWithDelta(5.0, $score, 0.01);
    }

    public function test_score_is_capped_at_max_10(): void
    {
        $entityId = $this->makeEntity('user', 'maxscore-user@test.local');

        // Insert many critical alerts to push score well past 10
        for ($i = 1; $i <= 20; $i++) {
            $this->insertAlert(
                "max-alert-{$i}", 'IDENTITY_MFA_FAILURE_BURST', 'critical',
                "2026-05-16 {$i}:00:00", 'maxscore-user@test.local', null, null
            );
        }

        $result = $this->scorer->calculateRisk($entityId);

        $this->assertLessThanOrEqual(EntityRiskScoringService::MAX_SCORE, $result['score']);
    }

    public function test_critical_alerts_weigh_more_than_high_alerts(): void
    {
        $this->assertGreaterThan(
            EntityRiskScoringService::WEIGHTS['high_alerts'],
            EntityRiskScoringService::WEIGHTS['critical_alerts']
        );
    }

    public function test_mfa_failure_burst_weight_is_defined(): void
    {
        $this->assertArrayHasKey('mfa_failure_burst', EntityRiskScoringService::WEIGHTS);
        $this->assertGreaterThan(0.0, EntityRiskScoringService::WEIGHTS['mfa_failure_burst']);
    }

    // -------------------------------------------------------------------------
    // Explainability payloads
    // -------------------------------------------------------------------------

    public function test_each_factor_has_required_explainability_fields(): void
    {
        $entityId = $this->makeEntity('user', 'explain-user@test.local');
        $this->insertAlert('exp-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'explain-user@test.local', null, 'trace-exp-001');

        $result = $this->scorer->calculateRisk($entityId);

        $this->assertNotEmpty($result['factors'], 'Expected at least one contributing factor');

        foreach ($result['factors'] as $factor) {
            $this->assertArrayHasKey('factor',       $factor, "factor missing 'factor' key");
            $this->assertArrayHasKey('value',        $factor, "factor missing 'value' key");
            $this->assertArrayHasKey('weight',       $factor, "factor missing 'weight' key");
            $this->assertArrayHasKey('contribution', $factor, "factor missing 'contribution' key");
            $this->assertIsString($factor['factor']);
            $this->assertIsInt($factor['value']);
            $this->assertIsFloat($factor['weight']);
            $this->assertIsFloat($factor['contribution']);
        }
    }

    public function test_result_has_alert_ids_incident_ids_trace_ids(): void
    {
        $entityId = $this->makeEntity('user', 'ids-user@test.local');
        $this->insertAlert('ids-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'ids-user@test.local', null, 'trace-ids-001');

        $result = $this->scorer->calculateRisk($entityId);

        $this->assertArrayHasKey('alert_ids',    $result);
        $this->assertArrayHasKey('incident_ids', $result);
        $this->assertArrayHasKey('trace_ids',    $result);
        $this->assertIsArray($result['alert_ids']);
        $this->assertIsArray($result['incident_ids']);
        $this->assertIsArray($result['trace_ids']);
    }

    // -------------------------------------------------------------------------
    // Risk factor weighting
    // -------------------------------------------------------------------------

    public function test_user_with_mfa_burst_gets_mfa_failure_burst_factor(): void
    {
        $entityId = $this->makeEntity('user', 'mfa-user@test.local');
        $this->insertAlert('mfa-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'mfa-user@test.local', null, null);

        $result  = $this->scorer->calculateRisk($entityId);
        $factors = array_column($result['factors'], 'factor');

        $this->assertContains('mfa_failure_burst', $factors);
    }

    public function test_critical_alert_creates_critical_alerts_factor(): void
    {
        $entityId = $this->makeEntity('user', 'critical-user@test.local');
        $this->insertAlert('crit-alert-001', 'IDENTITY_RISKY_LOGIN', 'critical',
            '2026-05-16 10:00:00', 'critical-user@test.local', null, null);

        $result  = $this->scorer->calculateRisk($entityId);
        $factors = array_column($result['factors'], 'factor');

        $this->assertContains('critical_alerts', $factors);
    }

    public function test_incident_involvement_adds_factor(): void
    {
        $entityId = $this->makeEntity('incident', 'inc-factor-001');
        $this->insertIncident('inc-factor-001', 'Test Incident Factor', 'trace-inc-factor');

        $result  = $this->scorer->calculateRisk($entityId);
        $factors = array_column($result['factors'], 'factor');

        $this->assertContains('incident_involvement', $factors);
    }

    // -------------------------------------------------------------------------
    // Trace linkage preservation
    // -------------------------------------------------------------------------

    public function test_trace_ids_are_preserved_in_snapshot(): void
    {
        $entityId = $this->makeEntity('user', 'trace-user@test.local');
        $this->insertAlert('trace-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'trace-user@test.local', null, 'trace-linkage-001');

        $snapshot = $this->scorer->calculateAndPersist($entityId);

        $this->assertNotNull($snapshot);
        $this->assertContains('trace-linkage-001', $snapshot->trace_ids ?? []);
    }

    public function test_alert_ids_are_preserved_in_snapshot(): void
    {
        $entityId = $this->makeEntity('user', 'alertid-user@test.local');
        $this->insertAlert('alertid-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'alertid-user@test.local', null, null);

        $snapshot = $this->scorer->calculateAndPersist($entityId);

        $this->assertNotNull($snapshot);
        $this->assertContains('alertid-001', $snapshot->alert_ids ?? []);
    }

    // -------------------------------------------------------------------------
    // Replay-safe recalculation
    // -------------------------------------------------------------------------

    public function test_each_recalculation_appends_new_snapshot(): void
    {
        $entityId = $this->makeEntity('user', 'replay-user@test.local');
        $this->insertAlert('replay-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'replay-user@test.local', null, null);

        $this->scorer->calculateAndPersist($entityId);
        $this->scorer->calculateAndPersist($entityId);
        $this->scorer->calculateAndPersist($entityId);

        $count = EntityRiskSnapshot::where('entity_id', $entityId)->count();
        $this->assertSame(3, $count, 'Each call must append a new snapshot — never overwrite history');
    }

    public function test_replay_recalculation_produces_same_score(): void
    {
        $entityId = $this->makeEntity('user', 'replay2-user@test.local');
        $this->insertAlert('replay2-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'replay2-user@test.local', null, null);

        $s1 = $this->scorer->calculateAndPersist($entityId);
        $s2 = $this->scorer->calculateAndPersist($entityId);

        $this->assertEqualsWithDelta($s1->risk_score, $s2->risk_score, 0.001);
        $this->assertSame($s1->risk_level, $s2->risk_level);
    }

    public function test_entity_risk_fields_updated_after_persist(): void
    {
        $entityId = $this->makeEntity('user', 'persist-user@test.local');
        $this->insertAlert('persist-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'persist-user@test.local', null, null);

        $this->scorer->calculateAndPersist($entityId);

        $entity = DB::table('entities')->where('id', $entityId)->first();

        $this->assertNotNull($entity->risk_score);
        $this->assertNotNull($entity->risk_level);
        $this->assertNotNull($entity->last_risk_calculated_at);
        $this->assertNotNull($entity->risk_factors);
    }

    // -------------------------------------------------------------------------
    // No automatic enforcement
    // -------------------------------------------------------------------------

    public function test_calculate_risk_does_not_mutate_security_alerts(): void
    {
        $entityId = $this->makeEntity('user', 'noauto-user@test.local');
        $this->insertAlert('noauto-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'noauto-user@test.local', null, null);

        $alertCountBefore = DB::table('security_alerts')->count();
        $this->scorer->calculateRisk($entityId);
        $alertCountAfter = DB::table('security_alerts')->count();

        $this->assertSame($alertCountBefore, $alertCountAfter,
            'calculateRisk must NOT insert or modify security_alerts rows');
    }

    public function test_calculate_risk_does_not_mutate_security_incidents(): void
    {
        $entityId = $this->makeEntity('user', 'noauto2-user@test.local');
        $incidentCountBefore = DB::table('security_incidents')->count();

        $this->scorer->calculateRisk($entityId);

        $incidentCountAfter = DB::table('security_incidents')->count();
        $this->assertSame($incidentCountBefore, $incidentCountAfter,
            'calculateRisk must NOT insert or modify security_incidents rows');
    }

    public function test_risk_result_has_no_enforcement_keys(): void
    {
        $entityId = $this->makeEntity('ip', '198.51.100.99');
        $result   = $this->scorer->calculateRisk($entityId);

        $forbidden = ['block', 'quarantine', 'isolate', 'contain', 'terminate'];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $result,
                "Risk result must not contain enforcement key: {$key}");
        }
    }

    // -------------------------------------------------------------------------
    // Shadow endpoint alert advisory_only flag
    // -------------------------------------------------------------------------

    public function test_shadow_alert_factor_is_marked_advisory_only(): void
    {
        $entityId = $this->makeEntity('ip', '10.55.66.77');

        // Insert a shadow observation
        DB::table('entity_observations')->insert([
            'entity_id'        => $entityId,
            'observation_type' => 'shadow_alert_received',
            'source_table'     => 'shadow_alerts',
            'source_event_id'  => 'shadow-evt-001',
            'trace_id'         => null,
            'observed_at'      => '2026-05-16 10:00:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $result        = $this->scorer->calculateRisk($entityId);
        $advisoryFactors = array_filter($result['factors'], fn($f) => !empty($f['advisory_only']));

        $this->assertNotEmpty($advisoryFactors,
            'shadow_alert_advisory factor must have advisory_only = true');

        foreach ($advisoryFactors as $f) {
            $this->assertTrue($f['advisory_only'],
                "Factor {$f['factor']} advisory_only must be true");
        }
    }

    // -------------------------------------------------------------------------
    // getRiskHistory
    // -------------------------------------------------------------------------

    public function test_risk_history_returns_snapshots_in_desc_order(): void
    {
        $entityId = $this->makeEntity('user', 'history-user@test.local');
        $this->insertAlert('hist-alert-001', 'IDENTITY_MFA_FAILURE_BURST', 'high',
            '2026-05-16 10:00:00', 'history-user@test.local', null, null);

        $this->scorer->calculateAndPersist($entityId);
        $this->scorer->calculateAndPersist($entityId);

        $history = $this->scorer->getRiskHistory($entityId);
        $this->assertSame(2, $history->count());
    }

    // -------------------------------------------------------------------------
    // API authorization
    // -------------------------------------------------------------------------

    public function test_entity_risk_api_index_requires_authentication(): void
    {
        $this->get('/api/entity-risk')->assertRedirect();
    }

    public function test_entity_risk_api_top_requires_authentication(): void
    {
        $this->get('/api/entity-risk/top')->assertRedirect();
    }

    public function test_entity_risk_api_entity_requires_authentication(): void
    {
        $this->get('/api/entities/1/risk')->assertRedirect();
    }

    public function test_entity_risk_api_history_requires_authentication(): void
    {
        $this->get('/api/entities/1/risk-history')->assertRedirect();
    }

    public function test_entity_risk_api_index_returns_json_for_analyst(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($user)->get('/api/entity-risk')
            ->assertOk()
            ->assertJsonStructure(['entities', 'total']);
    }

    public function test_entity_risk_api_top_returns_json_for_analyst(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($user)->get('/api/entity-risk/top')
            ->assertOk()
            ->assertJsonStructure(['users', 'hosts', 'ips', 'domains', 'distribution']);
    }

    public function test_entity_risk_api_entity_returns_404_for_missing(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($user)->get('/api/entities/99999/risk')
            ->assertNotFound()
            ->assertJson(['error' => 'entity_not_found']);
    }

    public function test_risk_dashboard_requires_authentication(): void
    {
        $this->get('/entity-risk')->assertRedirect();
    }

    public function test_risk_dashboard_returns_200_for_analyst(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($user)->get('/entity-risk')->assertOk();
    }

    public function test_risk_breakdown_requires_authentication(): void
    {
        $this->get('/entity-risk/1/breakdown')->assertRedirect();
    }

    // -------------------------------------------------------------------------
    // getTopRiskyEntities
    // -------------------------------------------------------------------------

    public function test_get_top_risky_entities_returns_only_scored_entities(): void
    {
        $this->makeEntity('user', 'unscored-user@test.local');

        $scoredId = $this->makeEntity('user', 'scored-user@test.local');
        DB::table('entities')->where('id', $scoredId)->update([
            'risk_score' => 8.5,
            'risk_level' => 'critical',
        ]);

        $top = $this->scorer->getTopRiskyEntities('user', 10);

        $this->assertTrue($top->every(fn($e) => $e->risk_score !== null));
        $this->assertDatabaseHas('entities', ['entity_key' => 'scored-user@test.local', 'risk_level' => 'critical']);
    }

    // -------------------------------------------------------------------------
    // Grafana dashboard
    // -------------------------------------------------------------------------

    public function test_entity_risk_grafana_dashboard_file_exists(): void
    {
        $path = base_path('docs/grafana/entity-risk-dashboard.json');
        $this->assertFileExists($path);

        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('panels', $json);
        $this->assertNotEmpty($json['panels']);
        $this->assertSame('XDR Entity Risk Dashboard', $json['title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEntity(string $type, string $key): int
    {
        return DB::table('entities')->insertGetId([
            'entity_type'       => $type,
            'entity_key'        => $key,
            'display_name'      => $key,
            'first_seen_at'     => '2026-05-16 09:00:00',
            'last_seen_at'      => '2026-05-16 10:00:00',
            'observation_count' => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function insertAlert(
        string  $alertId,
        string  $alertType,
        string  $severity,
        string  $detectedAt,
        ?string $actorKey = null,
        ?string $ip = null,
        ?string $traceId = null
    ): void {
        DB::table('security_alerts')->insert([
            'alert_id'   => $alertId,
            'alert_type' => $alertType,
            'severity'   => $severity,
            'detected_at'=> $detectedAt,
            'actor_key'  => $actorKey,
            'ip'         => $ip,
            'trace_id'   => $traceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertIncident(string $incidentId, string $title, ?string $traceId = null): void
    {
        DB::table('security_incidents')->insert([
            'incident_id'  => $incidentId,
            'title'        => $title,
            'status'       => 'open',
            'severity'     => 'high',
            'first_seen_at'=> '2026-05-16 09:00:00',
            'last_seen_at' => '2026-05-16 10:00:00',
            'trace_id'     => $traceId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
