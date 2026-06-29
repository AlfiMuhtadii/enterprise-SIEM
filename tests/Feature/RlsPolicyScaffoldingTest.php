<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-069 — RLS Policy Scaffolding (advisory, zero-enforcement) tests.
 */
class RlsPolicyScaffoldingTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Migration artifact checks (offline-file assertions)
    // -------------------------------------------------------------------------

    public function test_rls_scaffold_migration_file_exists(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations, 'RLS scaffold migration should exist in database/migrations/');
    }

    public function test_migration_uses_do_block_idempotency(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('DO $$', $content);
        $this->assertStringContainsString('pg_policies', $content);
    }

    public function test_migration_does_not_execute_rls_enforcement(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $content = file_get_contents($migrations[0]);
        // Must not contain the ALTER TABLE ... ENABLE ROW LEVEL SECURITY execution pattern.
        // Comments referencing "enforcement" are acceptable — we check the actual SQL.
        $this->assertDoesNotMatchRegularExpression(
            '/ALTER\s+TABLE.*ENABLE\s+ROW\s+LEVEL\s+SECURITY/si',
            $content,
            'RLS scaffold must NOT execute ALTER TABLE ... ENABLE ROW LEVEL SECURITY'
        );
    }

    public function test_migration_guards_pgsql_driver(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('pgsql', $content);
        $this->assertStringContainsString('getDriverName', $content);
    }

    public function test_migration_covers_security_alerts(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $this->assertStringContainsString('security_alerts', file_get_contents($migrations[0]));
    }

    public function test_migration_covers_security_incidents(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $this->assertStringContainsString('security_incidents', file_get_contents($migrations[0]));
    }

    public function test_migration_down_drops_policies(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $this->assertStringContainsString('DROP POLICY IF EXISTS', file_get_contents($migrations[0]));
    }

    public function test_migration_uses_current_setting_for_tenant(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        $this->assertNotEmpty($migrations);
        $content = file_get_contents($migrations[0]);
        $this->assertStringContainsString('current_setting', $content);
        $this->assertStringContainsString('app.tenant_id', $content);
    }

    // -------------------------------------------------------------------------
    // TenantRlsStatusCommand (advisory read-only)
    // -------------------------------------------------------------------------

    public function test_rls_status_command_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Console\Commands\TenantRlsStatusCommand::class),
            'TenantRlsStatusCommand should be registered'
        );
    }

    public function test_rls_status_command_is_read_only(): void
    {
        $path    = app_path('Console/Commands/TenantRlsStatusCommand.php');
        $content = file_get_contents($path);
        $this->assertStringNotContainsString(
            'ENABLE ROW LEVEL SECURITY',
            $content,
            'TenantRlsStatusCommand must never enable RLS'
        );
    }

    public function test_rls_status_command_reports_advisory(): void
    {
        $path    = app_path('Console/Commands/TenantRlsStatusCommand.php');
        $content = file_get_contents($path);
        $this->assertStringContainsString('Advisory', $content);
    }

    public function test_rls_status_command_runs_on_sqlite_without_error(): void
    {
        $this->artisan('tenant:rls-status')->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // TenantBoundaryService posture
    // -------------------------------------------------------------------------

    public function test_tenant_boundary_service_rls_disabled(): void
    {
        $this->assertFalse(
            \App\Services\TenantBoundaryService::RLS_ENABLED,
            'TenantBoundaryService::RLS_ENABLED must remain false — advisory posture only'
        );
    }

    // -------------------------------------------------------------------------
    // TenantStrictModeReadinessService GATE-08
    // -------------------------------------------------------------------------

    public function test_strict_mode_readiness_has_gate_08(): void
    {
        $gates = \App\Services\TenantStrictModeReadinessService::GATES;
        $this->assertArrayHasKey('GATE-08', $gates);
    }

    public function test_gate_08_evaluates_rls_scaffold(): void
    {
        $result = app(\App\Services\TenantStrictModeReadinessService::class)->assess('rls-test');
        $this->assertArrayHasKey('GATE-08', $result['gate_results']);
        $gate = $result['gate_results']['GATE-08'];
        $this->assertContains($gate['result'], ['PASS', 'WARN', 'FAIL']);
    }

    public function test_gate_08_passes_when_migration_present(): void
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*'));
        if (empty($migrations)) {
            $this->markTestSkipped('RLS scaffold migration not present');
        }
        $result = app(\App\Services\TenantStrictModeReadinessService::class)->assess('rls-test');
        $this->assertSame('PASS', $result['gate_results']['GATE-08']['result']);
    }

    // -------------------------------------------------------------------------
    // Docs
    // -------------------------------------------------------------------------

    public function test_rls_decision_record_doc_exists(): void
    {
        $this->assertFileExists(
            base_path('docs/security/RLS_DECISION_RECORD.md'),
            'RLS_DECISION_RECORD.md must exist for GATE-03 to pass'
        );
    }
}
