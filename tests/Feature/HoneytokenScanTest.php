<?php

namespace Tests\Feature;

use App\Models\Honeytoken;
use App\Models\User;
use App\Services\HoneytokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CAP-DECEPTION-HONEYTOKEN — mirrors PerfIocLoopTest's structure since
 * HoneytokenService::scanForHits() intentionally mirrors
 * SocThreatIntelController::matchIocs() exactly.
 */
class HoneytokenScanTest extends TestCase
{
    use RefreshDatabase;

    private function seedAlert(string $alertId, array $evidence = []): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => 'g|'.$alertId,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'IDENTITY_ANOMALY',
            'detector_name' => 'IDENTITY_ANOMALY',
            'detector_version' => 'v1',
            'severity' => 'medium',
            'ip' => '203.0.113.5',
            'actor_key' => '203.0.113.5',
            'score' => 0.5,
            'evidence' => json_encode($evidence),
            'raw_event' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_scan_finds_hit_when_honeytoken_credential_appears_in_alert_evidence(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'svc-decoy-admin@example.test', 'decoy admin account');

        $this->seedAlert('alert-cred', ['user' => 'svc-decoy-admin@example.test']);

        $hits = $service->scanForHits();

        $this->assertSame(1, $hits);
        $this->assertDatabaseHas('honeytoken_hits', [
            'honeytoken_id' => $token->honeytoken_id,
            'alert_id' => 'alert-cred',
            'matched_field' => 'credential',
        ]);
    }

    public function test_scan_persists_all_matches_when_alert_touches_multiple_honeytokens(): void
    {
        $service = app(HoneytokenService::class);
        $t1 = $service->create('file_path', 'C:/decoy/passwords.txt');
        $t2 = $service->create('dns_name', 'decoy-internal.example.test');

        $this->seedAlert('alert-multi', ['file' => 'C:/decoy/passwords.txt', 'dns_query' => 'decoy-internal.example.test']);

        $hits = $service->scanForHits();

        $this->assertSame(2, $hits);
        $this->assertSame(2, DB::table('honeytoken_hits')->where('alert_id', 'alert-multi')->count());

        $evidence = json_decode(DB::table('security_alerts')->where('alert_id', 'alert-multi')->value('evidence'), true);
        $this->assertCount(2, $evidence['honeytoken_matches']);
    }

    public function test_scan_ignores_inactive_honeytokens(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('url', 'https://decoy.example.test/secret');
        $service->deactivate($token->honeytoken_id);

        $this->seedAlert('alert-inactive', ['url' => 'https://decoy.example.test/secret']);

        $hits = $service->scanForHits();

        $this->assertSame(0, $hits);
        $this->assertSame(0, DB::table('honeytoken_hits')->count());
    }

    public function test_scan_is_idempotent_on_repeat_runs(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'decoy-user-2@example.test');
        $this->seedAlert('alert-rep', ['user' => 'decoy-user-2@example.test']);

        $service->scanForHits();
        $service->scanForHits();

        $this->assertSame(1, DB::table('honeytoken_hits')->where('alert_id', 'alert-rep')->count());
    }

    public function test_scan_finds_nothing_with_no_active_honeytokens(): void
    {
        $this->seedAlert('alert-none', ['user' => 'legit-user@example.test']);
        $hits = app(HoneytokenService::class)->scanForHits();

        $this->assertSame(0, $hits);
    }

    public function test_create_rejects_invalid_token_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(HoneytokenService::class)->create('not_a_real_type', 'value');
    }

    public function test_deactivate_prevents_future_matches_without_deleting_history(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'decoy-user-3@example.test');
        $this->seedAlert('alert-hist', ['user' => 'decoy-user-3@example.test']);
        $service->scanForHits();
        $this->assertSame(1, DB::table('honeytoken_hits')->count());

        $service->deactivate($token->honeytoken_id);
        $this->assertDatabaseHas('honeytokens', ['honeytoken_id' => $token->honeytoken_id, 'is_active' => false]);
        // prior hit row is untouched (append-only)
        $this->assertSame(1, DB::table('honeytoken_hits')->count());
    }

    public function test_scan_command_reports_hit_count(): void
    {
        $service = app(HoneytokenService::class);
        $service->create('credential', 'decoy-user-4@example.test');
        $this->seedAlert('alert-cmd', ['user' => 'decoy-user-4@example.test']);

        $this->artisan('honeytoken:scan')
            ->expectsOutputToContain('1 match(es) found')
            ->assertSuccessful();
    }

    public function test_index_route_requires_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('honeytoken.index'))->assertOk();
    }

    public function test_store_route_forbidden_for_viewer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->post(route('honeytoken.store'), ['token_type' => 'credential', 'token_value' => 'x'])
            ->assertForbidden();
    }

    public function test_admin_can_seed_and_deactivate_via_http(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('honeytoken.store'), [
            'token_type' => 'credential',
            'token_value' => 'decoy-http@example.test',
            'label' => 'http seeded',
        ])->assertRedirect(route('honeytoken.index'));

        $token = Honeytoken::where('token_value', 'decoy-http@example.test')->firstOrFail();
        $this->assertTrue($token->is_active);

        $this->actingAs($admin)
            ->post(route('honeytoken.deactivate', $token->honeytoken_id))
            ->assertRedirect(route('honeytoken.index'));

        $this->assertFalse($token->fresh()->is_active);
    }
}
