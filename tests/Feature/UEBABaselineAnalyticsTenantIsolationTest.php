<?php

namespace Tests\Feature;

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
 *
 * Bounded scope: collectCurrentObservations() (used internally by
 * detectAnomalies()) is deliberately NOT tenant-scoped — its source
 * tables (identity_provider_events, saas_audit_events, endpoint_agents,
 * etc.) have no tenant_id column at all, a documented residual gap.
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
