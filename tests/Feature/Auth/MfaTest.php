<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDENTITY-SSO-MFA (scoped to TOTP): per-user opt-in second factor. Every
 * test here uses the real TotpService to generate a valid current code —
 * no mocking of the TOTP algorithm itself, since that's exactly the part
 * that must be verified correct end-to-end.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function currentCodeFor(string $secret): string
    {
        $totp = app(TotpService::class);
        $ref = new \ReflectionMethod($totp, 'generateCode');
        $ref->setAccessible(true);

        return $ref->invokeArgs($totp, [$secret, intdiv(time(), 30)]);
    }

    public function test_users_without_mfa_login_unaffected(): void
    {
        $user = User::factory()->create(['mfa_enabled' => false]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_setup_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create(['mfa_enabled' => false]);

        $this->actingAs($user)->get('/mfa/setup')->assertOk();
    }

    public function test_enable_with_valid_code_turns_on_mfa(): void
    {
        $user = User::factory()->create(['mfa_enabled' => false]);
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        $this->actingAs($user)->withSession(['mfa_setup_secret' => $secret]);
        $response = $this->post('/mfa/enable', ['code' => $this->currentCodeFor($secret)]);

        $response->assertRedirect(route('profile.edit'));
        $this->assertTrue($user->fresh()->mfa_enabled);
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
    }

    public function test_enable_with_invalid_code_does_not_turn_on_mfa(): void
    {
        $user = User::factory()->create(['mfa_enabled' => false]);
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        $this->actingAs($user)->withSession(['mfa_setup_secret' => $secret]);
        $response = $this->post('/mfa/enable', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->mfa_enabled);
    }

    public function test_login_with_mfa_enabled_does_not_fully_authenticate_immediately(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('mfa.challenge'));
        $this->assertSame($user->id, session('mfa_pending_user_id'));
    }

    public function test_mfa_challenge_with_valid_code_completes_login(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $response = $this->post('/mfa/challenge', ['code' => $this->currentCodeFor($secret)]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_mfa_challenge_with_invalid_code_stays_unauthenticated(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response = $this->post('/mfa/challenge', ['code' => '000000']);

        $this->assertGuest();
        $response->assertSessionHasErrors('code');
    }

    public function test_mfa_challenge_without_pending_session_redirects_to_login(): void
    {
        $response = $this->get('/mfa/challenge');
        $response->assertRedirect(route('login'));
    }

    public function test_disable_requires_current_password(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        $response = $this->actingAs($user)->post('/mfa/disable', ['password' => 'wrong-password']);

        $response->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->mfa_enabled);
    }

    public function test_disable_with_correct_password_turns_off_mfa(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        $response = $this->actingAs($user)->post('/mfa/disable', ['password' => 'password']);

        $response->assertRedirect(route('profile.edit'));
        $this->assertFalse($user->fresh()->mfa_enabled);
        $this->assertNull($user->fresh()->mfa_secret);
    }

    public function test_mfa_secret_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create(['mfa_enabled' => true, 'mfa_secret' => 'SECRET123']);

        $this->assertArrayNotHasKey('mfa_secret', $user->toArray());
    }
}
