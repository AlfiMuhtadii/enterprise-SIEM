<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $policyDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policyDir = storage_path('framework/testing/security-response');
        File::deleteDirectory($this->policyDir);
        File::ensureDirectoryExists($this->policyDir);
        Config::set('security.response_policy_dir', $this->policyDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDir);

        parent::tearDown();
    }

    public function test_login_is_rejected_when_ip_is_temporarily_throttled(): void
    {
        $user = User::factory()->create();

        $this->writePolicy('throttle_ips', [
            '127.0.0.1' => ['expires_at' => now()->addMinutes(10)->toIso8601String()],
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_requires_captcha_for_flagged_ip(): void
    {
        $user = User::factory()->create();

        $this->writePolicy('captcha_ips', [
            '127.0.0.1' => ['expires_at' => now()->addMinutes(10)->toIso8601String()],
        ]);

        $blocked = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $blocked->assertRedirect('/login');
        $blocked->assertSessionHasErrors('email');
        $this->assertGuest();

        $allowed = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'captcha_token' => config('security.demo_captcha_token'),
        ]);

        $allowed->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_revoked_session_is_invalidated_on_next_request(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->writePolicy('revoke_user_ids', [
            (string) $user->id => ['expires_at' => now()->addMinutes(10)->toIso8601String()],
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * @param array<string, array<string, string>> $entries
     */
    public function test_malformed_expiry_is_treated_as_inactive_fail_closed(): void
    {
        // RESP-POLICY-FAIL-OPEN: a malformed (non-empty) expires_at must be treated
        // as expired/inactive, not active-forever. null/empty stays permanent.
        $this->writePolicy('block_ips', [
            '10.0.0.1' => ['expires_at' => 'not-a-real-date', 'reason' => 'malformed'],
            '10.0.0.2' => ['expires_at' => null, 'reason' => 'permanent'],
            '10.0.0.3' => ['expires_at' => now()->addDay()->toIso8601String()],
            '10.0.0.4' => ['expires_at' => now()->subDay()->toIso8601String()],
        ]);

        $this->assertFalse(\App\Support\SecurityResponsePolicy::isIpFlagged('block_ips', '10.0.0.1'), 'malformed expiry must fail-closed');
        $this->assertTrue(\App\Support\SecurityResponsePolicy::isIpFlagged('block_ips', '10.0.0.2'), 'null expiry = permanent');
        $this->assertTrue(\App\Support\SecurityResponsePolicy::isIpFlagged('block_ips', '10.0.0.3'), 'future expiry = active');
        $this->assertFalse(\App\Support\SecurityResponsePolicy::isIpFlagged('block_ips', '10.0.0.4'), 'past expiry = inactive');

        $active = \App\Support\SecurityResponsePolicy::activeEntries('block_ips');
        $this->assertArrayNotHasKey('10.0.0.1', $active);
        $this->assertArrayHasKey('10.0.0.2', $active);
    }

    private function writePolicy(string $name, array $entries): void
    {
        File::put(
            $this->policyDir . DIRECTORY_SEPARATOR . $name . '.json',
            json_encode([
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
                'entries' => $entries,
            ], JSON_PRETTY_PRINT)
        );
    }
}
