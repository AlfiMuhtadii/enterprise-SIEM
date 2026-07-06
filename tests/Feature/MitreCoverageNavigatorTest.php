<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAP-MITRE-COVERAGE-NAV: MITRE ATT&CK Navigator layer.json export, built
 * from the existing rule-registry coverage map. Read-only — no writes, no
 * autonomous action.
 */
class MitreCoverageNavigatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigator_layer_has_required_top_level_fields(): void
    {
        $service = app(RuleRegistryService::class);
        $coverage = [
            ['technique' => 'T1059.001', 'rules' => ['rule_a'], 'domains' => ['endpoint']],
        ];
        $layer = $service->navigatorLayer($coverage);

        foreach (['name', 'versions', 'domain', 'description', 'techniques', 'gradient'] as $field) {
            $this->assertArrayHasKey($field, $layer);
        }
        $this->assertSame('4.5', $layer['versions']['layer']);
        $this->assertSame('enterprise-attack', $layer['domain']);
    }

    public function test_navigator_layer_scores_parent_and_subtechnique_independently(): void
    {
        $service = app(RuleRegistryService::class);
        $coverage = [
            ['technique' => 'T1078', 'rules' => ['rule_a', 'rule_b'], 'domains' => ['identity']],
            ['technique' => 'T1078.004', 'rules' => ['rule_a'], 'domains' => ['identity']],
        ];
        $layer = $service->navigatorLayer($coverage);

        $byId = collect($layer['techniques'])->keyBy('techniqueID');
        $this->assertSame(2, $byId['T1078']['score']);
        $this->assertSame(1, $byId['T1078.004']['score']);
    }

    public function test_navigator_layer_includes_domain_metadata_per_technique(): void
    {
        $service = app(RuleRegistryService::class);
        $coverage = [
            ['technique' => 'T1059.001', 'rules' => ['rule_a'], 'domains' => ['endpoint', 'network']],
        ];
        $layer = $service->navigatorLayer($coverage);

        $tech = $layer['techniques'][0];
        $domainsMeta = collect($tech['metadata'])->firstWhere('name', 'domains');
        $this->assertStringContainsString('endpoint', $domainsMeta['value']);
        $this->assertStringContainsString('network', $domainsMeta['value']);
    }

    public function test_navigator_layer_handles_empty_coverage(): void
    {
        $service = app(RuleRegistryService::class);
        $layer = $service->navigatorLayer([]);

        $this->assertSame([], $layer['techniques']);
        $this->assertSame(1, $layer['gradient']['maxValue']); // never zero — avoids a degenerate gradient
    }

    public function test_navigator_layer_matches_real_registry_coverage(): void
    {
        // End-to-end against the real 133-rule registry, not a synthetic fixture.
        $service = app(RuleRegistryService::class);
        $mitre = $service->mitreCoverageMap($service->allRules());
        $layer = $service->navigatorLayer($mitre);

        $this->assertGreaterThan(0, count($layer['techniques']));
        $this->assertGreaterThan(0, count(array_filter($layer['techniques'], fn ($t) => str_contains($t['techniqueID'], '.'))), 'expected at least one sub-technique in the real registry coverage');
    }

    public function test_download_route_requires_auth(): void
    {
        $this->get(route('detection.lifecycle.attack-map.navigator-layer'))
            ->assertRedirect(route('login'));
    }

    public function test_download_route_forbidden_without_rules_govern_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->get(route('detection.lifecycle.attack-map.navigator-layer'))
            ->assertForbidden();
    }

    public function test_download_route_returns_valid_json_attachment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('detection.lifecycle.attack-map.navigator-layer'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('navigator-layer.json', $response->headers->get('Content-Disposition'));

        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('techniques', $decoded);
        $this->assertGreaterThan(0, count($decoded['techniques']));
    }

    public function test_attack_map_view_renders_with_download_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('detection.lifecycle.attack-map'));

        $response->assertOk();
        $response->assertSee(route('detection.lifecycle.attack-map.navigator-layer'), false);
    }
}
