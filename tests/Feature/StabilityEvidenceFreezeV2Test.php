<?php

namespace Tests\Feature;

use App\Models\StabilityFreezeRun;
use App\Models\User;
use App\Services\StabilityEvidenceFreezeV2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-049: Stability Evidence Freeze v2
 *
 * Validates: constants, gate evaluation (12 gates), phase summaries (4),
 * advisory safety, persistence, route access, JSON API.
 */
class StabilityEvidenceFreezeV2Test extends TestCase
{
    use RefreshDatabase;

    private StabilityEvidenceFreezeV2Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StabilityEvidenceFreezeV2Service::class);
    }

    // ── Safety constants ─────────────────────────────────────────────────────

    public function test_advisory_only_constant_is_true(): void
    {
        $this->assertTrue(StabilityEvidenceFreezeV2Service::ADVISORY_ONLY);
    }

    public function test_freeze_approved_constant_is_false(): void
    {
        $this->assertFalse(StabilityEvidenceFreezeV2Service::FREEZE_APPROVED);
    }

    public function test_freeze_version_is_v2(): void
    {
        $this->assertSame('v2', StabilityEvidenceFreezeV2Service::FREEZE_VERSION);
    }

    public function test_phase_range_is_e045_e048(): void
    {
        $this->assertSame('E045-E048', StabilityEvidenceFreezeV2Service::PHASE_RANGE);
    }

    public function test_stable_score_threshold_is_0_80(): void
    {
        $this->assertEqualsWithDelta(0.80, StabilityEvidenceFreezeV2Service::STABLE_SCORE_THRESHOLD, 0.001);
    }

    // ── freeze() dry-run ──────────────────────────────────────────────────────

    public function test_dry_run_evaluates_12_gates(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(12, $result['gates']);
    }

    public function test_dry_run_collects_4_phase_summaries(): void
    {
        $result = $this->service->freeze(true);
        $this->assertCount(4, $result['phases']);
    }

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

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->freeze(true);
        $this->assertDatabaseCount('stability_freeze_runs', 0);
    }

    public function test_dry_run_pass_score_is_between_0_and_1(): void
    {
        $result = $this->service->freeze(true);
        $score = $result['summary']['pass_score'];
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_dry_run_stability_field_present(): void
    {
        $result = $this->service->freeze(true);
        $this->assertContains($result['summary']['stability'], ['STABLE', 'UNSTABLE']);
    }

    public function test_dry_run_gates_all_have_is_advisory_true(): void
    {
        $result = $this->service->freeze(true);
        foreach ($result['gates'] as $gate) {
            $this->assertTrue($gate['is_advisory']);
        }
    }

    public function test_dry_run_phases_cover_e045_to_e048(): void
    {
        $result  = $this->service->freeze(true);
        $phaseIds = array_column($result['phases'], 'enterprise_id');
        foreach (['E045', 'E046', 'E047', 'E048'] as $eid) {
            $this->assertContains($eid, $phaseIds);
        }
    }

    public function test_dry_run_gate_ids_cover_ef01_to_ef12(): void
    {
        $result   = $this->service->freeze(true);
        $gateIds  = array_column($result['gates'], 'gate_id');
        for ($i = 1; $i <= 12; $i++) {
            $expected = sprintf('EF-%02d', $i);
            $this->assertContains($expected, $gateIds, "Gate {$expected} missing");
        }
    }

    // ── freeze() with persistence ──────────────────────────────────────────

    public function test_persist_creates_freeze_run_row(): void
    {
        $this->service->freeze(false);
        $this->assertDatabaseCount('stability_freeze_runs', 1);
    }

    public function test_persist_creates_12_gate_rows(): void
    {
        $this->service->freeze(false);
        $this->assertSame(12, DB::table('stability_freeze_gates')->count());
    }

    public function test_persist_creates_4_phase_summary_rows(): void
    {
        $this->service->freeze(false);
        $this->assertSame(4, DB::table('stability_freeze_phase_summaries')->count());
    }

    public function test_persisted_run_has_freeze_approved_false(): void
    {
        $this->service->freeze(false);
        $run = StabilityFreezeRun::first();
        $this->assertFalse((bool) $run->freeze_approved);
    }

    // ── getLatestFreeze() ─────────────────────────────────────────────────────

    public function test_get_latest_freeze_returns_null_when_no_runs(): void
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
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/detection/stability-freeze-v2')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_stability_freeze_view(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/detection/stability-freeze-v2')
            ->assertStatus(200)
            ->assertSeeText('Stability Evidence Freeze v2');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/stability-freeze-v2')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true)
            ->assertJsonPath('freeze_approved', false);
    }

    public function test_json_api_returns_stable_threshold(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/detection/stability-freeze-v2')
            ->assertStatus(200)
            ->assertJsonPath('stable_threshold', 0.8);
    }
}
