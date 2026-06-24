<?php

namespace Tests\Feature;

use App\Console\Commands\PilotEvidenceFreezeCommand;
use App\Models\PilotReadinessEvidenceLink;
use App\Models\PilotReadinessGateEvaluation;
use App\Models\PilotReadinessMatrixRun;
use App\Services\EnterprisePilotReadinessMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PILOT-LIVE-035: Tests for PilotEvidenceFreezeCommand.
 *
 * The command is advisory-only — no alerts, no incidents, no autonomous promotion.
 * Self-approve is blocked (operator cannot equal approved-by).
 */
class PilotEvidenceFreezeTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Constants & command registration (5 tests)
    // =========================================================================

    public function test_command_class_exists(): void
    {
        $this->assertTrue(class_exists(PilotEvidenceFreezeCommand::class));
    }

    public function test_advisory_only_service_constant(): void
    {
        $this->assertTrue(EnterprisePilotReadinessMatrixService::ADVISORY_ONLY);
    }

    public function test_self_approve_blocked_service_constant(): void
    {
        $this->assertTrue(EnterprisePilotReadinessMatrixService::SELF_APPROVE_BLOCKED);
    }

    public function test_autonomous_promotion_service_constant_is_false(): void
    {
        $this->assertFalse(EnterprisePilotReadinessMatrixService::AUTONOMOUS_PROMOTION);
    }

    public function test_required_gate_ids_includes_four_gates(): void
    {
        $ids = EnterprisePilotReadinessMatrixService::REQUIRED_GATE_IDS;
        $this->assertCount(4, $ids);
        $this->assertContains('soak_validation', $ids);
        $this->assertContains('replay_verification', $ids);
        $this->assertContains('tenant_isolation', $ids);
        $this->assertContains('rollback_readiness', $ids);
    }

    // =========================================================================
    // Self-approve block (2 tests)
    // =========================================================================

    public function test_self_approve_blocked_exits_1(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-same',
            '--approved-by' => 'op-same',
        ])->assertExitCode(1);
    }

    public function test_different_operator_and_approver_allowed(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ])->assertExitCode(0);
    }

    // =========================================================================
    // Dry-run mode (3 tests)
    // =========================================================================

    public function test_dry_run_exits_zero(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
            '--dry-run'    => true,
        ])->assertExitCode(0);
    }

    public function test_dry_run_writes_no_matrix_run(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
            '--dry-run'    => true,
        ]);

        $this->assertDatabaseCount('pilot_readiness_matrix_runs', 0);
    }

    public function test_dry_run_writes_no_gate_evaluations(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
            '--dry-run'    => true,
        ]);

        $this->assertDatabaseCount('pilot_readiness_gate_evaluations', 0);
    }

    // =========================================================================
    // Normal run — DB records (8 tests)
    // =========================================================================

    public function test_normal_run_exits_zero(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ])->assertExitCode(0);
    }

    public function test_normal_run_creates_one_matrix_run(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseCount('pilot_readiness_matrix_runs', 1);
    }

    public function test_normal_run_matrix_run_is_advisory(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $run = PilotReadinessMatrixRun::first();
        $this->assertTrue((bool) $run->is_advisory);
    }

    public function test_normal_run_autonomous_promotion_false(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $run = PilotReadinessMatrixRun::first();
        $this->assertFalse((bool) $run->autonomous_promotion);
    }

    public function test_normal_run_creates_gate_evaluations(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $count = PilotReadinessGateEvaluation::count();
        $this->assertGreaterThanOrEqual(4, $count);
    }

    public function test_normal_run_required_gates_are_recorded(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $run = PilotReadinessMatrixRun::first();
        foreach (EnterprisePilotReadinessMatrixService::REQUIRED_GATE_IDS as $gateId) {
            $this->assertDatabaseHas('pilot_readiness_gate_evaluations', [
                'matrix_run_id' => $run->matrix_run_id,
                'gate_id'       => $gateId,
                'status'        => 'pass',
            ]);
        }
    }

    public function test_normal_run_creates_evidence_links(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $count = PilotReadinessEvidenceLink::count();
        $this->assertGreaterThanOrEqual(4, $count);
    }

    public function test_normal_run_evidence_links_match_gate_evaluations(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $gateCount     = PilotReadinessGateEvaluation::count();
        $evidenceCount = PilotReadinessEvidenceLink::count();
        $this->assertEquals($gateCount, $evidenceCount);
    }

    // =========================================================================
    // Gate dimensions (3 tests)
    // =========================================================================

    public function test_easm_gate_has_easm_dimension(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseHas('pilot_readiness_gate_evaluations', [
            'gate_id'   => 'easm_posture_readiness',
            'dimension' => 'easm',
        ]);
    }

    public function test_obs_gate_has_technical_dimension(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseHas('pilot_readiness_gate_evaluations', [
            'gate_id'   => 'obs_slo_readiness',
            'dimension' => 'technical',
        ]);
    }

    public function test_pilot_matrix_gate_has_certification_dimension(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseHas('pilot_readiness_gate_evaluations', [
            'gate_id'   => 'pilot_matrix_validator',
            'dimension' => 'certification',
        ]);
    }

    // =========================================================================
    // --list option (2 tests)
    // =========================================================================

    public function test_list_exits_zero_when_no_runs(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--list' => true,
        ])->assertExitCode(0);
    }

    public function test_list_exits_zero_with_existing_runs(): void
    {
        // Create a run first.
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->artisan('pilot:evidence-freeze', [
            '--list' => true,
        ])->assertExitCode(0);
    }

    // =========================================================================
    // Tenant isolation (2 tests)
    // =========================================================================

    public function test_tenant_option_is_stored_on_matrix_run(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
            '--tenant'     => 'tenant-acme',
        ]);

        $this->assertDatabaseHas('pilot_readiness_matrix_runs', [
            'tenant_id' => 'tenant-acme',
        ]);
    }

    public function test_default_tenant_is_system(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseHas('pilot_readiness_matrix_runs', [
            'tenant_id' => 'system',
        ]);
    }

    // =========================================================================
    // Append-only safety (3 tests)
    // =========================================================================

    public function test_gate_evaluation_model_is_append_only(): void
    {
        $eval = new PilotReadinessGateEvaluation();
        $this->expectException(\LogicException::class);
        $eval->exists = true;
        $eval->save();
    }

    public function test_evidence_link_model_is_append_only(): void
    {
        $link = new PilotReadinessEvidenceLink();
        $this->expectException(\LogicException::class);
        $link->exists = true;
        $link->save();
    }

    public function test_matrix_run_delete_is_forbidden(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $run = PilotReadinessMatrixRun::first();
        $this->assertNotNull($run);
        $this->expectException(\LogicException::class);
        $run->delete();
    }

    // =========================================================================
    // No side-effects (3 tests)
    // =========================================================================

    public function test_freeze_does_not_create_security_alerts(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseCount('security_alerts', 0);
    }

    public function test_freeze_does_not_create_security_incidents(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_freeze_does_not_create_advisory_findings(): void
    {
        $this->artisan('pilot:evidence-freeze', [
            '--operator'   => 'op-alice',
            '--approved-by' => 'op-bob',
        ]);

        $this->assertDatabaseCount('advisory_findings', 0);
    }
}
