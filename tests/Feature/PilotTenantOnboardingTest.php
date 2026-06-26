<?php

namespace Tests\Feature;

use App\Models\PilotTenantProfile;
use App\Models\User;
use App\Services\PilotTenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-052: Real Pilot Tenant Onboarding
 *
 * Validates: onboarding flow, health checks, advisory safety,
 * max tenant limit, dry-run, persistence, routes.
 */
class PilotTenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private PilotTenantOnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PilotTenantOnboardingService::class);
    }

    // ── Safety constants ───────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(PilotTenantOnboardingService::ADVISORY_ONLY);
    }

    public function test_max_pilot_tenants_is_10(): void
    {
        $this->assertSame(10, PilotTenantOnboardingService::MAX_PILOT_TENANTS);
    }

    // ── onboard() dry-run ─────────────────────────────────────────────────────

    public function test_dry_run_returns_ok_true(): void
    {
        $result = $this->service->onboard(['tenant_name' => 'Test Corp'], true);
        $this->assertTrue($result['ok']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->onboard(['tenant_name' => 'Test Corp'], true);
        $this->assertDatabaseCount('pilot_tenant_profiles', 0);
        $this->assertDatabaseCount('pilot_tenant_onboarding_events', 0);
    }

    public function test_dry_run_result_has_tenant_id(): void
    {
        $result = $this->service->onboard(['tenant_name' => 'Acme'], true);
        $this->assertNotEmpty($result['tenant_id']);
    }

    public function test_dry_run_result_is_advisory_true(): void
    {
        $result = $this->service->onboard([], true);
        $this->assertTrue($result['is_advisory']);
    }

    public function test_dry_run_with_explicit_tenant_id(): void
    {
        $result = $this->service->onboard(['tenant_id' => 'pilot-acme', 'tenant_name' => 'Acme Corp'], true);
        $this->assertSame('pilot-acme', $result['tenant_id']);
    }

    public function test_dry_run_pilot_type(): void
    {
        $result = $this->service->onboard(['tenant_type' => 'pilot'], true);
        $this->assertSame('pilot', $result['profile']['tenant_type']);
    }

    public function test_dry_run_demo_type(): void
    {
        $result = $this->service->onboard(['tenant_type' => 'demo'], true);
        $this->assertSame('demo', $result['profile']['tenant_type']);
    }

    public function test_invalid_type_defaults_to_pilot(): void
    {
        $result = $this->service->onboard(['tenant_type' => 'invalid'], true);
        $this->assertSame('pilot', $result['profile']['tenant_type']);
    }

    // ── onboard() with persistence ────────────────────────────────────────────

    public function test_persist_creates_profile_row(): void
    {
        $this->service->onboard(['tenant_id' => 'pilot-test', 'tenant_name' => 'Test'], false);
        $this->assertDatabaseCount('pilot_tenant_profiles', 1);
        $this->assertDatabaseHas('pilot_tenant_profiles', ['tenant_id' => 'pilot-test']);
    }

    public function test_persist_creates_onboarding_events(): void
    {
        $this->service->onboard(['tenant_id' => 'pilot-test'], false);
        $count = DB::table('pilot_tenant_onboarding_events')->count();
        $this->assertGreaterThan(0, $count);
    }

    public function test_persist_upsert_does_not_duplicate(): void
    {
        $this->service->onboard(['tenant_id' => 'pilot-test'], false);
        $this->service->onboard(['tenant_id' => 'pilot-test'], false);
        $this->assertDatabaseCount('pilot_tenant_profiles', 1);
    }

    public function test_max_tenant_limit_blocks_new_onboarding(): void
    {
        for ($i = 0; $i < PilotTenantOnboardingService::MAX_PILOT_TENANTS; $i++) {
            $this->service->onboard(['tenant_id' => "pilot-{$i}"], false);
        }
        $result = $this->service->onboard(['tenant_id' => 'pilot-overflow'], false);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('MAX_PILOT_TENANTS', $result['error']);
    }

    // ── validateTenantHealth ──────────────────────────────────────────────────

    public function test_health_check_profile_not_found(): void
    {
        $health = $this->service->validateTenantHealth('nonexistent-tenant');
        $this->assertFalse($health['healthy']);
        $this->assertFalse($health['checks']['profile_exists']);
    }

    public function test_health_check_after_onboard(): void
    {
        $this->service->onboard(['tenant_id' => 'pilot-abc'], false);
        $health = $this->service->validateTenantHealth('pilot-abc');
        $this->assertTrue($health['checks']['profile_exists']);
        $this->assertTrue($health['is_advisory']);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/pilot-tenants')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_pilot_tenant_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/pilot-tenants')
            ->assertStatus(200)
            ->assertSeeText('Pilot Tenant');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/pilot-tenants')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true);
    }

    public function test_tenant_detail_route_works(): void
    {
        $this->service->onboard(['tenant_id' => 'pilot-view-test'], false);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/pilot-tenants/pilot-view-test')
            ->assertStatus(200);
    }
}
