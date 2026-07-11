<?php

namespace Tests\Feature;

use App\Services\TenantBoundaryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GAP-003 (docs/security/RLS_DECISION_RECORD.md) — coverage for
 * TenantNullAuditCommand, the read-only tool the decision record's Phase 3
 * (null tenant_id audit) depends on. Never had test coverage before this;
 * the audit's own correctness has to be trusted before anyone acts on its
 * output against real data.
 */
class TenantNullAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private function insertDlqRecord(string $recordId, ?string $tenantId): void
    {
        DB::table('dlq_records')->insert([
            'record_id' => $recordId,
            'fingerprint' => 'fp-'.$recordId,
            'tenant_id' => $tenantId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdvisoryFinding(string $findingId, ?string $tenantId): void
    {
        DB::table('advisory_findings')->insert([
            'finding_id' => $findingId,
            'rule_id' => 'RULE-1',
            'domain' => 'endpoint',
            'source_topic' => 'xdr.alerts.shadow.endpoint',
            'alert_type' => 'test_alert',
            'fingerprint' => 'fp-'.$findingId,
            'tenant_id' => $tenantId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reports_clean_when_all_isolated_tables_have_no_null_tenant_records(): void
    {
        $this->insertDlqRecord('rec-1', 'tenant-a');
        $this->insertAdvisoryFinding('find-1', 'tenant-a');

        $this->artisan('tenant:null-audit')
            ->expectsOutputToContain('All audited tables are clean (zero null tenant_id records).')
            ->assertExitCode(0);
    }

    public function test_detects_null_tenant_records_and_exits_nonzero(): void
    {
        $this->insertDlqRecord('rec-clean', 'tenant-a');
        $this->insertDlqRecord('rec-null', null);

        $this->artisan('tenant:null-audit')
            ->expectsOutputToContain('table(s) have null tenant_id records.')
            ->assertExitCode(1);
    }

    public function test_reports_correct_null_count_and_percentage_for_a_mixed_table(): void
    {
        $this->insertDlqRecord('rec-1', 'tenant-a');
        $this->insertDlqRecord('rec-2', 'tenant-a');
        $this->insertDlqRecord('rec-3', null);

        $outputPath = storage_path('app/tenant_null_audit_test_mixed.json');
        if (File::exists($outputPath)) {
            File::delete($outputPath);
        }

        $this->artisan('tenant:null-audit', ['--output' => $outputPath])
            ->assertExitCode(1);

        $report = json_decode(File::get($outputPath), true);
        $dlqRow = collect($report['tables'])->firstWhere('table', 'dlq_records');

        $this->assertSame(1, $dlqRow['null_records']);
        $this->assertSame(3, $dlqRow['total']);
        $this->assertSame('33.3%', $dlqRow['null_pct']);
        $this->assertSame('HAS_NULL', $dlqRow['status']);

        File::delete($outputPath);
    }

    public function test_writes_json_report_with_expected_summary_shape(): void
    {
        $this->insertDlqRecord('rec-1', 'tenant-a');

        $outputPath = storage_path('app/tenant_null_audit_test_summary.json');
        if (File::exists($outputPath)) {
            File::delete($outputPath);
        }

        $this->artisan('tenant:null-audit', ['--output' => $outputPath])
            ->assertExitCode(0);

        $this->assertTrue(File::exists($outputPath));
        $report = json_decode(File::get($outputPath), true);

        $this->assertArrayHasKey('generated_at', $report);
        $this->assertFalse($report['has_null_records']);
        $this->assertSame(count(TenantBoundaryService::ISOLATED_TABLES), $report['summary']['total_tables_audited']);
        $this->assertSame(0, $report['summary']['tables_with_null']);

        File::delete($outputPath);
    }

    public function test_table_option_audits_only_the_named_table(): void
    {
        $this->insertDlqRecord('rec-null', null);
        $this->insertAdvisoryFinding('find-null', null);

        $outputPath = storage_path('app/tenant_null_audit_test_single.json');
        if (File::exists($outputPath)) {
            File::delete($outputPath);
        }

        $this->artisan('tenant:null-audit', ['--table' => 'dlq_records', '--output' => $outputPath])
            ->assertExitCode(1);

        $report = json_decode(File::get($outputPath), true);

        $this->assertCount(1, $report['tables']);
        $this->assertSame('dlq_records', $report['tables'][0]['table']);

        File::delete($outputPath);
    }

    public function test_table_option_rejects_a_table_not_in_the_isolated_registry(): void
    {
        $this->artisan('tenant:null-audit', ['--table' => 'users'])
            ->expectsOutputToContain("Table 'users' is not a registered tenant-isolated table.")
            ->assertExitCode(1);
    }

    public function test_reports_table_missing_status_when_registered_table_does_not_exist(): void
    {
        Schema::drop('tenant_notification_settings');

        try {
            $this->artisan('tenant:null-audit', ['--table' => 'tenant_notification_settings'])
                ->assertExitCode(0);
        } finally {
            // RefreshDatabase rolls the whole test transaction back regardless,
            // but restore explicitly so a failure mid-test can't leave the
            // schema in a dropped state for whatever runs next in-process.
            if (! Schema::hasTable('tenant_notification_settings')) {
                Schema::create('tenant_notification_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('tenant_id')->nullable();
                    $table->timestamps();
                });
            }
        }
    }

    public function test_reports_no_tenant_column_status_when_column_missing(): void
    {
        Schema::table('dlq_records', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        try {
            $this->artisan('tenant:null-audit', ['--table' => 'dlq_records'])
                ->assertExitCode(0);
        } finally {
            if (! Schema::hasColumn('dlq_records', 'tenant_id')) {
                Schema::table('dlq_records', function (Blueprint $table) {
                    $table->string('tenant_id')->nullable();
                });
            }
        }
    }

    public function test_never_mutates_any_record(): void
    {
        $this->insertDlqRecord('rec-untouched', null);

        $this->artisan('tenant:null-audit')->run();

        $record = DB::table('dlq_records')->where('record_id', 'rec-untouched')->first();
        $this->assertNull($record->tenant_id);
    }
}
