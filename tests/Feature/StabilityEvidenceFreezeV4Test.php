<?php

namespace Tests\Feature;

use App\Models\StabilityFreezeV4Run;
use App\Models\User;
use App\Services\StabilityEvidenceFreezeV4Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-059: Stability Evidence Freeze v4
 *
 * Validates: safety constants, 16-gate evaluation, 4 phase summaries,
 * allowed/forbidden claims, gap registry, summary fields, persistence,
 * getLatestFreeze, route access, JSON API.
 */
class StabilityEvidenceFreezeV4Test extends TestCase
{
    use RefreshDatabase;

    private StabilityEvidenceFreezeV4Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StabilityEvidenceFreezeV4Service::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(StabilityEvidenceFreezeV4Service::ADVISORY_ONLY);
    }

    public function test_freeze_approved_constant_is_false(): void
    {
        $this->assertFalse(StabilityEvidenceFreezeV4Service::FREEZE_APPROVED);
    }

    public function test_freeze_version_is_v4(): void
    {
        $this->assertSame('v4', StabilityEvidenceFreezeV4Service::FREEZE_VERSION);
    }

    public function test_phase_range_is_e055_e058(): void
    {
        $this->assertSame('E055-E058', StabilityEvidenceFreezeV4Service::PHASE_RANGE);
    }

    public function test_stable_score_threshold_is_0_80(): void
    {
        $this->assertEqualsWithDelta(0.80, StabilityEvidenceFreezeV4Service::STABLE_SCORE_THRESHOLD, 0.001);
    }

    // ── freeze() dry-run — gates ──────────────────────────────────────────────

    public function test_dry_run_evaluates_16_gates(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(16, $result['gates']);
    }

    public function test_dry_run_gate_ids_cover_ev4_01_to_ev4_16(): void
    {
        $result  = $this->service->freeze(true);
        $gateIds = array_column($result['gates'], 'gate_id');
        for ($i = 1; $i <= 16; $i++) {
            $expected = sprintf('EV4-%02d', $i);
            $this->assertContains($expected, $gateIds, "Gate {$expected} missing");
        }
    }

    public function test_dry_run_all_gates_have_is_advisory_true(): void
    {
        $result = $this->service->freeze(true);
        foreach ($result['gates'] as $gate) {
            $this->assertTrue($gate['is_advisory'], "Gate {$gate['gate_id']} missing is_advisory=true");
        }
    }

    public function test_dry_run_summary_has_freeze_approved_false(): void
    {
        $result = $this->service->freeze(true);
        $this->assertFalse($result['summary']['freeze_approved']);
    }

    public function test_dry_run_summary_has_is_advisory_true(): void
    {
        $result = $this->service->freeze(true);
        $this->assertTrue($result['summary']['is_advisory']);
    }

    // ── freeze() dry-run — phases ─────────────────────────────────────────────

    public function test_dry_run_returns_4_phases(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(4, $result['phases']);
    }

    public function test_dry_run_phases_cover_e055_to_e058(): void
    {
        $result   = $this->service->freeze(true);
        $phaseIds = array_column($result['phases'], 'enterprise_id');
        foreach (['E055', 'E056', 'E057', 'E058'] as $id) {
            $this->assertContains($id, $phaseIds, "Phase {$id} missing");
        }
    }

    // ── freeze() dry-run — claims ─────────────────────────────────────────────

    public function test_dry_run_returns_at_least_5_allowed_claims(): void
    {
        $result  = $this->service->freeze(true);
        $allowed = array_filter($result['claims'], fn ($c) => $c['claim_type'] === 'allowed');
        $this->assertGreaterThanOrEqual(5, count($allowed));
    }

    public function test_dry_run_returns_at_least_3_forbidden_claims(): void
    {
        $result   = $this->service->freeze(true);
        $forbidden = array_filter($result['claims'], fn ($c) => $c['claim_type'] === 'forbidden');
        $this->assertGreaterThanOrEqual(3, count($forbidden));
    }

    // ── freeze() dry-run — gap registry ──────────────────────────────────────

    public function test_dry_run_returns_5_gaps(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(5, $result['gaps']);
    }

    public function test_dry_run_gap_01_severity_is_high(): void
    {
        $result = $this->service->freeze(true);
        $gap1   = array_values(array_filter($result['gaps'], fn ($g) => $g['gap_id'] === 'GAP-01'))[0] ?? null;
        $this->assertNotNull($gap1);
        $this->assertSame('high', strtolower($gap1['severity']));
    }

    public function test_dry_run_gap_02_severity_is_medium(): void
    {
        $result = $this->service->freeze(true);
        $gap2   = array_values(array_filter($result['gaps'], fn ($g) => $g['gap_id'] === 'GAP-02'))[0] ?? null;
        $this->assertNotNull($gap2);
        $this->assertSame('medium', strtolower($gap2['severity']));
    }

    // ── dry-run does not persist ──────────────────────────────────────────────

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->freeze(true);
        $this->assertDatabaseCount('stability_v4_freeze_runs', 0);
    }

    // ── persistence ───────────────────────────────────────────────────────────

    public function test_freeze_persists_run_row(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_v4_freeze_runs', 1);
    }

    public function test_freeze_persists_16_gate_rows(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_v4_freeze_gates', 16);
    }

    public function test_freeze_persists_4_phase_rows(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_v4_phase_summaries', 4);
    }

    public function test_freeze_persists_claims_rows(): void
    {
        $this->service->freeze(false);
        $count = DB::table('stability_v4_readiness_claims')->count();
        $this->assertGreaterThanOrEqual(8, $count);
    }

    public function test_freeze_persists_5_gap_rows(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_v4_gap_registry', 5);
    }

    public function test_freeze_run_has_freeze_approved_false(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseHas('stability_v4_freeze_runs', ['freeze_approved' => false]);
    }

    public function test_freeze_run_has_freeze_version_v4(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseHas('stability_v4_freeze_runs', ['freeze_version' => 'v4']);
    }

    // ── getLatestFreeze ───────────────────────────────────────────────────────

    public function test_get_latest_freeze_returns_null_before_any_run(): void
    {
        $this->assertNull($this->service->getLatestFreeze());
    }

    public function test_get_latest_freeze_returns_data_after_run(): void
    {
        $this->service->freeze(false);
        $latest = $this->service->getLatestFreeze();
        $this->assertNotNull($latest);
        $this->assertArrayHasKey('summary', $latest);
        $this->assertArrayHasKey('gates', $latest);
        $this->assertArrayHasKey('phases', $latest);
        $this->assertArrayHasKey('claims', $latest);
        $this->assertArrayHasKey('gaps', $latest);
    }

    public function test_get_latest_freeze_summary_has_freeze_approved_false(): void
    {
        $this->service->freeze(false);
        $latest = $this->service->getLatestFreeze();
        $this->assertFalse((bool) $latest['summary']['freeze_approved']);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/detection/stability-freeze-v4');
        $response->assertRedirect();
    }

    public function test_route_accessible_to_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/detection/stability-freeze-v4');
        $response->assertStatus(200);
    }

    public function test_json_api_returns_freeze_approved_false(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/stability-freeze-v4');
        $response->assertStatus(200);
        $response->assertJsonPath('freeze_approved', false);
        $response->assertJsonPath('advisory_only', true);
    }

    public function test_json_api_returns_phase_range(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson('/detection/stability-freeze-v4');
        $response->assertStatus(200);
        $response->assertJsonPath('phase_range', 'E055-E058');
    }
}
