<?php

namespace Tests\Feature;

use App\Services\Phase1SoakEvidenceFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-064: Phase 1 Soak Evidence Freeze
 *
 * Validates:
 *   - Safety constants (ADVISORY_ONLY, NO_PROMOTION, FREEZE_APPROVED)
 *   - All 12 gates evaluate without error
 *   - Structural gates PASS unconditionally
 *   - Advisory gates WARN when live data absent (not FAIL)
 *   - Persist writes exactly one run + 12 gate rows + evidence rows
 *   - Dry-run writes no rows
 *   - getLatestFreeze() returns the most recent persisted run
 *   - freeze_approved is always false in persisted rows
 *   - no_promotion is always true in persisted rows
 *   - Verdict: FAIL only when a non-advisory gate fails
 */
class Phase1SoakEvidenceFreezeTest extends TestCase
{
    use RefreshDatabase;

    private Phase1SoakEvidenceFreezeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(Phase1SoakEvidenceFreezeService::class);
    }

    // ── Safety constants ──────────────────────────────────────────────────────

    public function test_advisory_only_is_true(): void
    {
        $this->assertTrue(Phase1SoakEvidenceFreezeService::ADVISORY_ONLY);
    }

    public function test_no_promotion_is_true(): void
    {
        $this->assertTrue(Phase1SoakEvidenceFreezeService::NO_PROMOTION);
    }

    public function test_freeze_approved_is_false(): void
    {
        $this->assertFalse(Phase1SoakEvidenceFreezeService::FREEZE_APPROVED);
    }

    public function test_freeze_version_is_e064(): void
    {
        $this->assertSame('E064', Phase1SoakEvidenceFreezeService::FREEZE_VERSION);
    }

    public function test_gates_total_is_12(): void
    {
        $this->assertSame(12, Phase1SoakEvidenceFreezeService::GATES_TOTAL);
    }

    public function test_pass_threshold_is_0_80(): void
    {
        $this->assertEqualsWithDelta(0.80, Phase1SoakEvidenceFreezeService::PASS_THRESHOLD, 0.001);
    }

    // ── freeze() returns correct structure ────────────────────────────────────

    public function test_freeze_dry_run_returns_summary_gates_evidence(): void
    {
        $result = $this->service->freeze(true);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('gates', $result);
        $this->assertArrayHasKey('evidence', $result);
    }

    public function test_freeze_returns_12_gates(): void
    {
        $result = $this->service->freeze(true);

        $this->assertCount(12, $result['gates']);
    }

    public function test_freeze_gate_ids_cover_ev064_01_through_12(): void
    {
        $result  = $this->service->freeze(true);
        $ids     = array_column($result['gates'], 'gate_id');

        for ($i = 1; $i <= 12; $i++) {
            $this->assertContains('EV064-' . str_pad($i, 2, '0', STR_PAD_LEFT), $ids);
        }
    }

    public function test_freeze_summary_contains_required_keys(): void
    {
        $summary = $this->service->freeze(true)['summary'];

        foreach (['freeze_run_id', 'freeze_version', 'gates_total', 'gates_passed', 'gates_warned', 'gates_failed', 'pass_score', 'verdict', 'no_promotion', 'freeze_approved', 'is_advisory', 'is_dry_run'] as $key) {
            $this->assertArrayHasKey($key, $summary, "Missing key: {$key}");
        }
    }

    public function test_freeze_summary_freeze_approved_is_always_false(): void
    {
        $summary = $this->service->freeze(true)['summary'];
        $this->assertFalse($summary['freeze_approved']);
    }

    public function test_freeze_summary_no_promotion_is_always_true(): void
    {
        $summary = $this->service->freeze(true)['summary'];
        $this->assertTrue($summary['no_promotion']);
    }

    public function test_freeze_summary_is_advisory_is_true(): void
    {
        $summary = $this->service->freeze(true)['summary'];
        $this->assertTrue($summary['is_advisory']);
    }

    public function test_freeze_summary_is_dry_run_reflects_flag(): void
    {
        $this->assertTrue($this->service->freeze(true)['summary']['is_dry_run']);
        $this->assertFalse($this->service->freeze(false)['summary']['is_dry_run']);
    }

    // ── Structural gates always PASS ──────────────────────────────────────────

    public function test_ev064_01_phase1_soak_execution_service_deployed(): void
    {
        $gates  = $this->service->freeze(true)['gates'];
        $gate   = $this->findGate($gates, 'EV064-01');

        $this->assertNotNull($gate);
        $this->assertSame('pass', $gate['status']);
    }

    public function test_ev064_02_no_promotion_constant_verified(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-02');

        $this->assertSame('pass', $gate['status']);
    }

    public function test_ev064_03_advisory_only_constant_verified(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-03');

        $this->assertSame('pass', $gate['status']);
    }

    public function test_ev064_04_phase1_soak_runs_table_exists(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-04');

        $this->assertSame('pass', $gate['status']);
    }

    public function test_ev064_05_phase1_soak_gate_results_table_exists(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-05');

        $this->assertSame('pass', $gate['status']);
    }

    public function test_ev064_06_fixture_files_present_on_disk(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-06');

        $this->assertSame('pass', $gate['status']);
        $this->assertStringContainsString('12', $gate['evidence']);
    }

    // ── Advisory gates WARN when live data absent (never FAIL) ───────────────

    public function test_ev064_07_warns_when_no_backlog_rows(): void
    {
        // Empty table — rule:run-fixtures not yet run
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-07');

        $this->assertContains($gate['status'], ['pass', 'warn'], 'EV064-07 must never be fail when table is merely empty');
    }

    public function test_ev064_08_warns_when_no_empirical_rules(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-08');

        $this->assertContains($gate['status'], ['pass', 'warn'], 'EV064-08 must never be fail when table is merely empty');
    }

    public function test_ev064_09_is_advisory(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-09');

        $this->assertTrue($gate['is_advisory'], 'EV064-09 must be advisory');
    }

    public function test_ev064_10_is_advisory(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-10');

        $this->assertTrue($gate['is_advisory'], 'EV064-10 must be advisory');
    }

    public function test_ev064_11_is_advisory(): void
    {
        $gates = $this->service->freeze(true)['gates'];
        $gate  = $this->findGate($gates, 'EV064-11');

        $this->assertTrue($gate['is_advisory'], 'EV064-11 must be advisory');
    }

    // ── Dry-run writes no rows ────────────────────────────────────────────────

    public function test_dry_run_writes_no_rows(): void
    {
        $this->service->freeze(true);

        $this->assertSame(0, DB::table('phase1_soak_freeze_runs')->count());
        $this->assertSame(0, DB::table('phase1_soak_freeze_gates')->count());
        $this->assertSame(0, DB::table('phase1_soak_freeze_evidence')->count());
    }

    // ── Persist writes correct row counts ────────────────────────────────────

    public function test_live_freeze_writes_one_run_row(): void
    {
        $this->service->freeze(false);

        $this->assertSame(1, DB::table('phase1_soak_freeze_runs')->count());
    }

    public function test_live_freeze_writes_12_gate_rows(): void
    {
        $this->service->freeze(false);

        $this->assertSame(12, DB::table('phase1_soak_freeze_gates')->count());
    }

    public function test_live_freeze_writes_evidence_rows(): void
    {
        $this->service->freeze(false);

        $this->assertGreaterThan(0, DB::table('phase1_soak_freeze_evidence')->count());
    }

    public function test_live_freeze_persists_freeze_approved_false(): void
    {
        $this->service->freeze(false);

        $row = DB::table('phase1_soak_freeze_runs')->first();
        $this->assertFalse((bool) $row->freeze_approved);
    }

    public function test_live_freeze_persists_no_promotion_true(): void
    {
        $this->service->freeze(false);

        $row = DB::table('phase1_soak_freeze_runs')->first();
        $this->assertTrue((bool) $row->no_promotion);
    }

    public function test_live_freeze_persists_freeze_version_e064(): void
    {
        $this->service->freeze(false);

        $row = DB::table('phase1_soak_freeze_runs')->first();
        $this->assertSame('E064', $row->freeze_version);
    }

    public function test_multiple_freezes_accumulate_rows(): void
    {
        $this->service->freeze(false);
        $this->service->freeze(false);

        $this->assertSame(2, DB::table('phase1_soak_freeze_runs')->count());
        $this->assertSame(24, DB::table('phase1_soak_freeze_gates')->count());
    }

    // ── getLatestFreeze() ─────────────────────────────────────────────────────

    public function test_get_latest_freeze_returns_null_when_no_runs(): void
    {
        $this->assertNull($this->service->getLatestFreeze());
    }

    public function test_get_latest_freeze_returns_most_recent_run(): void
    {
        $this->service->freeze(false);
        $this->service->freeze(false);

        $latest = $this->service->getLatestFreeze();

        $this->assertNotNull($latest);
        $this->assertArrayHasKey('summary', $latest);
        $this->assertArrayHasKey('gates', $latest);
        $this->assertArrayHasKey('evidence', $latest);
    }

    public function test_get_latest_freeze_summary_has_12_gates_total(): void
    {
        $this->service->freeze(false);

        $latest = $this->service->getLatestFreeze();
        $this->assertSame(12, (int) $latest['summary']['gates_total']);
    }

    public function test_get_latest_freeze_gates_count_is_12(): void
    {
        $this->service->freeze(false);

        $latest = $this->service->getLatestFreeze();
        $this->assertCount(12, $latest['gates']);
    }

    // ── Evidence snapshot ─────────────────────────────────────────────────────

    public function test_evidence_includes_soak_run_decision_type(): void
    {
        $evidence = $this->service->freeze(false)['evidence'];

        $types = array_column($evidence, 'evidence_type');
        $this->assertContains('soak_run_decision', $types);
    }

    public function test_evidence_includes_empirical_rules_count(): void
    {
        $evidence = $this->service->freeze(false)['evidence'];

        $types = array_column($evidence, 'evidence_type');
        $this->assertContains('empirical_rules_count', $types);
    }

    public function test_evidence_includes_no_promotion_confirmed(): void
    {
        $evidence = $this->service->freeze(false)['evidence'];

        $types = array_column($evidence, 'evidence_type');
        $this->assertContains('no_promotion_confirmed', $types);

        $noPromoEv = array_filter($evidence, fn ($e) => $e['evidence_type'] === 'no_promotion_confirmed');
        $noPromoEv = array_values($noPromoEv)[0];
        $this->assertSame('true', $noPromoEv['evidence_value']);
    }

    public function test_evidence_fixture_files_on_disk_is_12(): void
    {
        $evidence = $this->service->freeze(false)['evidence'];

        $fixtureEv = array_values(array_filter($evidence, fn ($e) => $e['evidence_type'] === 'fixture_files_on_disk'))[0] ?? null;
        $this->assertNotNull($fixtureEv);
        $this->assertSame('12', $fixtureEv['evidence_value']);
    }

    // ── EV064-07/08 PASS after warm-up ───────────────────────────────────────

    public function test_ev064_07_and_08_pass_after_warm_up_chain(): void
    {
        // Seed the backlog as warm-up would
        for ($i = 1; $i <= 12; $i++) {
            DB::table('rule_fixture_backlogs')->updateOrInsert(
                ['rule_id' => "RULE_{$i}"],
                [
                    'domain'                  => 'identity',
                    'title'                   => "Rule {$i}",
                    'status'                  => 'staged_active',
                    'confidence'              => 0.90,
                    'has_replay_fixture'      => true,
                    'has_validation_evidence' => true,
                    'confidence_source'       => 'empirical',
                    'is_advisory'             => true,
                ]
            );
        }

        $gates  = $this->service->freeze(true)['gates'];
        $gate07 = $this->findGate($gates, 'EV064-07');
        $gate08 = $this->findGate($gates, 'EV064-08');

        $this->assertSame('pass', $gate07['status'], 'EV064-07 must pass when 12 backlog rows have has_validation_evidence=true');
        $this->assertSame('pass', $gate08['status'], 'EV064-08 must pass when 12 rules are empirical');
    }

    // ── Artisan command wiring ────────────────────────────────────────────────

    public function test_artisan_command_runs_without_error(): void
    {
        $this->artisan('soak:phase1-freeze', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_artisan_command_shows_advisory_header(): void
    {
        $this->artisan('soak:phase1-freeze', ['--dry-run' => true])
            ->expectsOutputToContain('ADVISORY_ONLY  = true')
            ->assertExitCode(0);
    }

    public function test_artisan_command_shows_no_promotion(): void
    {
        $this->artisan('soak:phase1-freeze', ['--dry-run' => true])
            ->expectsOutputToContain('NO_PROMOTION   = true')
            ->assertExitCode(0);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findGate(array $gates, string $id): ?array
    {
        return array_values(array_filter($gates, fn ($g) => $g['gate_id'] === $id))[0] ?? null;
    }
}
