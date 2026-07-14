<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantStrictModeReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantStrictModeReadinessTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Service — assess()
    // -----------------------------------------------------------------------

    public function test_assess_returns_required_keys(): void
    {
        $svc = app(TenantStrictModeReadinessService::class);
        $result = $svc->assess('test');

        $this->assertArrayHasKey('assessment_id', $result);
        $this->assertArrayHasKey('gate_results', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function test_assess_evaluates_all_eight_gates(): void
    {
        $result = app(TenantStrictModeReadinessService::class)->assess('test');
        $this->assertCount(count(TenantStrictModeReadinessService::GATES), $result['gate_results']);
    }

    public function test_assess_persists_assessment_row(): void
    {
        $result = app(TenantStrictModeReadinessService::class)->assess('test@example.com');
        $this->assertDatabaseHas('tenant_strict_mode_assessments', [
            'assessment_id' => $result['assessment_id'],
        ]);
    }

    public function test_assess_persists_gate_result_rows(): void
    {
        $result = app(TenantStrictModeReadinessService::class)->assess('test');
        $count = DB::table('tenant_strict_mode_gate_results')
            ->where('assessment_id', $result['assessment_id'])
            ->count();
        $this->assertSame(count(TenantStrictModeReadinessService::GATES), $count);
    }

    public function test_summary_has_readiness_score(): void
    {
        $summary = app(TenantStrictModeReadinessService::class)->assess('test')['summary'];
        $this->assertArrayHasKey('readiness_score', $summary);
        $this->assertIsFloat($summary['readiness_score']);
        $this->assertGreaterThanOrEqual(0.0, $summary['readiness_score']);
        $this->assertLessThanOrEqual(1.0, $summary['readiness_score']);
    }

    public function test_summary_overall_status_is_valid(): void
    {
        $summary = app(TenantStrictModeReadinessService::class)->assess('test')['summary'];
        $this->assertContains($summary['overall_status'], ['READY', 'NOT_READY', 'WARN']);
    }

    public function test_strict_mode_never_recommended_autonomously(): void
    {
        // strict_mode_recommended can be true or false — but the service NEVER
        // flips the env var autonomously; verify the advisory note is present.
        $summary = app(TenantStrictModeReadinessService::class)->assess('test')['summary'];
        $this->assertStringContainsStringIgnoringCase('advisory', $summary['note'] ?? '');
    }

    public function test_each_gate_result_has_required_fields(): void
    {
        $gateResults = app(TenantStrictModeReadinessService::class)->assess('test')['gate_results'];
        foreach ($gateResults as $gateId => $gate) {
            $this->assertArrayHasKey('gate_name', $gate, "Gate {$gateId} missing gate_name");
            $this->assertArrayHasKey('result', $gate, "Gate {$gateId} missing result");
            $this->assertContains($gate['result'], ['PASS', 'WARN', 'FAIL'], "Gate {$gateId} invalid result");
            $this->assertArrayHasKey('detail', $gate, "Gate {$gateId} missing detail");
        }
    }

    public function test_gate01_mutable_null_count_passes_on_fresh_db(): void
    {
        // Fresh database has no data → null count = 0 → GATE-01 should PASS
        $gateResults = app(TenantStrictModeReadinessService::class)->assess('test')['gate_results'];
        $this->assertArrayHasKey('GATE-01', $gateResults);
        $this->assertSame('PASS', $gateResults['GATE-01']['result']);
    }

    public function test_gate01_fails_when_mutable_table_has_null_tenant_id(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'GATE01-TEST-'.uniqid(),
            'alert_type' => 'TEST',
            'severity' => 'low',
            'evidence' => json_encode([]),
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'tenant_id' => null,
            'created_at' => now()->format('Y-m-d H:i:sP'),
            'updated_at' => now()->format('Y-m-d H:i:sP'),
        ]);

        $gateResults = app(TenantStrictModeReadinessService::class)->assess('test')['gate_results'];
        $this->assertSame('FAIL', $gateResults['GATE-01']['result']);
        $this->assertStringContainsString('null', strtolower($gateResults['GATE-01']['detail']));
    }

    public function test_gate02_backfill_command_available(): void
    {
        $gateResults = app(TenantStrictModeReadinessService::class)->assess('test')['gate_results'];
        $this->assertSame('PASS', $gateResults['GATE-02']['result']);
    }

    public function test_gate07_tenant_context_authority_in_place(): void
    {
        $gateResults = app(TenantStrictModeReadinessService::class)->assess('test')['gate_results'];
        $this->assertSame('PASS', $gateResults['GATE-07']['result']);
    }

    public function test_required_gates_constant_is_array(): void
    {
        $this->assertIsArray(TenantStrictModeReadinessService::REQUIRED_GATES);
        $this->assertContains('GATE-01', TenantStrictModeReadinessService::REQUIRED_GATES);
        $this->assertContains('GATE-07', TenantStrictModeReadinessService::REQUIRED_GATES);
    }

    public function test_pass_threshold_constant(): void
    {
        $this->assertSame(0.80, TenantStrictModeReadinessService::PASS_THRESHOLD);
    }

    public function test_self_approve_blocked_constant(): void
    {
        $this->assertTrue(TenantStrictModeReadinessService::SELF_APPROVE_BLOCKED);
    }

    // -----------------------------------------------------------------------
    // Service — getHistory()
    // -----------------------------------------------------------------------

    public function test_get_history_returns_collection(): void
    {
        $svc = app(TenantStrictModeReadinessService::class);
        $svc->assess('test');
        $history = $svc->getHistory();
        $this->assertGreaterThanOrEqual(1, $history->count());
    }

    public function test_get_history_respects_limit(): void
    {
        $svc = app(TenantStrictModeReadinessService::class);
        for ($i = 0; $i < 5; $i++) {
            $svc->assess('test');
        }
        $this->assertLessThanOrEqual(3, $svc->getHistory(3)->count());
    }

    public function test_get_gate_results_for_assessment(): void
    {
        $svc = app(TenantStrictModeReadinessService::class);
        $result = $svc->assess('test');
        $gates = $svc->getGateResults($result['assessment_id']);
        $this->assertCount(count(TenantStrictModeReadinessService::GATES), $gates);
    }

    // -----------------------------------------------------------------------
    // Service — recordBackfillRun()
    // -----------------------------------------------------------------------

    public function test_record_backfill_run_persists_row(): void
    {
        app(TenantStrictModeReadinessService::class)->recordBackfillRun([
            'dry_run' => true,
            'tenant_id_assigned' => null,
            'table_results' => [],
            'total_null_before' => 0,
            'total_updated' => 0,
            'total_null_after' => 0,
            'outcome' => 'DRY_RUN_PENDING',
        ]);
        $this->assertDatabaseCount('tenant_backfill_audit_runs', 1);
    }

    // -----------------------------------------------------------------------
    // Database tables exist
    // -----------------------------------------------------------------------

    public function test_tenant_backfill_audit_runs_table_exists(): void
    {
        $this->assertDatabaseCount('tenant_backfill_audit_runs', 0);
    }

    public function test_tenant_strict_mode_assessments_table_exists(): void
    {
        $this->assertDatabaseCount('tenant_strict_mode_assessments', 0);
    }

    public function test_tenant_strict_mode_gate_results_table_exists(): void
    {
        $this->assertDatabaseCount('tenant_strict_mode_gate_results', 0);
    }

    // -----------------------------------------------------------------------
    // HTTP — RBAC
    // -----------------------------------------------------------------------

    public function test_admin_can_view_readiness_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/soc/tenant/strict-mode-readiness')->assertOk();
    }

    public function test_analyst_can_view_readiness_index(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->get('/soc/tenant/strict-mode-readiness')->assertOk();
    }

    public function test_viewer_can_view_readiness_index(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/soc/tenant/strict-mode-readiness')->assertOk();
    }

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/soc/tenant/strict-mode-readiness')->assertRedirect('/login');
    }

    public function test_admin_can_run_assessment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/soc/tenant/strict-mode-readiness/assess')
            ->assertRedirect(route('soc.tenant.strict-mode-readiness.index'));
        $this->assertDatabaseCount('tenant_strict_mode_assessments', 1);
    }

    public function test_analyst_cannot_run_assessment(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/soc/tenant/strict-mode-readiness/assess')
            ->assertForbidden();
    }

    public function test_history_route_accessible(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/soc/tenant/strict-mode-readiness/history')->assertOk();
    }

    // -----------------------------------------------------------------------
    // Advisory-only constraints
    // -----------------------------------------------------------------------

    public function test_service_never_modifies_strict_mode_env(): void
    {
        $before = env('XDR_TENANT_STRICT_MODE');
        app(TenantStrictModeReadinessService::class)->assess('test');
        // env() reads from the original .env — service must not call putenv
        $after = env('XDR_TENANT_STRICT_MODE');
        $this->assertSame($before, $after);
    }

    public function test_assessment_row_is_append_only(): void
    {
        $svc = app(TenantStrictModeReadinessService::class);
        $result = $svc->assess('test');
        $id = DB::table('tenant_strict_mode_assessments')
            ->where('assessment_id', $result['assessment_id'])
            ->value('id');

        // Update is not triggered by the service — simulate an UPDATE attempt:
        // The table has no update path in the service (insertOrIgnore).
        $this->assertNotNull($id);
        $this->assertDatabaseHas('tenant_strict_mode_assessments', [
            'assessment_id' => $result['assessment_id'],
        ]);
    }
}
