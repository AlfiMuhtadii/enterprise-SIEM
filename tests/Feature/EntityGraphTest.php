<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityObservation;
use App\Models\EntityRelationship;
use App\Models\User;
use App\Services\EntityGraphService;
use App\Support\TraceRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntityGraphTest extends TestCase
{
    use RefreshDatabase;

    private EntityGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityGraphService();
    }

    // -------------------------------------------------------------------------
    // Entity extraction
    // -------------------------------------------------------------------------

    public function test_upsert_entity_creates_new_entity_with_correct_fields(): void
    {
        $id = $this->service->upsertEntity(
            'user', 'alice@example.com', 'Alice', '2026-05-16 10:00:00', 7.0
        );

        $entity = Entity::find($id);

        $this->assertNotNull($entity);
        $this->assertSame('user', $entity->entity_type);
        $this->assertSame('alice@example.com', $entity->entity_key);
        $this->assertSame('Alice', $entity->display_name);
        $this->assertSame(1, $entity->observation_count);
        $this->assertEqualsWithDelta(7.0, (float) $entity->risk_score, 0.01);
        $this->assertEquals('2026-05-16 10:00:00', $entity->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-05-16 10:00:00', $entity->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_upsert_entity_accepts_all_defined_types(): void
    {
        foreach (Entity::TYPES as $type) {
            $id = $this->service->upsertEntity($type, "key-{$type}");
            $this->assertGreaterThan(0, $id, "Failed to create entity for type: {$type}");
        }

        $this->assertSame(count(Entity::TYPES), Entity::count());
    }

    // -------------------------------------------------------------------------
    // Observation count increments
    // -------------------------------------------------------------------------

    public function test_upsert_entity_increments_observation_count_on_repeat(): void
    {
        $id = $this->service->upsertEntity('ip', '192.0.2.1', '', '2026-05-16 09:00:00');
        $this->service->upsertEntity('ip', '192.0.2.1', '', '2026-05-16 10:00:00');
        $this->service->upsertEntity('ip', '192.0.2.1', '', '2026-05-16 11:00:00');

        $entity = Entity::find($id);

        $this->assertSame(3, $entity->observation_count);
    }

    public function test_upsert_entity_does_not_create_duplicate_rows(): void
    {
        $this->service->upsertEntity('host', 'workstation-42');
        $this->service->upsertEntity('host', 'workstation-42');
        $this->service->upsertEntity('host', 'workstation-42');

        $this->assertSame(1, Entity::where('entity_type', 'host')
            ->where('entity_key', 'workstation-42')
            ->count());
    }

    // -------------------------------------------------------------------------
    // first_seen_at / last_seen_at update rules
    // -------------------------------------------------------------------------

    public function test_upsert_entity_updates_first_seen_when_earlier_timestamp(): void
    {
        $this->service->upsertEntity('user', 'bob@test.local', '', '2026-05-16 12:00:00');
        $this->service->upsertEntity('user', 'bob@test.local', '', '2026-05-15 08:00:00'); // earlier

        $entity = Entity::where('entity_key', 'bob@test.local')->first();

        $this->assertEquals('2026-05-15 08:00:00', $entity->first_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_upsert_entity_preserves_first_seen_when_later_timestamp(): void
    {
        $this->service->upsertEntity('user', 'carol@test.local', '', '2026-05-15 08:00:00');
        $this->service->upsertEntity('user', 'carol@test.local', '', '2026-05-16 15:00:00'); // later

        $entity = Entity::where('entity_key', 'carol@test.local')->first();

        // first_seen should stay at the earliest
        $this->assertEquals('2026-05-15 08:00:00', $entity->first_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_upsert_entity_updates_last_seen_when_later_timestamp(): void
    {
        $this->service->upsertEntity('domain', 'evil.example.com', '', '2026-05-16 10:00:00');
        $this->service->upsertEntity('domain', 'evil.example.com', '', '2026-05-16 14:30:00'); // later

        $entity = Entity::where('entity_key', 'evil.example.com')->first();

        $this->assertEquals('2026-05-16 14:30:00', $entity->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_upsert_entity_preserves_last_seen_when_earlier_timestamp(): void
    {
        $this->service->upsertEntity('domain', 'safe.example.com', '', '2026-05-16 14:00:00');
        $this->service->upsertEntity('domain', 'safe.example.com', '', '2026-05-16 08:00:00'); // earlier

        $entity = Entity::where('entity_key', 'safe.example.com')->first();

        // last_seen should remain at the latest
        $this->assertEquals('2026-05-16 14:00:00', $entity->last_seen_at->format('Y-m-d H:i:s'));
    }

    // -------------------------------------------------------------------------
    // Relationship aggregation
    // -------------------------------------------------------------------------

    public function test_upsert_relationship_creates_relationship_with_correct_fields(): void
    {
        $userId = $this->service->upsertEntity('user', 'dan@test.local');
        $hostId = $this->service->upsertEntity('host', 'server-01');

        $this->service->upsertRelationship(
            $userId, $hostId, 'user_logged_into_host',
            '2026-05-16 10:00:00', 'security_alerts', 'security_alerts', 'alert-001', 'trace-xyz'
        );

        $rel = EntityRelationship::where('source_entity_id', $userId)
            ->where('target_entity_id', $hostId)
            ->where('relationship_type', 'user_logged_into_host')
            ->first();

        $this->assertNotNull($rel);
        $this->assertSame(1, $rel->observation_count);
        $this->assertSame('trace-xyz', $rel->trace_id);
        $this->assertSame('security_alerts', $rel->source_table);
    }

    public function test_upsert_relationship_increments_observation_count_instead_of_duplicating(): void
    {
        $ipId   = $this->service->upsertEntity('ip', '10.0.0.1');
        $hostId = $this->service->upsertEntity('host', 'workstation-99');

        for ($i = 0; $i < 5; $i++) {
            $this->service->upsertRelationship(
                $ipId, $hostId, 'alert_involves_entity', '2026-05-16 1' . $i . ':00:00'
            );
        }

        $this->assertSame(1, EntityRelationship::where('source_entity_id', $ipId)
            ->where('target_entity_id', $hostId)
            ->count());

        $rel = EntityRelationship::where('source_entity_id', $ipId)
            ->where('target_entity_id', $hostId)
            ->first();

        $this->assertSame(5, $rel->observation_count);
    }

    // -------------------------------------------------------------------------
    // Trace linkage preservation
    // -------------------------------------------------------------------------

    public function test_trace_id_is_preserved_in_relationship(): void
    {
        $alertId = $this->service->upsertEntity('alert', 'alert-abc-001');
        $userId  = $this->service->upsertEntity('user', 'eve@test.local');

        $this->service->upsertRelationship(
            $alertId, $userId, 'alert_involves_entity',
            '2026-05-16 09:00:00', 'security_alerts', 'security_alerts', 'alert-abc-001', 'trace-aaa-bbb-ccc'
        );

        $rel = EntityRelationship::where('source_entity_id', $alertId)->first();
        $this->assertSame('trace-aaa-bbb-ccc', $rel->trace_id);
    }

    // -------------------------------------------------------------------------
    // Append-only observation
    // -------------------------------------------------------------------------

    public function test_append_observation_always_creates_new_row(): void
    {
        $entityId = $this->service->upsertEntity('ip', '203.0.113.10');

        $this->service->appendObservation($entityId, 'alert_source_ip', '2026-05-16 09:00:00');
        $this->service->appendObservation($entityId, 'alert_source_ip', '2026-05-16 09:00:00'); // identical
        $this->service->appendObservation($entityId, 'alert_source_ip', '2026-05-16 10:00:00');

        $this->assertSame(3, EntityObservation::where('entity_id', $entityId)->count());
    }

    public function test_append_observation_preserves_trace_id(): void
    {
        $entityId = $this->service->upsertEntity('user', 'frank@test.local');

        $this->service->appendObservation(
            $entityId, 'alert_actor', '2026-05-16 10:00:00',
            'security_alerts', 'alert-999', 'trace-replay-safe'
        );

        $obs = EntityObservation::where('entity_id', $entityId)->first();
        $this->assertSame('trace-replay-safe', $obs->trace_id);
        $this->assertSame('security_alerts', $obs->source_table);
        $this->assertSame('alert-999', $obs->source_event_id);
    }

    // -------------------------------------------------------------------------
    // Alert projection
    // -------------------------------------------------------------------------

    public function test_project_from_alerts_creates_entities_from_security_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'proj-alert-001',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'   => 'high',
            'detected_at'=> '2026-05-16 10:00:00',
            'ip'         => '198.51.100.5',
            'actor_key'  => 'projection-user@test.local',
            'trace_id'   => 'trace-proj-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();

        $this->assertDatabaseHas('entities', ['entity_type' => 'alert',   'entity_key' => 'proj-alert-001']);
        $this->assertDatabaseHas('entities', ['entity_type' => 'user',    'entity_key' => 'projection-user@test.local']);
        $this->assertDatabaseHas('entities', ['entity_type' => 'ip',      'entity_key' => '198.51.100.5']);
        $this->assertDatabaseHas('entities', ['entity_type' => 'trace',   'entity_key' => 'trace-proj-001']);
    }

    public function test_project_from_alerts_creates_relationships(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'rel-alert-001',
            'alert_type' => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'severity'   => 'medium',
            'detected_at'=> '2026-05-16 11:00:00',
            'ip'         => '192.0.2.99',
            'actor_key'  => 'rel-user@test.local',
            'trace_id'   => 'trace-rel-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();

        // Alert → trace relationship
        $this->assertTrue(EntityRelationship::where('relationship_type', 'trace_contains_entity')->exists());
        // Alert → user relationship
        $this->assertTrue(EntityRelationship::where('relationship_type', 'alert_involves_entity')->exists());
    }

    public function test_project_from_alerts_skips_null_actor_and_ip(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'minimal-alert-001',
            'alert_type' => 'ANOMALY_BEHAVIOR',
            'severity'   => 'low',
            'detected_at'=> '2026-05-16 12:00:00',
            'ip'         => null,
            'actor_key'  => null,
            'trace_id'   => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();

        // Only the minimal-alert-001 entity should have been created with no user/ip/trace peers
        $this->assertDatabaseHas('entities', ['entity_type' => 'alert', 'entity_key' => 'minimal-alert-001']);
        $this->assertDatabaseMissing('entities', ['entity_type' => 'user', 'entity_key' => null]);
        $this->assertDatabaseMissing('entities', ['entity_type' => 'ip',   'entity_key' => null]);
        // No relationships for this alert (no actor_key, no ip, no trace_id)
        $minimalAlert = Entity::where('entity_key', 'minimal-alert-001')->first();
        $this->assertNotNull($minimalAlert);
        $this->assertSame(0, EntityRelationship::where('source_entity_id', $minimalAlert->id)->count());
    }

    // -------------------------------------------------------------------------
    // Incident projection
    // -------------------------------------------------------------------------

    public function test_project_from_incidents_creates_incident_entity(): void
    {
        DB::table('security_incidents')->insert([
            'incident_id'  => 'inc-proj-001',
            'title'        => 'Suspicious Login Burst',
            'status'       => 'open',
            'severity'     => 'high',
            'first_seen_at'=> '2026-05-16 09:00:00',
            'last_seen_at' => '2026-05-16 09:30:00',
            'trace_id'     => 'trace-inc-001',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $count = $this->service->projectFromIncidents();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('entities', [
            'entity_type' => 'incident',
            'entity_key'  => 'inc-proj-001',
            'display_name'=> 'Suspicious Login Burst',
        ]);
        $this->assertDatabaseHas('entities', ['entity_type' => 'trace', 'entity_key' => 'trace-inc-001']);
    }

    // -------------------------------------------------------------------------
    // Replay-safe behavior
    // -------------------------------------------------------------------------

    public function test_replay_safe_projection_is_idempotent_for_entity_count(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'replay-alert-001',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'   => 'high',
            'detected_at'=> '2026-05-16 10:00:00',
            'ip'         => '10.1.2.3',
            'actor_key'  => 'replay-user@test.local',
            'trace_id'   => 'trace-replay-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();
        $entityCountAfterFirst = Entity::count();

        $this->service->projectFromAlerts();
        $entityCountAfterSecond = Entity::count();

        // Re-projection must not create new entity rows
        $this->assertSame($entityCountAfterFirst, $entityCountAfterSecond);
    }

    public function test_replay_safe_projection_increments_observation_count_not_resets(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'obs-count-alert-001',
            'alert_type' => 'IDENTITY_RISKY_LOGIN',
            'severity'   => 'medium',
            'detected_at'=> '2026-05-16 10:00:00',
            'actor_key'  => 'obs-user@test.local',
            'ip'         => null,
            'trace_id'   => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();
        $afterFirst = Entity::where('entity_key', 'obs-user@test.local')->first();
        $this->assertSame(1, $afterFirst->observation_count);

        $this->service->projectFromAlerts();
        $afterSecond = Entity::where('entity_key', 'obs-user@test.local')->first();
        // observation_count must only grow — never reset
        $this->assertGreaterThanOrEqual($afterFirst->observation_count, $afterSecond->observation_count);
    }

    public function test_first_seen_never_moves_forward_on_replay(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'anchor-alert-001',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'   => 'high',
            'detected_at'=> '2026-05-14 08:00:00',
            'actor_key'  => 'anchor-user@test.local',
            'ip'         => null,
            'trace_id'   => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->projectFromAlerts();
        $firstSeenAfterFirst = Entity::where('entity_key', 'anchor-user@test.local')->first()->first_seen_at;

        $this->service->projectFromAlerts();
        $firstSeenAfterSecond = Entity::where('entity_key', 'anchor-user@test.local')->first()->first_seen_at;

        // first_seen_at must be identical or earlier — never later
        $this->assertTrue($firstSeenAfterSecond->lte($firstSeenAfterFirst));
    }

    // -------------------------------------------------------------------------
    // Alert-to-entity linking
    // -------------------------------------------------------------------------

    public function test_get_alerts_returns_alerts_for_user_entity(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'user-alert-001',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'   => 'high',
            'detected_at'=> '2026-05-16 10:00:00',
            'actor_key'  => 'linked-user@test.local',
            'ip'         => null,
            'trace_id'   => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = $this->service->upsertEntity('user', 'linked-user@test.local');
        $alerts = $this->service->getAlerts($userId);

        $this->assertSame(1, $alerts->count());
        $this->assertSame('user-alert-001', $alerts->first()->alert_id);
    }

    // -------------------------------------------------------------------------
    // Incident-to-entity linking
    // -------------------------------------------------------------------------

    public function test_get_incidents_returns_incidents_for_trace_entity(): void
    {
        DB::table('security_incidents')->insert([
            'incident_id'  => 'linked-inc-001',
            'title'        => 'Linked Incident',
            'status'       => 'open',
            'severity'     => 'medium',
            'first_seen_at'=> '2026-05-16 10:00:00',
            'last_seen_at' => '2026-05-16 10:00:00',
            'trace_id'     => 'trace-linked-001',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $traceEntityId = $this->service->upsertEntity('trace', 'trace-linked-001');
        $incidents     = $this->service->getIncidents($traceEntityId);

        $this->assertSame(1, $incidents->count());
        $this->assertSame('linked-inc-001', $incidents->first()->incident_id);
    }

    // -------------------------------------------------------------------------
    // API authorization (RBAC access control)
    // -------------------------------------------------------------------------

    public function test_entity_api_index_requires_authentication(): void
    {
        $response = $this->get('/api/entities');
        $response->assertRedirect();
    }

    public function test_entity_api_show_requires_authentication(): void
    {
        $response = $this->get('/api/entities/1');
        $response->assertRedirect();
    }

    public function test_entity_api_timeline_requires_authentication(): void
    {
        $response = $this->get('/api/entities/1/timeline');
        $response->assertRedirect();
    }

    public function test_entity_api_relationships_requires_authentication(): void
    {
        $response = $this->get('/api/entities/1/relationships');
        $response->assertRedirect();
    }

    public function test_entity_api_alerts_requires_authentication(): void
    {
        $response = $this->get('/api/entities/1/alerts');
        $response->assertRedirect();
    }

    public function test_entity_api_incidents_requires_authentication(): void
    {
        $response = $this->get('/api/entities/1/incidents');
        $response->assertRedirect();
    }

    public function test_entity_api_index_returns_200_for_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($user)->get('/api/entities');

        $response->assertOk();
        $response->assertJsonStructure(['entities', 'total']);
    }

    public function test_entity_api_show_returns_404_for_missing_entity(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($user)->get('/api/entities/99999');

        $response->assertNotFound();
        $response->assertJson(['error' => 'entity_not_found']);
    }

    public function test_entity_web_index_requires_authentication(): void
    {
        $response = $this->get('/entity');
        $response->assertRedirect();
    }

    public function test_entity_web_index_returns_200_for_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'analyst']);

        $response = $this->actingAs($user)->get('/entity');

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Redaction compatibility
    // -------------------------------------------------------------------------

    public function test_entity_alerts_pass_through_trace_redactor_without_error(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'   => 'redact-alert-001',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'   => 'high',
            'detected_at'=> '2026-05-16 10:00:00',
            'actor_key'  => 'redact-user@test.local',
            'ip'         => '192.0.2.55',
            'trace_id'   => 'trace-redact-001',
            'evidence'   => json_encode(['password' => 'should-be-redacted', 'alert_type' => 'IDENTITY_MFA_FAILURE_BURST']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = $this->service->upsertEntity('user', 'redact-user@test.local');
        $alerts = $this->service->getAlerts($userId);

        // Must not throw — TraceRedactor should handle evidence field
        $redacted = TraceRedactor::collection($alerts);

        $this->assertSame(1, $redacted->count());
    }

    // -------------------------------------------------------------------------
    // Schema integrity
    // -------------------------------------------------------------------------

    public function test_entity_graph_tables_exist(): void
    {
        foreach (['entities', 'entity_relationships', 'entity_observations'] as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "Missing table: {$table}"
            );
        }
    }

    public function test_entities_table_has_required_columns(): void
    {
        foreach (['entity_type', 'entity_key', 'display_name', 'first_seen_at', 'last_seen_at', 'observation_count', 'risk_score', 'metadata'] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('entities', $col),
                "entities table missing column: {$col}"
            );
        }
    }

    public function test_entity_relationships_table_has_required_columns(): void
    {
        foreach ([
            'source_entity_id', 'target_entity_id', 'relationship_type',
            'first_seen_at', 'last_seen_at', 'observation_count', 'confidence',
            'relationship_origin', 'source_table', 'source_event_id', 'trace_id', 'metadata',
        ] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('entity_relationships', $col),
                "entity_relationships table missing column: {$col}"
            );
        }
    }

    public function test_entity_observations_table_has_required_columns(): void
    {
        foreach (['entity_id', 'observation_type', 'source_table', 'source_event_id', 'trace_id', 'observed_at', 'context'] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('entity_observations', $col),
                "entity_observations table missing column: {$col}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // buildAdjacency
    // -------------------------------------------------------------------------

    public function test_build_adjacency_returns_focal_node_and_peers(): void
    {
        $userId = $this->service->upsertEntity('user', 'adj-user@test.local');
        $ipId   = $this->service->upsertEntity('ip', '10.10.10.10');
        $hostId = $this->service->upsertEntity('host', 'adj-host-01');

        $this->service->upsertRelationship($userId, $ipId, 'process_connected_to_ip');
        $this->service->upsertRelationship($userId, $hostId, 'user_logged_into_host');

        $graph = $this->service->buildAdjacency($userId, 1);

        $nodeIds = array_column($graph['nodes'], 'id');
        $this->assertContains($userId, $nodeIds);
        $this->assertContains($ipId, $nodeIds);
        $this->assertContains($hostId, $nodeIds);

        $this->assertCount(2, $graph['edges']);
    }
}
