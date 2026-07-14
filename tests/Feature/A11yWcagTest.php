<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A11Y-WCAG: only 1 of 459 Blade views used any aria-*, zero <img alt>, no
 * modal/menu focus management, tables lack scope/caption. Full WCAG 2.1 AA
 * conformance across 459 views is separate, larger scope than a single
 * pass -- this bounded first phase fixes the shared layout/component files
 * (layouts.app, layouts.guest, layouts.navigation, application-logo,
 * nav-link, input-error), which every page inherits, plus a full
 * proof-of-pattern on the login page's form-error linking. See
 * docs/accessibility/A11Y_ROADMAP.md for the remaining per-view scope.
 */
class A11yWcagTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_skip_link_and_main_landmark(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Skip to main content');
        $response->assertSee('id="main-content"', false);
    }

    public function test_login_page_marks_invalid_field_with_aria_invalid_and_describedby(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'not-a-real-user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $follow = $this->get('/login');

        $follow->assertSee('aria-invalid="true"', false);
        $follow->assertSee('aria-describedby="email-error"', false);
        $follow->assertSee('id="email-error"', false);
        $follow->assertSee('role="alert"', false);
    }

    public function test_login_page_omits_aria_invalid_when_no_errors(): void
    {
        $response = $this->get('/login');

        $response->assertDontSee('aria-invalid', false);
        $response->assertDontSee('role="alert"', false);
    }

    public function test_dashboard_layout_has_skip_link_main_landmark_and_nav_labels(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Skip to main content');
        $response->assertSee('id="main-content"', false);
        $response->assertSee('aria-label="Sidebar"', false);
        $response->assertSee('aria-label="Primary"', false);
        $response->assertSee('aria-controls="sidebar-nav"', false);
        $response->assertSee('id="sidebar-nav"', false);
    }

    public function test_application_logo_svg_is_hidden_from_assistive_tech(): void
    {
        $response = $this->get('/login');

        $response->assertSee('aria-hidden="true"', false);
    }

    public function test_active_nav_link_gets_aria_current_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSee('aria-current="page"', false);
    }
}
