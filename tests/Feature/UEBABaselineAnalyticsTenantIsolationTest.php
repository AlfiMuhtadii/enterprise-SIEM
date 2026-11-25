<?php

namespace Tests\Feature;

use App\Models\BaselineAnomalyScore;
use App\Models\EndpointStreamEvent;
use App\Models\PeerGroupProfile;
use App\Models\User;
use App\Services\TenantContextAuthority;
use App\Services\UEBABaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-TENANCY-UEBA — entity_behavior_baselines/baseline_observations/
 * baseline_anomaly_scores/peer_group_profiles had no tenant_id at all.
 * Two tenants observing the same entity_key (a shared username, a shared
 * host label) would merge into one rolling baseline, and
 * deriveGroupKey()'s global keys (e.g. "user_role:admin") meant two
 * tenants' admin users shared the exact same peer group — mixing
 * behavioral baselines and leaking anomaly footprints across tenants.
 */
class UEBABaselineAnalyticsTenantIsolationTest extends TestCase
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

    private function addBaseline(string $key, string $type, string $dimension, ?string $tenantId): void
    {
        foreach ([1.0, 2.0, 3.0, 4.0, 5.0] as $value) {
            $this->svc->recordObservation($key, $type, $dimension, $value, tenantId: $tenantId);
        }
        $this->assertNotNull($this->svc->computeBaseline($key, $type, $dimension, $tenantId));
    }

    private function addEndpointObservation(string $agentId, ?string $tenantId, int $count): void
    {
        $now = now();
        $id = DB::table('endpoint_agents')->insertGetId([
            'agent_id' => $agentId, 'host_fingerprint' => $agentId, 'host_id' => 'shared-host',
            'hostname' => 'shared-host', 'tenant_id' => $tenantId,
        ]);
        $snapshotId = DB::table('endpoint_process_snapshots')->insertGetId([
            'snapshot_id' => 'snapshot-'.$agentId, 'agent_id' => $id, 'collected_at' => $now,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            DB::table('endpoint_process_entries')->insert([
                'snapshot_id' => $snapshotId, 'agent_id' => $id, 'created_at' => $now,
            ]);
            DB::table('endpoint_behavioral_findings')->insert([
                'finding_id' => "finding-{$agentId}-{$i}", 'agent_id' => $id, 'finding_type' => 'test-observation',
                'severity' => 'low', 'confidence' => 0.80, 'title' => 'Test observation',
                'detected_at' => $now, 'created_at' => $now,
            ]);
            DB::table('endpoint_stream_events')->insert([
                'event_id' => "stream-{$agentId}-{$i}", 'agent_id' => $agentId, 'host_id' => 'shared-host',
                'event_type' => EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED, 'sequence_id' => $i,
                'occurred_at' => $now, 'connection_dest' => "destination-{$agentId}-{$i}.test",
            ]);
        }
    }

    public function test_live_endpoint_dimensions_are_scoped_by_agent_ownership(): void
    {
        $this->addEndpointObservation('other', 'tenant-b', 5);
        $this->addEndpointObservation('own-first', 'tenant-a', 2);
        $this->addEndpointObservation('own-second', 'tenant-a', 1);
        $this->addEndpointObservation('legacy', null, 4);
        DB::table('endpoint_stream_events')->insert([
            'event_id' => 'unregistered-label', 'agent_id' => 'unregistered', 'host_id' => 'shared-host',
            'event_type' => EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED, 'sequence_id' => 1,
            'occurred_at' => now(), 'connection_dest' => 'unregistered.test',
        ]);

        $dimensions = ['process_execution_frequency', 'host_usage', 'network_destination_frequency'];
        foreach (['tenant-a', null] as $tenantId) {
            foreach ($dimensions as $dimension) {
                $this->addBaseline('shared-host', 'host', $dimension, $tenantId);
            }
            $this->svc->detectAnomalies('shared-host', 'host', $tenantId);
            $scores = BaselineAnomalyScore::where('entity_key', 'shared-host')->where('tenant_id', $tenantId)->get();
            $this->assertEqualsCanonicalizing($dimensions, $scores->pluck('dimension')->all());
            foreach ($scores as $score) {
                $this->assertEquals($tenantId === null ? 4 : 3, $score->observed_value);
                $this->assertTrue($score->is_advisory);
                $this->assertFalse($score->acted_on);
            }
        }
    }

    public function test_scoped_user_detection_reports_excluded_unowned_identity_and_saas_inputs(): void
    {
        $key = 'shared-user@example.test';
        $integration = DB::table('external_integrations')->insertGetId([
            'integration_id' => 'legacy-provider', 'integration_type' => 'idp',
            'provider' => 'legacy-provider', 'name' => 'Legacy',
        ]);
        DB::table('identity_provider_events')->insert([
            'event_id' => 'identity-1', 'integration_id' => $integration,
            'provider' => 'legacy-provider', 'event_type' => 'login', 'user_email' => $key, 'occurred_at' => now(),
        ]);
        DB::table('saas_audit_events')->insert([
            'event_id' => 'saas-1', 'integration_id' => $integration,
            'provider' => 'legacy-provider', 'action' => 'view_file', 'actor_email' => $key, 'occurred_at' => now(),
        ]);
        foreach (['login_frequency', 'saas_action_frequency'] as $dimension) {
            $this->addBaseline($key, 'user', $dimension, 'tenant-a');
            $this->addBaseline($key, 'user', $dimension, null);
        }

        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($user->id, 'tenant-a', $admin->id);
        $body = ['entity_key' => $key, 'entity_type' => 'user'];
        $this->actingAs($user)
            ->postJson(route('api.ueba.detect'), $body)
            ->assertForbidden();
        $this->assertDatabaseMissing('baseline_anomaly_scores', ['entity_key' => $key]);

        $this
            ->withHeader('X-Tenant-ID', 'tenant-a')
            ->postJson(route('api.ueba.detect'), $body)
            ->assertOk()
            ->assertJsonPath('current_observation_status', 'unattributed_sources_excluded')
            ->assertJsonPath('anomalies_detected', 0)
            ->assertJsonPath('autonomous_action', false);
        $this->assertDatabaseMissing('baseline_anomaly_scores', ['entity_key' => $key, 'tenant_id' => 'tenant-a']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', TenantContextAuthority::GLOBAL_SCOPE)
            ->postJson(route('api.ueba.detect'), $body)
            ->assertOk()
            ->assertJsonPath('current_observation_status', 'legacy_unscoped');
        foreach (['login_frequency', 'saas_action_frequency'] as $dimension) {
            $this->assertDatabaseHas('baseline_anomaly_scores', [
                'entity_key' => $key, 'tenant_id' => null, 'dimension' => $dimension, 'observed_value' => 1,
            ]);
        }
    }

    public function test_baseline_computation_is_isolated_per_tenant(): void
    {
        // Same entity_key in two tenants with deliberately different value
        // ranges — if merged, both baselines would share the same mean.
        foreach ([5, 5, 5, 5, 5] as $v) {
            $this->svc->recordObservation('shared-user@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }
        foreach ([50, 50, 50, 50, 50] as $v) {
            $this->svc->recordObservation('shared-user@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-b');
        }

        $baselineA = $this->svc->computeBaseline('shared-user@x.test', 'user', 'login_frequency', 'tenant-a');
        $baselineB = $this->svc->computeBaseline('shared-user@x.test', 'user', 'login_frequency', 'tenant-b');

        $this->assertSame(5.0, $baselineA->baseline_mean);
        $this->assertSame(50.0, $baselineB->baseline_mean);
        $this->assertSame(2, DB::table('entity_behavior_baselines')->where('entity_key', 'shared-user@x.test')->count());
    }

    public function test_null_tenant_observations_still_dedupe_against_each_other(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('legacy-user@x.test', 'user', 'login_frequency', (float) $v);
        }
        $b1 = $this->svc->computeBaseline('legacy-user@x.test', 'user', 'login_frequency');
        $b2 = $this->svc->computeBaseline('legacy-user@x.test', 'user', 'login_frequency');

        $this->assertSame($b1->id, $b2->id);
        $this->assertSame(1, DB::table('entity_behavior_baselines')->where('entity_key', 'legacy-user@x.test')->count());
    }

    public function test_peer_group_assignment_is_isolated_per_tenant(): void
    {
        $groupA = $this->svc->assignPeerGroup('alice@a.test', 'user', ['role' => 'admin'], 'tenant-a');
        $groupB = $this->svc->assignPeerGroup('bob@b.test', 'user', ['role' => 'admin'], 'tenant-b');

        $this->assertNotSame($groupA->id, $groupB->id);
        $this->assertSame($groupA->peer_group_key, $groupB->peer_group_key, 'group key string is intentionally global/human-readable');
        $this->assertSame('tenant-a', $groupA->tenant_id);
        $this->assertSame('tenant-b', $groupB->tenant_id);
        $this->assertNotContains('bob@b.test', $groupA->member_entity_keys);
        $this->assertNotContains('alice@a.test', $groupB->member_entity_keys);
        $this->assertSame(2, PeerGroupProfile::where('peer_group_key', $groupA->peer_group_key)->count());
    }

    public function test_compute_peer_group_profile_only_aggregates_same_tenant_members(): void
    {
        // computePeerGroupProfile only populates dimension_stats when a
        // dimension has >= 2 member values, so tenant-a needs two members.
        foreach (['alice@a.test', 'carol@a.test'] as $key) {
            foreach ([10, 10, 10] as $v) {
                $this->svc->recordObservation($key, 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
            }
            $this->svc->computeBaseline($key, 'user', 'login_frequency', 'tenant-a');
            $groupA = $this->svc->assignPeerGroup($key, 'user', ['role' => 'admin'], 'tenant-a');
        }

        foreach (['bob@b.test', 'dave@b.test'] as $key) {
            foreach ([1000, 1000, 1000] as $v) {
                $this->svc->recordObservation($key, 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-b');
            }
            $this->svc->computeBaseline($key, 'user', 'login_frequency', 'tenant-b');
            $this->svc->assignPeerGroup($key, 'user', ['role' => 'admin'], 'tenant-b');
        }

        $refreshedA = $this->svc->computePeerGroupProfile($groupA->peer_group_key, 'tenant-a');

        $stats = $refreshedA->dimension_stats['login_frequency'] ?? null;
        $this->assertNotNull($stats);
        $this->assertEquals(10.0, $stats['mean'], 'tenant-b\'s members (mean=1000) must not be aggregated into tenant-a\'s group profile');
    }

    public function test_get_anomaly_history_scoped_by_tenant(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('u@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }
        $this->svc->computeBaseline('u@x.test', 'user', 'login_frequency', 'tenant-a');
        $this->svc->scoreAnomaly('u@x.test', 'user', 'login_frequency', 999.0, [], [], null, 'tenant-a');

        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('u@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-b');
        }
        $this->svc->computeBaseline('u@x.test', 'user', 'login_frequency', 'tenant-b');
        $this->svc->scoreAnomaly('u@x.test', 'user', 'login_frequency', 999.0, [], [], null, 'tenant-b');

        $historyA = $this->svc->getAnomalyHistory('u@x.test', 'user', 100, 'tenant-a');

        $this->assertCount(1, $historyA);
        $this->assertSame('tenant-a', $historyA->first()->tenant_id);
    }

    public function test_get_anomaly_history_null_tenant_sees_all(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('u2@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }
        $this->svc->computeBaseline('u2@x.test', 'user', 'login_frequency', 'tenant-a');
        $this->svc->scoreAnomaly('u2@x.test', 'user', 'login_frequency', 999.0, [], [], null, 'tenant-a');

        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('u2@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-b');
        }
        $this->svc->computeBaseline('u2@x.test', 'user', 'login_frequency', 'tenant-b');
        $this->svc->scoreAnomaly('u2@x.test', 'user', 'login_frequency', 999.0, [], [], null, 'tenant-b');

        $historyAdmin = $this->svc->getAnomalyHistory('u2@x.test', 'user', 100, null);

        $this->assertCount(2, $historyAdmin, 'null tenantId (admin/unscoped) should see both tenants\' scores');
    }

    public function test_get_top_anomalous_entities_scoped_by_tenant(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('topuser@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }
        $this->svc->computeBaseline('topuser@x.test', 'user', 'login_frequency', 'tenant-a');
        $this->svc->scoreAnomaly('topuser@x.test', 'user', 'login_frequency', 999.0, [], [], null, 'tenant-a');

        $topA = $this->svc->getTopAnomalousEntities('user', 20, 'tenant-a');
        $topB = $this->svc->getTopAnomalousEntities('user', 20, 'tenant-b');

        $this->assertTrue($topA->contains('entity_key', 'topuser@x.test'));
        $this->assertFalse($topB->contains('entity_key', 'topuser@x.test'));
    }

    public function test_http_dashboard_stats_scoped_by_tenant(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('dashuser@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }
        $this->svc->computeBaseline('dashuser@x.test', 'user', 'login_frequency', 'tenant-a');

        $viewer = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($viewer->id, 'tenant-b', $viewer->id);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-b'])
            ->get(route('ueba.dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return $stats['total_baselines'] === 0;
        });
    }

    public function test_api_compute_baseline_persists_tenant_id_from_header(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->svc->recordObservation('apiuser@x.test', 'user', 'login_frequency', (float) $v, null, null, null, [], 'tenant-a');
        }

        $user = $this->admin();
        app(TenantContextAuthority::class)->grantMembership($user->id, 'tenant-a', $user->id);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson(route('api.ueba.compute'), [
                'entity_key' => 'apiuser@x.test',
                'entity_type' => 'user',
                'dimension' => 'login_frequency',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('entity_behavior_baselines', ['entity_key' => 'apiuser@x.test', 'tenant_id' => 'tenant-a']);
    }
}
