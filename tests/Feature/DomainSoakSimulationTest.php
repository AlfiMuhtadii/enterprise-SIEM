<?php

namespace Tests\Feature;

use App\Models\DomainSoakSimulation;
use App\Models\User;
use App\Services\DomainSoakSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-057: Domain Soak Simulation
 *
 * Validates: safety constants, simulate() per-domain, simulateAll(), persistence,
 * gate structure, getSimulations, route access, JSON API.
 */
class DomainSoakSimulationTest extends TestCase
{
    use RefreshDatabase;

    private DomainSoakSimulationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DomainSoakSimulationService::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_promotion_recommended_constant_is_false(): void
    {
        $this->assertFalse(DomainSoakSimulationService::PROMOTION_RECOMMENDED);
    }

    public function test_real_soak_required_constant_is_true(): void
    {
        $this->assertTrue(DomainSoakSimulationService::REAL_SOAK_REQUIRED);
    }

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(DomainSoakSimulationService::ADVISORY_ONLY);
    }

    public function test_structural_pass_rate_is_0_80(): void
    {
        $this->assertEqualsWithDelta(0.80, DomainSoakSimulationService::STRUCTURAL_PASS_RATE, 0.001);
    }

    public function test_supported_domains_includes_endpoint(): void
    {
        $this->assertContains('endpoint', DomainSoakSimulationService::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_includes_network(): void
    {
        $this->assertContains('network', DomainSoakSimulationService::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_includes_threat_intel(): void
    {
        $this->assertContains('threat-intel', DomainSoakSimulationService::SUPPORTED_DOMAINS);
    }

    // ── simulate() dry-run ────────────────────────────────────────────────────

    public function test_simulate_endpoint_dry_run_returns_simulation(): void
    {
        $result = $this->service->simulate('endpoint', true);
        $this->assertArrayHasKey('simulation', $result);
        $this->assertArrayHasKey('gates', $result);
    }

    public function test_simulate_sets_promotion_recommended_false(): void
    {
        $result = $this->service->simulate('endpoint', true);
        $this->assertFalse($result['simulation']['promotion_recommended']);
    }

    public function test_simulate_sets_real_soak_required_true(): void
    {
        $result = $this->service->simulate('endpoint', true);
        $this->assertTrue($result['simulation']['real_soak_required']);
    }

    public function test_simulate_sets_soak_verdict_simulation_only(): void
    {
        $result = $this->service->simulate('endpoint', true);
        $this->assertSame('SIMULATION_ONLY', $result['simulation']['soak_verdict']);
    }

    public function test_simulate_returns_4_gates(): void
    {
        $result = $this->service->simulate('endpoint', true);
        $this->assertCount(4, $result['gates']);
    }

    public function test_simulate_network_returns_4_gates(): void
    {
        $result = $this->service->simulate('network', true);
        $this->assertCount(4, $result['gates']);
    }

    public function test_simulate_threat_intel_returns_4_gates(): void
    {
        $result = $this->service->simulate('threat-intel', true);
        $this->assertCount(4, $result['gates']);
    }

    public function test_simulate_unsupported_domain_returns_error(): void
    {
        $result = $this->service->simulate('identity', true);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->simulate('endpoint', true);
        $this->assertDatabaseCount('domain_soak_simulations', 0);
    }

    // ── persistence ───────────────────────────────────────────────────────────

    public function test_simulate_persists_simulation_row(): void
    {
        $this->service->simulate('endpoint', false);
        $this->assertDatabaseCount('domain_soak_simulations', 1);
    }

    public function test_simulate_persists_4_gate_rows(): void
    {
        $this->service->simulate('endpoint', false);
        $this->assertDatabaseCount('domain_soak_simulation_gates', 4);
    }

    public function test_simulate_sets_domain_correctly(): void
    {
        $this->service->simulate('network', false);
        $this->assertDatabaseHas('domain_soak_simulations', ['domain' => 'network']);
    }

    public function test_simulate_sets_promotion_recommended_false_in_db(): void
    {
        $this->service->simulate('endpoint', false);
        $this->assertDatabaseHas('domain_soak_simulations', ['promotion_recommended' => false]);
    }

    // ── simulateAll ───────────────────────────────────────────────────────────

    public function test_simulate_all_dry_run_returns_3_domains(): void
    {
        $result = $this->service->simulateAll(true);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('endpoint', $result);
        $this->assertArrayHasKey('network', $result);
        $this->assertArrayHasKey('threat-intel', $result);
    }

    public function test_simulate_all_persists_3_simulation_rows(): void
    {
        $this->service->simulateAll(false);
        $this->assertDatabaseCount('domain_soak_simulations', 3);
    }

    // ── getSimulations ────────────────────────────────────────────────────────

    public function test_get_simulations_returns_collection(): void
    {
        $sims = $this->service->getSimulations();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $sims);
    }

    public function test_get_simulations_filtered_by_domain(): void
    {
        $this->service->simulateAll(false);
        $endpointSims = $this->service->getSimulations('endpoint');
        $this->assertCount(1, $endpointSims);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/domain-soak-simulations');
        $response->assertRedirect();
    }

    public function test_route_accessible_to_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/domain-soak-simulations');
        $response->assertStatus(200);
    }

    public function test_json_api_returns_promotion_recommended_false(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/domain-soak-simulations');
        $response->assertStatus(200);
        $response->assertJsonPath('promotion_recommended', false);
        $response->assertJsonPath('real_soak_required', true);
        $response->assertJsonPath('advisory_only', true);
    }
}
