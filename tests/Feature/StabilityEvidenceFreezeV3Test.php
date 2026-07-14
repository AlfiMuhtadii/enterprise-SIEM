<?php

namespace Tests\Feature;

use App\Models\StabilityFreezeV3Run;
use App\Models\User;
use App\Services\StabilityEvidenceFreezeV3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-055: Stability Evidence Freeze v3
 *
 * Validates: constants, 22-gate evaluation, 10 phase summaries,
 * allowed/forbidden claims, gap registry, advisory safety,
 * persistence, getLatestFreeze, route access, JSON API.
 */
class StabilityEvidenceFreezeV3Test extends TestCase
{
    use RefreshDatabase;

    private StabilityEvidenceFreezeV3Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StabilityEvidenceFreezeV3Service::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(StabilityEvidenceFreezeV3Service::ADVISORY_ONLY);
    }

    public function test_freeze_approved_constant_is_false(): void
    {
        $this->assertFalse(StabilityEvidenceFreezeV3Service::FREEZE_APPROVED);
    }

    public function test_freeze_version_is_v3(): void
    {
        $this->assertSame('v3', StabilityEvidenceFreezeV3Service::FREEZE_VERSION);
    }

    public function test_phase_range_is_e045_e054(): void
    {
        $this->assertSame('E045-E054', StabilityEvidenceFreezeV3Service::PHASE_RANGE);
    }

    public function test_stable_score_threshold_is_0_80(): void
    {
        $this->assertEqualsWithDelta(0.80, StabilityEvidenceFreezeV3Service::STABLE_SCORE_THRESHOLD, 0.001);
    }

    // ── freeze() dry-run — gates ──────────────────────────────────────────────

    public function test_dry_run_evaluates_22_gates(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(22, $result['gates']);
    }

    public function test_dry_run_gate_ids_cover_ev3_01_to_ev3_22(): void
    {
        $result = $this->service->freeze(true);
        $gateIds = array_column($result['gates'], 'gate_id');
        for ($i = 1; $i <= 22; $i++) {
            $expected = sprintf('EV3-%02d', $i);
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

    public function test_dry_run_pass_score_between_0_and_1(): void
    {
        $result = $this->service->freeze(true);
        $score = $result['summary']['pass_score'];
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    // ── freeze() dry-run — phases ─────────────────────────────────────────────

    public function test_dry_run_collects_10_phase_summaries(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(10, $result['phases']);
    }

    public function test_dry_run_phases_cover_e045_to_e054(): void
    {
        $result = $this->service->freeze(true);
        $phaseIds = array_column($result['phases'], 'enterprise_id');
        foreach (['E045', 'E046', 'E047', 'E048', 'E049', 'E050', 'E051', 'E052', 'E053', 'E054'] as $eid) {
            $this->assertContains($eid, $phaseIds, "Phase {$eid} missing");
        }
    }

    // ── freeze() dry-run — summary ────────────────────────────────────────────

    public function test_dry_run_summary_freeze_approved_is_false(): void
    {
        $result = $this->service->freeze(true);
        $this->assertFalse($result['summary']['freeze_approved']);
    }

    public function test_dry_run_summary_is_advisory_is_true(): void
    {
        $result = $this->service->freeze(true);
        $this->assertTrue($result['summary']['is_advisory']);
    }

    public function test_dry_run_stability_field_present(): void
    {
        $result = $this->service->freeze(true);
        $this->assertContains($result['summary']['stability'], ['STABLE', 'UNSTABLE']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->freeze(true);
        $this->assertDatabaseCount('stability_v3_freeze_runs', 0);
    }

    public function test_dry_run_summary_total_phases_is_10(): void
    {
        $result = $this->service->freeze(true);
        $this->assertSame(10, $result['summary']['total_phases']);
    }

    // ── Allowed / forbidden claims ────────────────────────────────────────────

    public function test_dry_run_returns_allowed_claims(): void
    {
        $result = $this->service->freeze(true);
        $allowed = array_filter($result['claims'], fn ($c) => $c['claim_type'] === 'allowed');
        $this->assertNotEmpty($allowed);
    }

    public function test_dry_run_returns_forbidden_claims(): void
    {
        $result = $this->service->freeze(true);
        $forbidden = array_filter($result['claims'], fn ($c) => $c['claim_type'] === 'forbidden');
        $this->assertNotEmpty($forbidden);
    }

    public function test_dry_run_allowed_claim_count_matches_summary(): void
    {
        $result = $this->service->freeze(true);
        $allowed = count(array_filter($result['claims'], fn ($c) => $c['claim_type'] === 'allowed'));
        $this->assertSame($allowed, $result['summary']['allowed_claim_count']);
    }

    // ── Gap registry ──────────────────────────────────────────────────────────

    public function test_dry_run_gap_registry_has_7_entries(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(7, $result['gaps']);
    }

    public function test_dry_run_gap_registry_has_fixture_gap(): void
    {
        $result = $this->service->freeze(true);
        $descriptions = array_column($result['gaps'], 'description');
        $hasFixtureGap = false;
        foreach ($descriptions as $d) {
            if (str_contains($d, 'fixture')) {
                $hasFixtureGap = true;
                break;
            }
        }
        $this->assertTrue($hasFixtureGap, 'GAP-01 fixture gap not found');
    }

    public function test_dry_run_gap_registry_has_soak_gap(): void
    {
        $result = $this->service->freeze(true);
        $descriptions = array_column($result['gaps'], 'description');
        $hasSoakGap = false;
        foreach ($descriptions as $d) {
            if (str_contains($d, 'soak')) {
                $hasSoakGap = true;
                break;
            }
        }
        $this->assertTrue($hasSoakGap, 'GAP-03 soak gap not found');
    }

    public function test_dry_run_gap_count_matches_summary(): void
    {
        $result = $this->service->freeze(true);
        $this->assertSame(count($result['gaps']), $result['summary']['gap_count']);
    }

    // ── freeze() with persistence ─────────────────────────────────────────────

    public function test_persist_creates_freeze_run_row(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_v3_freeze_runs', 1);
    }

    public function test_persist_creates_22_gate_rows(): void
    {
        $this->service->freeze(false);
        $this->assertSame(22, DB::table('stability_v3_freeze_gates')->count());
    }

    public function test_persist_creates_10_phase_summary_rows(): void
    {
        $this->service->freeze(false);
        $this->assertSame(10, DB::table('stability_v3_phase_summaries')->count());
    }

    public function test_persist_creates_readiness_claims(): void
    {
        $this->service->freeze(false);
        $this->assertGreaterThan(0, DB::table('stability_v3_readiness_claims')->count());
    }

    public function test_persist_creates_gap_registry_rows(): void
    {
        $this->service->freeze(false);
        $this->assertSame(7, DB::table('stability_v3_gap_registry')->count());
    }

    public function test_persisted_run_has_freeze_approved_false(): void
    {
        $this->service->freeze(false);
        $run = StabilityFreezeV3Run::first();
        $this->assertFalse((bool) $run->freeze_approved);
    }

    // ── getLatestFreeze() ─────────────────────────────────────────────────────

    public function test_get_latest_freeze_returns_null_when_no_runs(): void
    {
        $this->assertNull($this->service->getLatestFreeze());
    }

    public function test_get_latest_freeze_returns_complete_data_after_run(): void
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

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/detection/stability-freeze-v3')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_stability_freeze_v3_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/stability-freeze-v3')
            ->assertStatus(200)
            ->assertSeeText('Stability Evidence Freeze v3');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/stability-freeze-v3')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true)
            ->assertJsonPath('freeze_approved', false);
    }

    public function test_json_api_returns_phase_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/stability-freeze-v3')
            ->assertStatus(200)
            ->assertJsonPath('phase_range', 'E045-E054');
    }
}
