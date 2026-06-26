<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DetectionPromotionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERPRISE-045: Detection Domain Promotion Readiness
 *
 * Validates: 5-category classification logic, summary counts, ADVISORY_ONLY
 * and NO_AUTONOMOUS_PROMOTION constants, SOC view access, JSON API.
 */
class DetectionPromotionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private DetectionPromotionReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DetectionPromotionReadinessService::class);
    }

    // ── Safety constants ─────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(DetectionPromotionReadinessService::ADVISORY_ONLY);
    }

    public function test_no_autonomous_promotion_constant_is_true(): void
    {
        $this->assertTrue(DetectionPromotionReadinessService::NO_AUTONOMOUS_PROMOTION);
    }

    // ── Classification logic ──────────────────────────────────────────────────

    public function test_staged_active_rule_classifies_as_active(): void
    {
        $rule = ['rule_id' => 'IDENTITY_MFA_FAILURE_BURST', 'status' => 'staged_active', 'domain' => 'identity'];
        $this->assertSame('active', $this->service->classifyRule($rule));
    }

    public function test_shadow_identity_classifies_as_shadow_ready(): void
    {
        $rule = ['rule_id' => 'CRED_MFA_FATIGUE_PATTERN', 'status' => 'shadow', 'domain' => 'identity'];
        $this->assertSame('shadow_ready', $this->service->classifyRule($rule));
    }

    public function test_shadow_cloud_classifies_as_shadow_ready(): void
    {
        $rule = ['rule_id' => 'CLOUD_OAUTH_ABUSE_PROGRESSION', 'status' => 'shadow', 'domain' => 'cloud'];
        $this->assertSame('shadow_ready', $this->service->classifyRule($rule));
    }

    public function test_shadow_saas_classifies_as_shadow_ready(): void
    {
        $rule = ['rule_id' => 'SAAS_IMPOSSIBLE_ACTIVITY_CHAIN', 'status' => 'shadow', 'domain' => 'saas'];
        $this->assertSame('shadow_ready', $this->service->classifyRule($rule));
    }

    public function test_shadow_endpoint_classifies_as_shadow_needs_soak(): void
    {
        $rule = ['rule_id' => 'suspicious_parent_child_process', 'status' => 'shadow', 'domain' => 'endpoint'];
        $this->assertSame('shadow_needs_soak', $this->service->classifyRule($rule));
    }

    public function test_shadow_network_classifies_as_deferred(): void
    {
        $rule = ['rule_id' => 'suspicious_dns_tld', 'status' => 'shadow', 'domain' => 'network'];
        $this->assertSame('deferred', $this->service->classifyRule($rule));
    }

    public function test_shadow_threat_intel_classifies_as_deferred(): void
    {
        $rule = ['rule_id' => 'ioc_ip_match', 'status' => 'shadow', 'domain' => 'threat-intel'];
        $this->assertSame('deferred', $this->service->classifyRule($rule));
    }

    public function test_shadow_xdr_classifies_as_deferred(): void
    {
        $rule = ['rule_id' => 'UEBA_PEER_GROUP_BEHAVIOR_DEVIATION', 'status' => 'shadow', 'domain' => 'xdr'];
        $this->assertSame('deferred', $this->service->classifyRule($rule));
    }

    public function test_classify_is_deterministic(): void
    {
        $rule = ['rule_id' => 'EVASION_LOG_TAMPERING', 'status' => 'shadow', 'domain' => 'endpoint'];
        $this->assertSame(
            $this->service->classifyRule($rule),
            $this->service->classifyRule($rule)
        );
    }

    // ── classifyAll() ─────────────────────────────────────────────────────────

    public function test_classify_all_returns_133_rules(): void
    {
        $all = $this->service->classifyAll();
        $this->assertCount(133, $all);
    }

    public function test_classify_all_every_rule_has_readiness_field(): void
    {
        $this->service->classifyAll()->each(function (array $rule): void {
            $this->assertArrayHasKey('readiness', $rule, "Rule {$rule['rule_id']} missing readiness");
            $this->assertContains($rule['readiness'], [
                'active', 'shadow_ready', 'shadow_needs_soak', 'deferred', 'out_of_scope',
            ]);
        });
    }

    // ── getSummary() ──────────────────────────────────────────────────────────

    public function test_summary_total_is_133(): void
    {
        $this->assertSame(133, $this->service->getSummary()['total']);
    }

    public function test_summary_active_is_12(): void
    {
        $this->assertSame(12, $this->service->getSummary()['active']);
    }

    public function test_summary_shadow_ready_is_12(): void
    {
        $this->assertSame(12, $this->service->getSummary()['shadow_ready']);
    }

    public function test_summary_shadow_needs_soak_is_93(): void
    {
        $this->assertSame(93, $this->service->getSummary()['shadow_needs_soak']);
    }

    public function test_summary_deferred_is_16(): void
    {
        $this->assertSame(16, $this->service->getSummary()['deferred']);
    }

    public function test_summary_out_of_scope_is_0(): void
    {
        $this->assertSame(0, $this->service->getSummary()['out_of_scope']);
    }

    public function test_summary_counts_sum_to_total(): void
    {
        $s = $this->service->getSummary();
        $sum = $s['active'] + $s['shadow_ready'] + $s['shadow_needs_soak'] + $s['deferred'] + $s['out_of_scope'];
        $this->assertSame($s['total'], $sum);
    }

    // ── SOC view / route ──────────────────────────────────────────────────────

    public function test_promotion_readiness_route_requires_auth(): void
    {
        $this->get('/detection/promotion-readiness')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_promotion_readiness_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/promotion-readiness')
            ->assertStatus(200)
            ->assertSeeText('Detection Domain Promotion Readiness');
    }

    public function test_promotion_readiness_view_shows_summary_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/promotion-readiness')
            ->assertStatus(200)
            ->assertSeeText('93')   // shadow_needs_soak count
            ->assertSeeText('16');  // deferred count
    }

    public function test_promotion_readiness_json_api_returns_all_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/promotion-readiness');

        $response->assertStatus(200)
            ->assertJsonPath('advisory', true)
            ->assertJsonPath('no_promotion', true)
            ->assertJsonCount(133, 'rules');
    }
}
