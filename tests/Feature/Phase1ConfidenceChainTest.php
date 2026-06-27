<?php

namespace Tests\Feature;

use App\Services\ConfidenceSourceRefreshService;
use App\Services\DetectionReplayFixtureService;
use App\Services\Phase1SoakExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-063: Fixture Confidence Refresh Wiring
 *
 * Validates the end-to-end chain:
 *   rule:run-fixtures → rule_fixture_backlogs upserted → rule:refresh-confidence → empirical labels
 *   → confidence_source_audit_events written → P1G-04 PASS
 *
 * Root cause fixed: DetectionReplayFixtureService::persist() now uses updateOrInsert
 * so rows are CREATED when rule_fixture_backlogs is empty (first run after migrate:fresh).
 */
class Phase1ConfidenceChainTest extends TestCase
{
    use RefreshDatabase;

    private DetectionReplayFixtureService $fixtureService;
    private ConfidenceSourceRefreshService $refreshService;
    private Phase1SoakExecutionService $soakService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureService = app(DetectionReplayFixtureService::class);
        $this->refreshService = app(ConfidenceSourceRefreshService::class);
        $this->soakService    = app(Phase1SoakExecutionService::class);
    }

    // ── Step 1: rule:run-fixtures creates backlog rows ────────────────────────

    public function test_fixture_batch_creates_backlog_rows_when_table_empty(): void
    {
        $this->assertSame(0, DB::table('rule_fixture_backlogs')->count(), 'precondition: table empty');

        $this->fixtureService->runBatch('tier_1_immediate', false);

        $this->assertSame(12, DB::table('rule_fixture_backlogs')->count());
    }

    public function test_fixture_batch_sets_has_replay_fixture_true(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $withFixture = DB::table('rule_fixture_backlogs')->where('has_replay_fixture', true)->count();
        $this->assertSame(12, $withFixture);
    }

    public function test_fixture_batch_sets_has_validation_evidence_true(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $withEvidence = DB::table('rule_fixture_backlogs')->where('has_validation_evidence', true)->count();
        $this->assertSame(12, $withEvidence);
    }

    public function test_fixture_batch_sets_priority_tier_to_tier_1_immediate(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $count = DB::table('rule_fixture_backlogs')->where('priority_tier', 'tier_1_immediate')->count();
        $this->assertSame(12, $count);
    }

    public function test_fixture_batch_sets_status_staged_active(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $count = DB::table('rule_fixture_backlogs')->where('status', 'staged_active')->count();
        $this->assertSame(12, $count);
    }

    public function test_fixture_batch_sets_is_advisory_true(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $count = DB::table('rule_fixture_backlogs')->where('is_advisory', true)->count();
        $this->assertSame(12, $count);
    }

    // ── Idempotency: running twice must not duplicate rows ────────────────────

    public function test_fixture_batch_upserts_idempotently(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $this->assertSame(12, DB::table('rule_fixture_backlogs')->count(), 'rows must not be duplicated on second run');
    }

    public function test_fixture_batch_upsert_updates_existing_row(): void
    {
        // Pre-seed a row with has_replay_fixture=false to simulate stale data
        DB::table('rule_fixture_backlogs')->insert([
            'rule_id'                 => 'IDENTITY_MFA_FAILURE_BURST',
            'domain'                  => 'identity',
            'title'                   => 'stale row',
            'status'                  => 'staged_active',
            'confidence'              => 0.50,
            'has_replay_fixture'      => false,
            'has_validation_evidence' => false,
            'is_advisory'             => true,
        ]);

        $this->fixtureService->runBatch('tier_1_immediate', false);

        $row = DB::table('rule_fixture_backlogs')->where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')->first();
        $this->assertTrue((bool) $row->has_replay_fixture, 'upsert must update existing row');
        $this->assertTrue((bool) $row->has_validation_evidence, 'upsert must set validation evidence');
    }

    // ── Dry-run: no backlog rows written ─────────────────────────────────────

    public function test_fixture_batch_dry_run_does_not_create_backlog_rows(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', true);

        $this->assertSame(0, DB::table('rule_fixture_backlogs')->count());
    }

    // ── Step 2: rule:refresh-confidence reads populated backlog ───────────────

    public function test_confidence_refresh_after_fixture_run_finds_12_rules(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $result = $this->refreshService->refresh(false);
        $run    = $result['run'];

        $this->assertSame(12, $run['total_rules'], 'refresh must find 12 rules in rule_fixture_backlogs');
    }

    public function test_confidence_refresh_marks_all_12_as_empirical(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $result = $this->refreshService->refresh(false);
        $run    = $result['run'];

        $this->assertSame(12, $run['empirical_count']);
        $this->assertSame(0, $run['fixture_tested_count']);
        $this->assertSame(0, $run['manual_count']);
    }

    public function test_confidence_refresh_sets_empirical_rate_to_100_percent(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);

        $result = $this->refreshService->refresh(false);
        $this->assertEqualsWithDelta(1.0, $result['run']['empirical_rate'], 0.001);
    }

    public function test_confidence_refresh_writes_audit_events(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->refreshService->refresh(false);

        $auditCount = DB::table('confidence_source_audit_events')
            ->where('new_confidence_source', 'empirical')
            ->count();
        $this->assertSame(12, $auditCount);
    }

    public function test_confidence_refresh_updates_confidence_source_in_backlog(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->refreshService->refresh(false);

        $empiricalCount = DB::table('rule_fixture_backlogs')
            ->where('confidence_source', 'empirical')
            ->count();
        $this->assertSame(12, $empiricalCount);
    }

    // ── Step 3: P1G-04 PASS after the full chain ─────────────────────────────

    public function test_p1g04_passes_after_fixture_run_and_confidence_refresh(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->refreshService->refresh(false);

        $gates = $this->soakService->runGates(false);
        $p1g04 = $this->findGate($gates, 'P1G-04');

        $this->assertNotNull($p1g04);
        $this->assertSame('pass', $p1g04['status'], 'P1G-04 must pass after full confidence chain');
        $this->assertStringContainsString('empirical', $p1g04['evidence']);
    }

    public function test_p1g04_passes_via_backlog_source_when_both_tables_populated(): void
    {
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->refreshService->refresh(false);

        $gates = $this->soakService->runGates(false);
        $p1g04 = $this->findGate($gates, 'P1G-04');

        // Primary source (rule_fixture_backlogs) should be cited
        $this->assertStringContainsString('rule_fixture_backlogs', $p1g04['evidence']);
    }

    public function test_full_chain_improves_gate_pass_count(): void
    {
        // Before warm-up: P1G-04 is advisory warn
        $before = $this->soakService->buildRun(false);
        $passedBefore = $before['plan']['gates_passed'];

        // After warm-up chain
        $this->fixtureService->runBatch('tier_1_immediate', false);
        $this->refreshService->refresh(false);

        $after = $this->soakService->buildRun(false);
        $passedAfter = $after['plan']['gates_passed'];

        $this->assertGreaterThan($passedBefore, $passedAfter, 'Warm-up chain must increase gates_passed');
    }

    // ── --warm-up flag wires the same chain via artisan ──────────────────────

    public function test_warm_up_flag_seeds_empirical_evidence_via_artisan(): void
    {
        $this->assertSame(0, DB::table('rule_fixture_backlogs')->count(), 'precondition: empty');

        $this->artisan('soak:phase1-run', ['--warm-up' => true, '--dry-run' => false])
            ->assertExitCode(0);

        $empiricalCount = DB::table('rule_fixture_backlogs')
            ->where('confidence_source', 'empirical')
            ->count();
        $this->assertSame(12, $empiricalCount, '--warm-up must seed 12 empirical backlog rows');
    }

    public function test_warm_up_flag_produces_passing_p1g04_in_output(): void
    {
        $this->artisan('soak:phase1-run', ['--warm-up' => true, '--dry-run' => false])
            ->expectsOutputToContain('[PASS] [P1G-04]')
            ->assertExitCode(0);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findGate(array $gates, string $gateId): ?array
    {
        return array_values(array_filter($gates, fn ($g) => $g['gate_id'] === $gateId))[0] ?? null;
    }
}
