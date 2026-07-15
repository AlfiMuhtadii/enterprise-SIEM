<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * SEC-SESSION-INVALIDATION: a session hijacked before a password change or
 * MFA-disable must not survive it. Auth::logoutOtherDevices() alone only
 * regenerates the remember-me token; AuthenticateSession (now in the 'web'
 * middleware group) is what actually enforces it on every other session's
 * next request, by comparing a password hash stored in that session against
 * the user's current one.
 *
 * The single HTTP test client shares one cookie jar, so a literal
 * "two separate browsers" scenario is simulated the same way Laravel's own
 * AuthenticateSession tests do: seed the request's session with a specific
 * password_hash_web value via withSession(), standing in for "a browser
 * that authenticated before the change and hasn't made a request since."
 */
class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_update_fires_other_device_logout_event(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(OtherDeviceLogout::class, fn ($event) => $event->user->is($user));
    }

    public function test_mfa_disable_fires_other_device_logout_event(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/profile')
            ->post('/mfa/disable', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $user->fresh()->mfa_enabled);
        Event::assertDispatched(OtherDeviceLogout::class, fn ($event) => $event->user->is($user));
    }

    public function test_session_with_stale_password_hash_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['password_hash_web' => 'a-stale-hash-from-before-the-change'])
            ->get('/profile');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_session_with_current_password_hash_is_not_logged_out(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['password_hash_web' => $user->password])
            ->get('/profile');

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_end_to_end_other_session_is_logged_out_after_password_change(): void
    {
        $user = User::factory()->create();
        $passwordHashBeforeChange = $user->password;

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHasNoErrors();

        // "Browser B": still carries the password hash recorded before the
        // change above -- its next request must be logged out, not silently
        // still authenticated on the old credential.
        $response = $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $passwordHashBeforeChange])
            ->get('/profile');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_guest_requests_are_unaffected_by_authenticate_session_middleware(): void
    {
        $this->get('/login')->assertOk();
    }
}
