<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TENANT-HUNT-CORRELATION-ISOLATION: soc_hunt_sessions (saved "quick
 * hunts") and soc_hunt_run_sessions (each run's results) had no tenant_id
 * at all -- SocHuntController::index() rendered savedHunts/huntRuns
 * globally, leaking hunt histories across tenants. queryTelemetry()'s
 * correlated-alerts lookup (DB::table('security_alerts')->get()) was also
 * unscoped, leaking another tenant's alerts on a matched host into
 * correlated_alerts. Also closes the same 2 unscoped dashboard counts in
 * SocDashboardController's investigationSummary.
 */
class TenantHuntCorrelationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hunt_tables_have_a_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('soc_hunt_sessions', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('soc_hunt_run_sessions', 'tenant_id'));
    }

    public function test_hunt_tables_are_registered_isolated_and_append_only(): void
    {
        $this->assertContains('soc_hunt_sessions', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('soc_hunt_sessions', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
        $this->assertContains('soc_hunt_run_sessions', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('soc_hunt_run_sessions', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
    }

    private function actingAsTenant(string $tenantId): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        app(TenantContextAuthority::class)->grantMembership($user->id, $tenantId, $user->id);

        return $user;
    }

    public function test_save_stores_tenant_id_from_the_requesting_analyst(): void
    {
        $user = $this->actingAsTenant('tenant-a');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post('/soc/hunts/save', ['name' => 'my saved hunt'])
            ->assertRedirect();

        $this->assertDatabaseHas('soc_hunt_sessions', [
            'name' => 'my saved hunt',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_run_stores_tenant_id_from_the_requesting_analyst(): void
    {
        $user = $this->actingAsTenant('tenant-a');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get('/soc/hunts?run=1')
            ->assertOk();

        $this->assertDatabaseHas('soc_hunt_run_sessions', ['tenant_id' => 'tenant-a']);
    }

    public function test_saved_hunts_panel_shows_own_tenant_and_legacy_but_not_other_tenant(): void
    {
        DB::table('soc_hunt_sessions')->insert([
            'hunt_id' => 'hunt-own', 'tenant_id' => 'tenant-b', 'name' => 'own_tenant_hunt_marker',
            'created_by' => 'a@tenant-b.test', 'filters' => json_encode([]), 'saved' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('soc_hunt_sessions')->insert([
            'hunt_id' => 'hunt-legacy', 'tenant_id' => null, 'name' => 'legacy_hunt_marker',
            'created_by' => 'legacy@test', 'filters' => json_encode([]), 'saved' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('soc_hunt_sessions')->insert([
            'hunt_id' => 'hunt-other', 'tenant_id' => 'tenant-c', 'name' => 'other_tenant_hunt_marker',
            'created_by' => 'a@tenant-c.test', 'filters' => json_encode([]), 'saved' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsTenant('tenant-b');

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-b'])
            ->get('/soc/hunts');

        $response->assertOk();
        $response->assertSee('own_tenant_hunt_marker');
        $response->assertSee('legacy_hunt_marker');
        $response->assertDontSee('other_tenant_hunt_marker');
    }

    public function test_correlated_alerts_only_include_own_tenants_alerts(): void
    {
        $host = 'host-hunt-correlation-test';
        DB::table('telemetry_events')->insert([
            'ts' => now(), 'tenant_id' => 'tenant-d', 'event_id' => 'evt-hunt-corr-1',
            'telemetry_type' => 'endpoint', 'event_type' => 'process_observed', 'host_id' => $host,
            'payload' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-corr-own', 'detected_at' => now(), 'alert_type' => 'OWN_TENANT_CORR_TYPE',
            'detector_name' => 'TEST', 'detector_version' => 'v1', 'severity' => 'high',
            'tenant_id' => 'tenant-d', 'actor_key' => $host, 'score' => 0.9,
            'evidence' => json_encode([]), 'raw_event' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-corr-other', 'detected_at' => now(), 'alert_type' => 'OTHER_TENANT_CORR_TYPE',
            'detector_name' => 'TEST', 'detector_version' => 'v1', 'severity' => 'high',
            'tenant_id' => 'tenant-e', 'actor_key' => $host, 'score' => 0.9,
            'evidence' => json_encode([]), 'raw_event' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsTenant('tenant-d');

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-d'])
            ->get('/soc/hunts?run=1&host_id='.$host);

        // The "Correlated Alerts" badge renders alert_type:severity, not the
        // alert_id -- distinct alert_type values let us tell which alert(s)
        // actually reached the rendered page.
        $response->assertOk();
        $response->assertSee('OWN_TENANT_CORR_TYPE');
        $response->assertDontSee('OTHER_TENANT_CORR_TYPE');
    }

    public function test_dashboard_hunt_counts_are_scoped_to_own_tenant_and_legacy(): void
    {
        DB::table('soc_hunt_run_sessions')->insert([
            'run_id' => 'run-own', 'tenant_id' => 'tenant-f', 'executed_by' => 'a@tenant-f.test',
            'started_at' => now(), 'filters' => json_encode([]), 'result_count' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('soc_hunt_run_sessions')->insert([
            'run_id' => 'run-other', 'tenant_id' => 'tenant-g', 'executed_by' => 'a@tenant-g.test',
            'started_at' => now(), 'filters' => json_encode([]), 'result_count' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $scopedCount = DB::table('soc_hunt_run_sessions')
            ->where('started_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('tenant_id', 'tenant-f')->orWhereNull('tenant_id'))
            ->count();
        $this->assertSame(1, $scopedCount);

        $scopedMatches = (int) DB::table('soc_hunt_run_sessions')
            ->where('started_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('tenant_id', 'tenant-f')->orWhereNull('tenant_id'))
            ->sum('result_count');
        $this->assertSame(3, $scopedMatches);
    }
}
