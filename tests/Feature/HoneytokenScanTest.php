<?php

namespace Tests\Feature;

use App\Exceptions\TenantBoundaryViolationException;
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

        $firstRunHits = $service->scanForHits();
        $secondRunHits = $service->scanForHits();

        $this->assertSame(1, $firstRunHits);
        $this->assertSame(0, $secondRunHits, 'a repeat scan must report zero NEW matches for an already-recorded pair');
        $this->assertSame(1, DB::table('honeytoken_hits')->where('alert_id', 'alert-rep')->count());

        // The bug this guards against: evidence['honeytoken_matches'] used to
        // grow by one entry on every re-scan even though honeytoken_hits
        // itself stayed deduplicated -- must still be exactly 1 after two runs.
        $evidence = json_decode(DB::table('security_alerts')->where('alert_id', 'alert-rep')->value('evidence'), true);
        $this->assertCount(1, $evidence['honeytoken_matches']);
    }

    public function test_scan_denormalizes_tenant_id_onto_hit_rows(): void
    {
        $service = app(HoneytokenService::class);
        $service->create('credential', 'decoy-tenant-a@example.test', null, 'tenant-a');
        $this->seedAlert('alert-tenant', ['user' => 'decoy-tenant-a@example.test']);

        $service->scanForHits();

        $this->assertDatabaseHas('honeytoken_hits', [
            'alert_id' => 'alert-tenant',
            'tenant_id' => 'tenant-a',
        ]);
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

    // -----------------------------------------------------------------------
    // Tenant isolation
    // -----------------------------------------------------------------------

    public function test_store_stamps_tenant_id_from_request_context(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post(route('honeytoken.store'), [
                'token_type' => 'credential',
                'token_value' => 'decoy-scoped@example.test',
            ])
            ->assertRedirect(route('honeytoken.index'));

        $this->assertDatabaseHas('honeytokens', [
            'token_value' => 'decoy-scoped@example.test',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_index_only_shows_honeytokens_and_hits_for_the_requesting_tenant(): void
    {
        $service = app(HoneytokenService::class);
        $service->create('credential', 'decoy-tenant-a-visible@example.test', null, 'tenant-a');
        $service->create('credential', 'decoy-tenant-b-hidden@example.test', null, 'tenant-b');
        $this->seedAlert('alert-a', ['user' => 'decoy-tenant-a-visible@example.test']);
        $this->seedAlert('alert-b', ['user' => 'decoy-tenant-b-hidden@example.test']);
        $service->scanForHits();

        $viewer = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($viewer)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('honeytoken.index'));

        $response->assertOk();
        $response->assertSee('decoy-tenant-a-visible@example.test');
        $response->assertDontSee('decoy-tenant-b-hidden@example.test');
        $response->assertSee('alert-a');
        $response->assertDontSee('alert-b');
    }

    public function test_index_without_tenant_header_shows_everything_legacy_pass_through(): void
    {
        $service = app(HoneytokenService::class);
        $service->create('credential', 'decoy-legacy-a@example.test', null, 'tenant-a');
        $service->create('credential', 'decoy-legacy-b@example.test', null, 'tenant-b');

        $viewer = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($viewer)->get(route('honeytoken.index'));

        $response->assertOk();
        $response->assertSee('decoy-legacy-a@example.test');
        $response->assertSee('decoy-legacy-b@example.test');
    }

    public function test_deactivate_blocks_cross_tenant_attempt(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'decoy-cross-tenant@example.test', null, 'tenant-a');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-b'])
            ->post(route('honeytoken.deactivate', $token->honeytoken_id))
            ->assertForbidden();

        $this->assertTrue($token->fresh()->is_active);
    }

    public function test_service_deactivate_throws_on_cross_tenant_mismatch(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'decoy-service-cross@example.test', null, 'tenant-a');

        $this->expectException(TenantBoundaryViolationException::class);
        $service->deactivate($token->honeytoken_id, 'tenant-b');
    }

    public function test_service_deactivate_allows_matching_tenant(): void
    {
        $service = app(HoneytokenService::class);
        $token = $service->create('credential', 'decoy-service-match@example.test', null, 'tenant-a');

        $this->assertTrue($service->deactivate($token->honeytoken_id, 'tenant-a'));
        $this->assertFalse($token->fresh()->is_active);
    }
}
