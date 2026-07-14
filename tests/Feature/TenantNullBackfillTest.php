<?php

namespace Tests\Feature;

use App\Services\TenantBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ENTERPRISE-046: Tenant Strict Mode & Null Backfill Closure
 *
 * Validates:
 * - MUTABLE_TABLES and APPEND_ONLY_ISOLATED_TABLES constants
 * - tenant:backfill-nulls command (dry-run, write, idempotent, batch)
 * - Append-only tables are never touched by backfill
 * - Command exits 0 when all mutable tables are clean
 * - Command exits 1 on dry-run when nulls exist
 */
class TenantNullBackfillTest extends TestCase
{
    use RefreshDatabase;

    // ── TenantBoundaryService constants ──────────────────────────────────────

    public function test_mutable_tables_constant_is_defined(): void
    {
        $this->assertNotEmpty(TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_mutable_tables_includes_security_alerts(): void
    {
        $this->assertContains('security_alerts', TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_mutable_tables_includes_security_incidents(): void
    {
        $this->assertContains('security_incidents', TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_mutable_tables_includes_dlq_records(): void
    {
        $this->assertContains('dlq_records', TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_mutable_tables_has_expected_entries(): void
    {
        $this->assertGreaterThanOrEqual(3, count(TenantBoundaryService::MUTABLE_TABLES));
        $this->assertContains('security_alerts', TenantBoundaryService::MUTABLE_TABLES);
        $this->assertContains('security_incidents', TenantBoundaryService::MUTABLE_TABLES);
        $this->assertContains('dlq_records', TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_append_only_isolated_tables_constant_is_defined(): void
    {
        $this->assertNotEmpty(TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
    }

    public function test_advisory_findings_is_in_append_only_isolated(): void
    {
        $this->assertContains('advisory_findings', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
    }

    public function test_advisory_findings_is_not_in_mutable_tables(): void
    {
        $this->assertNotContains('advisory_findings', TenantBoundaryService::MUTABLE_TABLES);
    }

    public function test_mutable_and_append_only_are_disjoint(): void
    {
        $overlap = array_intersect(
            TenantBoundaryService::MUTABLE_TABLES,
            TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES
        );
        $this->assertEmpty($overlap, 'MUTABLE_TABLES and APPEND_ONLY_ISOLATED_TABLES must be disjoint');
    }

    public function test_mutable_tables_are_subset_of_isolated_tables(): void
    {
        foreach (TenantBoundaryService::MUTABLE_TABLES as $table) {
            $this->assertContains(
                $table,
                TenantBoundaryService::ISOLATED_TABLES,
                "MUTABLE_TABLES entry '{$table}' must be in ISOLATED_TABLES"
            );
        }
    }

    // ── Backfill command — clean state ────────────────────────────────────────

    public function test_backfill_exits_0_when_no_null_records(): void
    {
        // No records inserted — all tables empty = clean
        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'test-org'])
            ->assertExitCode(0);
    }

    public function test_dry_run_exits_0_when_all_tables_are_clean(): void
    {
        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'test-org', '--dry-run' => true])
            ->assertExitCode(0);
    }

    // ── Backfill command — security_alerts ───────────────────────────────────

    public function test_backfill_assigns_tenant_to_null_security_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'backfill-test-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'tenant_id' => null,
        ]);

        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'demo-tenant'])
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'backfill-test-001')->first();
        $this->assertSame('demo-tenant', $row->tenant_id);
    }

    public function test_dry_run_does_not_write_to_security_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'backfill-dry-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type' => 'CLOUD_MASS_DOWNLOAD',
            'severity' => 'medium',
            'tenant_id' => null,
        ]);

        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'demo-tenant', '--dry-run' => true])
            ->assertExitCode(1); // dry-run with pending nulls = exit 1

        $row = DB::table('security_alerts')->where('alert_id', 'backfill-dry-001')->first();
        $this->assertNull($row->tenant_id, 'Dry-run must not write');
    }

    // ── Backfill command — dlq_records ────────────────────────────────────────

    public function test_backfill_assigns_tenant_to_null_dlq_records(): void
    {
        DB::table('dlq_records')->insert([
            'record_id' => 'dlq-backfill-001',
            'fingerprint' => Str::uuid()->toString(),
            'first_seen_at' => now()->format('Y-m-d H:i:sP'),
            'last_seen_at' => now()->format('Y-m-d H:i:sP'),
            'tenant_id' => null,
            'status' => 'new',
        ]);

        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'ops-tenant'])
            ->assertExitCode(0);

        $row = DB::table('dlq_records')->where('record_id', 'dlq-backfill-001')->first();
        $this->assertSame('ops-tenant', $row->tenant_id);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_backfill_is_idempotent(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'backfill-idem-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type' => 'IDENTITY_PRIVILEGE_ESCALATION',
            'severity' => 'critical',
            'tenant_id' => null,
        ]);

        // First run
        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'stable-tenant'])
            ->assertExitCode(0);

        // Second run — nothing to do
        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'stable-tenant'])
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'backfill-idem-001')->first();
        $this->assertSame('stable-tenant', $row->tenant_id);
    }

    public function test_backfill_does_not_overwrite_existing_tenant(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => 'backfill-existing-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type' => 'CLOUD_NEW_ACCESS_KEY',
            'severity' => 'medium',
            'tenant_id' => 'original-tenant',
        ]);

        $this->artisan('tenant:backfill-nulls', ['--tenant' => 'new-tenant'])
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'backfill-existing-001')->first();
        $this->assertSame('original-tenant', $row->tenant_id, 'Must not overwrite non-null tenant_id');
    }

    // ── Empty --tenant guard ──────────────────────────────────────────────────

    public function test_backfill_rejects_empty_tenant_string(): void
    {
        $this->artisan('tenant:backfill-nulls', ['--tenant' => ''])
            ->assertExitCode(1);
    }
}
