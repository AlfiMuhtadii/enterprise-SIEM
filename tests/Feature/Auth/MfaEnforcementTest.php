<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * IDENTITY-SSO-MFA: mandatory MFA enforcement (EnsureMfaVerified / the
 * "mfa.required" middleware alias) on high-consequence approval routes
 * (response-plan/active-response/erasure approve). Exercised against a
 * dedicated test-only route registered with the exact same middleware
 * stack the real routes use, rather than each real controller's full
 * domain-model fixture setup — the mechanism under test is the middleware
 * itself, not the downstream controller logic (already covered by
 * ResponsePlanningTest et al.).
 */
class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function registerTestRoute(): void
    {
        Route::middleware(['web', 'auth', 'mfa.required'])
            ->post('/__test/mfa-required-action', fn () => response('ok', 200));
    }

    public function test_disabled_by_default_allows_action_without_mfa(): void
    {
        $this->assertFalse(config('soc.mfa_enforcement_enabled'), 'must default to false');
        $this->registerTestRoute();
        $user = User::factory()->create(['mfa_enabled' => false]);

        $response = $this->actingAs($user)->post('/__test/mfa-required-action');

        $response->assertOk();
    }

    public function test_enabled_blocks_user_without_mfa(): void
    {
        config(['soc.mfa_enforcement_enabled' => true]);
        $this->registerTestRoute();
        $user = User::factory()->create(['mfa_enabled' => false]);

        $response = $this->actingAs($user)->post('/__test/mfa-required-action');

        $response->assertForbidden();
    }

    public function test_enabled_allows_user_with_mfa_enabled(): void
    {
        config(['soc.mfa_enforcement_enabled' => true]);
        $this->registerTestRoute();
        $user = User::factory()->create(['mfa_enabled' => true, 'mfa_secret' => 'ABCDEFGHIJKLMNOP']);

        $response = $this->actingAs($user)->post('/__test/mfa-required-action');

        $response->assertOk();
    }

    public function test_enabled_blocked_attempt_is_audit_logged(): void
    {
        config(['soc.mfa_enforcement_enabled' => true]);
        $this->registerTestRoute();
        $user = User::factory()->create(['mfa_enabled' => false, 'role' => 'admin']);

        $this->actingAs($user)->post('/__test/mfa-required-action');

        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $user->email,
            'action' => 'mfa.enforcement_blocked',
            'target_id' => '__test/mfa-required-action',
        ]);
    }

    public function test_response_plan_approve_route_carries_mfa_middleware(): void
    {
        $route = Route::getRoutes()->getByName('response.approve');
        $this->assertNotNull($route);
        $this->assertContains('mfa.required', $route->gatherMiddleware());
    }

    public function test_active_response_approve_route_carries_mfa_middleware(): void
    {
        $route = Route::getRoutes()->getByName('active-response.approve');
        $this->assertNotNull($route);
        $this->assertContains('mfa.required', $route->gatherMiddleware());
    }

    public function test_erasure_approve_route_carries_mfa_middleware(): void
    {
        $route = Route::getRoutes()->getByName('data-residency.erasure.approve');
        $this->assertNotNull($route);
        $this->assertContains('mfa.required', $route->gatherMiddleware());
    }

    public function test_erasure_policy_update_route_does_not_require_mfa(): void
    {
        // Sanity check that MFA scope stayed on the approve/reject actions
        // only — not the whole soc:retention.manage group (a routine policy
        // config update is not the same risk class as approving deletion).
        $route = Route::getRoutes()->getByName('data-residency.policy.update');
        $this->assertNotNull($route);
        $this->assertNotContains('mfa.required', $route->gatherMiddleware());
    }
}
