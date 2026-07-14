<?php

namespace Tests\Feature;

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I18N-LOCALIZATION: no lang/ directory existed and only Breeze's own
 * scaffold views called __() at all -- with nothing published to
 * translate against. This bounded first phase builds the locale
 * resolution framework (SetUserLocale middleware, users.locale column,
 * profile language selector) and converts one full view (login +
 * shared layout skip-link text) as a working, tested proof-of-pattern.
 * See docs/i18n/LOCALIZATION_GUIDE.md for the remaining per-view scope.
 */
class I18nLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_english_with_no_signal(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign in to Security Demo');
    }

    public function test_lang_query_param_switches_login_page_to_indonesian(): void
    {
        $response = $this->get('/login?lang=id');

        $response->assertOk();
        $response->assertSee('Masuk ke Security Demo');
        $response->assertSee('Konsol Detector');
    }

    public function test_lang_query_param_persists_to_session_for_next_request(): void
    {
        $this->get('/login?lang=id');

        $follow = $this->get('/login');

        $follow->assertOk();
        $follow->assertSee('Masuk ke Security Demo');
    }

    public function test_unsupported_lang_query_param_is_ignored(): void
    {
        $response = $this->get('/login?lang=fr');

        $response->assertOk();
        $response->assertSee('Sign in to Security Demo');
    }

    public function test_authenticated_user_with_stored_locale_gets_translated_view_without_query_param(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'locale' => 'id']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Langsung ke konten utama');
    }

    public function test_lang_query_param_persists_to_authenticated_users_locale_column(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'locale' => null]);

        $this->actingAs($user)->get('/dashboard?lang=id');

        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_skip_link_text_is_translated_on_guest_layout(): void
    {
        $response = $this->get('/login?lang=id');

        $response->assertSee('Langsung ke konten utama');
    }

    public function test_profile_update_accepts_supported_locale(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'locale' => null]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'id',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('id', $user->fresh()->locale);
    }

    public function test_profile_update_rejects_unsupported_locale(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'fr',
        ]);

        $response->assertSessionHasErrors('locale');
    }

    public function test_profile_settings_page_renders_locale_selector_with_supported_options(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('id="locale"', false);
        foreach (SetUserLocale::SUPPORTED_LOCALES as $code) {
            $response->assertSee('value="'.$code.'"', false);
        }
    }
}
