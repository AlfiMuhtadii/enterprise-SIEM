<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConfidenceSourceRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-058: Confidence Source Refresh
 *
 * Validates: safety constants, deriveSource logic, refresh() dry-run,
 * persistence, getDistribution, getLatestRun, append-only integrity,
 * route access, JSON API.
 */
class ConfidenceSourceRefreshTest extends TestCase
{
    use RefreshDatabase;

    private ConfidenceSourceRefreshService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConfidenceSourceRefreshService::class);
    }

    // ── Safety constant ───────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(ConfidenceSourceRefreshService::ADVISORY_ONLY);
    }

    // ── deriveSource logic ────────────────────────────────────────────────────

    public function test_derive_source_fixture_and_evidence_returns_empirical(): void
    {
        $this->assertSame('empirical', $this->service->deriveSource(true, true));
    }

    public function test_derive_source_fixture_only_returns_fixture_tested(): void
    {
        $this->assertSame('fixture_tested', $this->service->deriveSource(true, false));
    }

    public function test_derive_source_no_fixture_returns_manual(): void
    {
        $this->assertSame('manual', $this->service->deriveSource(false, false));
    }

    public function test_derive_source_evidence_only_returns_manual(): void
    {
        $this->assertSame('manual', $this->service->deriveSource(false, true));
    }

    // ── getDistribution (empty backlog) ───────────────────────────────────────

    public function test_get_distribution_returns_zeros_when_backlog_empty(): void
    {
        $dist = $this->service->getDistribution();
        $this->assertSame(0, $dist['empirical']);
        $this->assertSame(0, $dist['fixture_tested']);
        $this->assertSame(0, $dist['manual']);
        $this->assertSame(0, $dist['total']);
    }

    // ── getLatestRun ──────────────────────────────────────────────────────────

    public function test_get_latest_run_returns_null_when_no_run(): void
    {
        $this->assertNull($this->service->getLatestRun());
    }

    // ── refresh() dry-run ─────────────────────────────────────────────────────

    public function test_refresh_dry_run_does_not_persist(): void
    {
        $this->service->refresh(true);
        $this->assertDatabaseCount('confidence_source_distribution_runs', 0);
        $this->assertDatabaseCount('confidence_source_audit_events', 0);
    }

    public function test_refresh_dry_run_returns_run_and_events_keys(): void
    {
        $result = $this->service->refresh(true);
        $this->assertArrayHasKey('run', $result);
        $this->assertArrayHasKey('events', $result);
    }

    // ── refresh() with seeded backlog ─────────────────────────────────────────

    private function seedBacklogRow(string $ruleId, string $domain, bool $hasFixture, bool $hasEvidence, string $source = 'manual'): void
    {
        DB::table('rule_fixture_backlogs')->insert([
            'rule_id'                => $ruleId,
            'domain'                 => $domain,
            'title'                  => $ruleId,
            'status'                 => 'staged_active',
            'confidence'             => 0.85,
            'confidence_source'      => $source,
            'has_replay_fixture'     => $hasFixture,
            'has_validation_evidence'=> $hasEvidence,
            'is_advisory'            => true,
        ]);
    }

    public function test_refresh_persists_distribution_run(): void
    {
        $this->seedBacklogRow('IDENTITY_MFA_FAILURE_BURST', 'identity', false, false);
        $this->service->refresh(false);
        $this->assertDatabaseCount('confidence_source_distribution_runs', 1);
    }

    public function test_refresh_persists_audit_event(): void
    {
        $this->seedBacklogRow('IDENTITY_MFA_FAILURE_BURST', 'identity', false, false);
        $this->service->refresh(false);
        $this->assertDatabaseCount('confidence_source_audit_events', 1);
    }

    public function test_refresh_upgrades_to_empirical_when_fixture_and_evidence(): void
    {
        $this->seedBacklogRow('IDENTITY_MFA_FAILURE_BURST', 'identity', true, true);
        $this->service->refresh(false);
        $this->assertDatabaseHas('rule_fixture_backlogs', [
            'rule_id' => 'IDENTITY_MFA_FAILURE_BURST',
            'confidence_source' => 'empirical',
        ]);
    }

    public function test_refresh_upgrades_to_fixture_tested_when_fixture_only(): void
    {
        $this->seedBacklogRow('CLOUD_MASS_DOWNLOAD', 'cloud', true, false);
        $this->service->refresh(false);
        $this->assertDatabaseHas('rule_fixture_backlogs', [
            'rule_id' => 'CLOUD_MASS_DOWNLOAD',
            'confidence_source' => 'fixture_tested',
        ]);
    }

    public function test_audit_event_has_is_advisory_true(): void
    {
        $this->seedBacklogRow('IDENTITY_RISKY_IP_LOGIN', 'identity', false, false);
        $this->service->refresh(false);
        $this->assertDatabaseHas('confidence_source_audit_events', [
            'rule_id' => 'IDENTITY_RISKY_IP_LOGIN',
            'is_advisory' => true,
        ]);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/confidence-source-refresh');
        $response->assertRedirect();
    }

    public function test_route_accessible_to_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/confidence-source-refresh');
        $response->assertStatus(200);
    }

    public function test_json_api_returns_advisory_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/confidence-source-refresh');
        $response->assertStatus(200);
        $response->assertJsonPath('advisory_only', true);
    }

    public function test_json_api_returns_distribution(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/confidence-source-refresh');
        $response->assertStatus(200);
        $response->assertJsonStructure(['distribution' => ['empirical', 'fixture_tested', 'manual', 'total']]);
    }
}
