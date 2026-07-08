<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\User;
use App\Services\EntityGraphService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-TENANCY-ENTITY-GRAPH — EntityGraphService::upsertEntity()/
 * upsertRelationship() previously looked up/created rows by
 * (entity_type, entity_key) only, with no tenant_id at all: two tenants
 * observing the same entity_key (a shared IP, a common email) merged into
 * ONE row, leaking relationship topology between tenants.
 *
 * Bounded scope: only projectFromAlerts()/projectFromIncidents() (backed
 * by security_alerts/security_incidents, both already tenant-tagged) are
 * threaded with tenant_id here. The 5 projectStream*()/
 * projectResponseAcknowledgement() methods are unchanged — their source
 * telemetry (endpoint_stream_events, response_executions) has no
 * tenant_id column yet, a separate, already-filed backlog concern.
 */
class EntityGraphTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private EntityGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityGraphService();
    }

    public function test_same_key_in_two_tenants_creates_two_separate_entities(): void
    {
        $idA = $this->service->upsertEntity('ip', '203.0.113.5', '203.0.113.5', null, null, [], 'tenant-a');
        $idB = $this->service->upsertEntity('ip', '203.0.113.5', '203.0.113.5', null, null, [], 'tenant-b');

        $this->assertNotSame($idA, $idB);
        $this->assertSame(2, DB::table('entities')->where('entity_key', '203.0.113.5')->count());
        $this->assertSame('tenant-a', Entity::find($idA)->tenant_id);
        $this->assertSame('tenant-b', Entity::find($idB)->tenant_id);
    }

    public function test_repeat_upsert_within_same_tenant_still_dedupes(): void
    {
        $id1 = $this->service->upsertEntity('ip', '203.0.113.6', '', null, null, [], 'tenant-a');
        $id2 = $this->service->upsertEntity('ip', '203.0.113.6', '', null, null, [], 'tenant-a');

        $this->assertSame($id1, $id2);
        $this->assertSame(1, DB::table('entities')->where('entity_key', '203.0.113.6')->count());
        $this->assertSame(2, DB::table('entities')->where('id', $id1)->value('observation_count'));
    }

    public function test_null_tenant_entities_still_dedupe_against_each_other(): void
    {
        // Legacy/unscoped callers (tenant_id omitted) must keep the exact
        // pre-fix dedup behavior — COALESCE(tenant_id, '_none') keeps all
        // null-tenant rows in one shared bucket.
        $id1 = $this->service->upsertEntity('ip', '203.0.113.7', '', null, null, []);
        $id2 = $this->service->upsertEntity('ip', '203.0.113.7', '', null, null, []);

        $this->assertSame($id1, $id2);
        $this->assertSame(1, DB::table('entities')->where('entity_key', '203.0.113.7')->count());
    }

    public function test_null_tenant_entity_is_distinct_from_a_tenant_scoped_one(): void
    {
        $legacyId = $this->service->upsertEntity('ip', '203.0.113.8', '', null, null, []);
        $tenantId = $this->service->upsertEntity('ip', '203.0.113.8', '', null, null, [], 'tenant-a');

        $this->assertNotSame($legacyId, $tenantId);
        $this->assertSame(2, DB::table('entities')->where('entity_key', '203.0.113.8')->count());
    }

    public function test_relationship_between_same_pair_is_separate_per_tenant(): void
    {
        $sourceA = $this->service->upsertEntity('user', 'shared-user', '', null, null, [], 'tenant-a');
        $targetA = $this->service->upsertEntity('ip', 'shared-ip', '', null, null, [], 'tenant-a');
        $sourceB = $this->service->upsertEntity('user', 'shared-user', '', null, null, [], 'tenant-b');
        $targetB = $this->service->upsertEntity('ip', 'shared-ip', '', null, null, [], 'tenant-b');

        $this->service->upsertRelationship($sourceA, $targetA, 'alert_involves_entity', null, null, null, null, null, 1.0, 'tenant-a');
        $this->service->upsertRelationship($sourceB, $targetB, 'alert_involves_entity', null, null, null, null, null, 1.0, 'tenant-b');

        $this->assertSame(2, DB::table('entity_relationships')->where('relationship_type', 'alert_involves_entity')->count());
        $relA = EntityRelationship::where('source_entity_id', $sourceA)->first();
        $relB = EntityRelationship::where('source_entity_id', $sourceB)->first();
        $this->assertSame('tenant-a', $relA->tenant_id);
        $this->assertSame('tenant-b', $relB->tenant_id);
    }

    public function test_project_from_alerts_derives_tenant_id_per_row(): void
    {
        DB::table('security_alerts')->insert([
            ['alert_id' => 'ga1', 'alert_type' => 'X', 'severity' => 'high', 'detected_at' => now(), 'actor_key' => 'shared@x.test', 'tenant_id' => 'tenant-a', 'created_at' => now(), 'updated_at' => now()],
            ['alert_id' => 'ga2', 'alert_type' => 'X', 'severity' => 'high', 'detected_at' => now(), 'actor_key' => 'shared@x.test', 'tenant_id' => 'tenant-b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service->projectFromAlerts();

        $userEntities = DB::table('entities')->where('entity_type', 'user')->where('entity_key', 'shared@x.test')->get();
        $this->assertCount(2, $userEntities);
        $this->assertEqualsCanonicalizing(['tenant-a', 'tenant-b'], $userEntities->pluck('tenant_id')->all());
    }

    public function test_http_show_returns_404_for_entity_owned_by_other_tenant(): void
    {
        $id = $this->service->upsertEntity('ip', '203.0.113.30', '', null, null, [], 'tenant-b');

        $analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'tenant-a', $analyst->id);

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson("/api/entities/{$id}");

        $response->assertStatus(404);
    }

    public function test_http_show_returns_200_for_entity_owned_by_requesting_tenant(): void
    {
        $id = $this->service->upsertEntity('ip', '203.0.113.31', '', null, null, [], 'tenant-a');

        $analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'tenant-a', $analyst->id);

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson("/api/entities/{$id}");

        $response->assertOk();
    }
}
