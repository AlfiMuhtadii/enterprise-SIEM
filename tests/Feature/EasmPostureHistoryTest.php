<?php

namespace Tests\Feature;

use App\Models\EasmAssetRiskScore;
use App\Models\EasmFinding;
use App\Models\EasmFindingChange;
use App\Models\EasmPostureSnapshot;
use App\Models\EasmScanRun;
use App\Models\WebsiteAsset;
use App\Services\EasmPassiveScanService;
use App\Services\EasmPostureHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EasmPostureHistoryTest extends TestCase
{
    use RefreshDatabase;

    private EasmPostureHistoryService $svc;
    private WebsiteAsset $asset;
    private EasmScanRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EasmPostureHistoryService::class);

        $this->asset = WebsiteAsset::create([
            'tenant_id'   => 'tenant-1',
            'name'        => 'Test Site',
            'url'         => 'https://example.com',
            'hostname'    => 'example.com',
            'scheme'      => 'https',
            'scan_policy' => 'passive',
            'status'      => 'active',
        ]);

        $this->run = EasmScanRun::create([
            'tenant_id'       => 'tenant-1',
            'asset_id'        => $this->asset->id,
            'run_id'          => 'run-test-001',
            'scan_policy'     => 'passive',
            'target_hostname' => 'example.com',
            'target_url'      => 'https://example.com',
            'status'          => 'completed',
            'started_at'      => now()->format('Y-m-d H:i:sP'),
            'finished_at'     => now()->format('Y-m-d H:i:sP'),
            'finding_count'   => 0,
            'checks_run'      => ['dns', 'tls'],
            'is_dry_run'      => false,
        ]);
    }

    // =========================================================================
    // Advisory-only (5 tests)
    // =========================================================================

    public function test_advisory_only_constant(): void
    {
        $this->assertTrue(EasmPostureHistoryService::ADVISORY_ONLY);
    }

    public function test_no_create_security_incident(): void
    {
        $this->assertFalse(method_exists($this->svc, 'createSecurityIncident'));
    }

    public function test_no_active_scan(): void
    {
        $this->assertFalse(method_exists($this->svc, 'activeScan'));
    }

    public function test_no_exploit_payload(): void
    {
        $this->assertFalse(method_exists($this->svc, 'injectExploit'));
    }

    public function test_trend_report_is_advisory_true(): void
    {
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertTrue($report['is_advisory']);
    }

    // =========================================================================
    // Posture score (8 tests)
    // =========================================================================

    public function test_score_empty_findings_is_100(): void
    {
        $this->assertSame(100, $this->svc->computePostureScore([]));
    }

    public function test_score_single_high_deducts_20(): void
    {
        $findings = [['severity' => 'high']];
        $this->assertSame(80, $this->svc->computePostureScore($findings));
    }

    public function test_score_single_medium_deducts_10(): void
    {
        $findings = [['severity' => 'medium']];
        $this->assertSame(90, $this->svc->computePostureScore($findings));
    }

    public function test_score_single_low_deducts_3(): void
    {
        $findings = [['severity' => 'low']];
        $this->assertSame(97, $this->svc->computePostureScore($findings));
    }

    public function test_score_single_info_deducts_1(): void
    {
        $findings = [['severity' => 'info']];
        $this->assertSame(99, $this->svc->computePostureScore($findings));
    }

    public function test_score_mixed_findings(): void
    {
        $findings = [
            ['severity' => 'high'],
            ['severity' => 'medium'],
            ['severity' => 'low'],
            ['severity' => 'info'],
        ];
        $this->assertSame(66, $this->svc->computePostureScore($findings));
    }

    public function test_score_does_not_go_below_zero(): void
    {
        $findings = array_fill(0, 10, ['severity' => 'high']);
        $this->assertSame(0, $this->svc->computePostureScore($findings));
    }

    public function test_score_unknown_severity_is_zero_deduction(): void
    {
        $findings = [['severity' => 'unknown']];
        $this->assertSame(100, $this->svc->computePostureScore($findings));
    }

    // =========================================================================
    // Risk tier (4 tests)
    // =========================================================================

    public function test_tier_low_for_score_80_to_100(): void
    {
        $this->assertSame('low', $this->svc->classifyRiskTier(100));
        $this->assertSame('low', $this->svc->classifyRiskTier(80));
    }

    public function test_tier_medium_for_score_60_to_79(): void
    {
        $this->assertSame('medium', $this->svc->classifyRiskTier(79));
        $this->assertSame('medium', $this->svc->classifyRiskTier(60));
    }

    public function test_tier_high_for_score_40_to_59(): void
    {
        $this->assertSame('high', $this->svc->classifyRiskTier(59));
        $this->assertSame('high', $this->svc->classifyRiskTier(40));
    }

    public function test_tier_critical_below_40(): void
    {
        $this->assertSame('critical', $this->svc->classifyRiskTier(39));
        $this->assertSame('critical', $this->svc->classifyRiskTier(0));
    }

    // =========================================================================
    // Trend direction (3 tests)
    // =========================================================================

    public function test_trend_stable_when_no_previous_score(): void
    {
        $this->assertSame('stable', $this->svc->determineTrendDirection(80, null));
    }

    public function test_trend_improving_when_score_increased(): void
    {
        $this->assertSame('improving', $this->svc->determineTrendDirection(90, 70));
    }

    public function test_trend_degrading_when_score_decreased(): void
    {
        $this->assertSame('degrading', $this->svc->determineTrendDirection(60, 80));
    }

    // =========================================================================
    // Finding diff (8 tests)
    // =========================================================================

    public function test_diff_new_finding_when_not_in_previous(): void
    {
        $diffs = $this->svc->diffFindings([], [['finding_key' => 'tls_expired', 'severity' => 'high']]);
        $this->assertCount(1, $diffs);
        $this->assertSame('new', $diffs[0]['change_type']);
        $this->assertNull($diffs[0]['severity_before']);
        $this->assertSame('high', $diffs[0]['severity_after']);
    }

    public function test_diff_resolved_finding_when_not_in_current(): void
    {
        $diffs = $this->svc->diffFindings([['finding_key' => 'tls_expired', 'severity' => 'high']], []);
        $this->assertCount(1, $diffs);
        $this->assertSame('resolved', $diffs[0]['change_type']);
        $this->assertSame('high', $diffs[0]['severity_before']);
        $this->assertNull($diffs[0]['severity_after']);
    }

    public function test_diff_unchanged_finding_same_severity(): void
    {
        $prev = [['finding_key' => 'missing_hsts', 'severity' => 'medium']];
        $curr = [['finding_key' => 'missing_hsts', 'severity' => 'medium']];
        $diffs = $this->svc->diffFindings($prev, $curr);
        $this->assertCount(1, $diffs);
        $this->assertSame('unchanged', $diffs[0]['change_type']);
    }

    public function test_diff_regressed_finding_severity_increased(): void
    {
        $prev = [['finding_key' => 'missing_hsts', 'severity' => 'low']];
        $curr = [['finding_key' => 'missing_hsts', 'severity' => 'high']];
        $diffs = $this->svc->diffFindings($prev, $curr);
        $this->assertCount(1, $diffs);
        $this->assertSame('regressed', $diffs[0]['change_type']);
        $this->assertSame('low', $diffs[0]['severity_before']);
        $this->assertSame('high', $diffs[0]['severity_after']);
    }

    public function test_diff_empty_both_returns_empty(): void
    {
        $diffs = $this->svc->diffFindings([], []);
        $this->assertCount(0, $diffs);
    }

    public function test_diff_mixed_changes(): void
    {
        $prev = [
            ['finding_key' => 'tls_expired', 'severity' => 'high'],
            ['finding_key' => 'missing_csp', 'severity' => 'medium'],
        ];
        $curr = [
            ['finding_key' => 'missing_csp', 'severity' => 'medium'],
            ['finding_key' => 'missing_hsts', 'severity' => 'low'],
        ];
        $diffs = $this->svc->diffFindings($prev, $curr);
        $types = array_column($diffs, 'change_type');
        $this->assertContains('resolved', $types);
        $this->assertContains('unchanged', $types);
        $this->assertContains('new', $types);
        $this->assertCount(3, $diffs);
    }

    public function test_diff_all_resolved_when_current_empty(): void
    {
        $prev = [
            ['finding_key' => 'tls_expired', 'severity' => 'high'],
            ['finding_key' => 'missing_csp', 'severity' => 'medium'],
        ];
        $diffs = $this->svc->diffFindings($prev, []);
        $this->assertCount(2, $diffs);
        foreach ($diffs as $d) {
            $this->assertSame('resolved', $d['change_type']);
        }
    }

    public function test_diff_severity_decrease_is_unchanged_not_regressed(): void
    {
        $prev = [['finding_key' => 'missing_hsts', 'severity' => 'high']];
        $curr = [['finding_key' => 'missing_hsts', 'severity' => 'low']];
        $diffs = $this->svc->diffFindings($prev, $curr);
        $this->assertSame('unchanged', $diffs[0]['change_type']);
    }

    // =========================================================================
    // Posture snapshot (5 tests)
    // =========================================================================

    public function test_create_posture_snapshot_persisted(): void
    {
        $findings = [['severity' => 'high', 'finding_key' => 'tls_expired']];
        $diffs    = [['finding_key' => 'tls_expired', 'change_type' => 'new', 'severity_before' => null, 'severity_after' => 'high']];
        $score    = $this->svc->computePostureScore($findings);

        $snapshot = $this->svc->createPostureSnapshot($this->run, $this->asset, $findings, $score, $diffs);

        $this->assertDatabaseHas('easm_posture_snapshots', [
            'asset_id' => $this->asset->id,
            'run_id'   => 'run-test-001',
        ]);
        $this->assertSame(80, $snapshot->posture_score);
        $this->assertSame(1, $snapshot->new_findings);
    }

    public function test_posture_snapshot_is_append_only(): void
    {
        $snapshot = EasmPostureSnapshot::create([
            'tenant_id'            => 'tenant-1',
            'asset_id'             => $this->asset->id,
            'run_id'               => 'run-append-test',
            'posture_score'        => 90,
            'risk_tier'            => 'low',
            'finding_count_total'  => 0,
            'finding_count_high'   => 0,
            'finding_count_medium' => 0,
            'finding_count_low'    => 0,
            'finding_count_info'   => 0,
            'new_findings'         => 0,
            'resolved_findings'    => 0,
            'regressed_findings'   => 0,
            'unchanged_findings'   => 0,
            'overall_status'       => 'PASS',
            'snapshot_at'          => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->expectException(LogicException::class);
        $snapshot->posture_score = 50;
        $snapshot->save();
    }

    public function test_snapshot_overall_status_findings_when_high(): void
    {
        $findings = [['severity' => 'high', 'finding_key' => 'tls_expired']];
        $snapshot = $this->svc->createPostureSnapshot($this->run, $this->asset, $findings, 80, []);
        $this->assertSame('FINDINGS', $snapshot->overall_status);
    }

    public function test_snapshot_overall_status_pass_when_no_findings(): void
    {
        $snapshot = $this->svc->createPostureSnapshot($this->run, $this->asset, [], 100, []);
        $this->assertSame('PASS', $snapshot->overall_status);
    }

    public function test_snapshot_overall_status_warn_for_low_info_only(): void
    {
        $findings = [['severity' => 'low', 'finding_key' => 'missing_hsts']];
        $snapshot = $this->svc->createPostureSnapshot($this->run, $this->asset, $findings, 97, []);
        $this->assertSame('WARN', $snapshot->overall_status);
    }

    // =========================================================================
    // Finding changes (5 tests)
    // =========================================================================

    public function test_record_finding_changes_creates_rows(): void
    {
        $diffs = [
            ['finding_key' => 'tls_expired', 'change_type' => 'new', 'severity_before' => null, 'severity_after' => 'high'],
            ['finding_key' => 'missing_csp', 'change_type' => 'resolved', 'severity_before' => 'medium', 'severity_after' => null],
        ];

        $records = $this->svc->recordFindingChanges($this->run, $this->asset, $diffs);

        $this->assertCount(2, $records);
        $this->assertDatabaseHas('easm_finding_changes', [
            'asset_id'    => $this->asset->id,
            'run_id'      => 'run-test-001',
            'change_type' => 'new',
        ]);
    }

    public function test_finding_change_is_append_only(): void
    {
        $change = EasmFindingChange::create([
            'tenant_id'       => 'tenant-1',
            'asset_id'        => $this->asset->id,
            'run_id'          => 'run-test-001',
            'finding_key'     => 'tls_expired',
            'change_type'     => 'new',
            'severity_before' => null,
            'severity_after'  => 'high',
            'changed_at'      => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->expectException(LogicException::class);
        $change->change_type = 'resolved';
        $change->save();
    }

    public function test_record_no_changes_for_empty_diffs(): void
    {
        $records = $this->svc->recordFindingChanges($this->run, $this->asset, []);
        $this->assertCount(0, $records);
    }

    public function test_finding_change_types_all_stored(): void
    {
        $diffs = [
            ['finding_key' => 'a', 'change_type' => 'new',       'severity_before' => null,   'severity_after' => 'high'],
            ['finding_key' => 'b', 'change_type' => 'resolved',  'severity_before' => 'low',  'severity_after' => null],
            ['finding_key' => 'c', 'change_type' => 'unchanged', 'severity_before' => 'info', 'severity_after' => 'info'],
            ['finding_key' => 'd', 'change_type' => 'regressed', 'severity_before' => 'low',  'severity_after' => 'high'],
        ];
        $records = $this->svc->recordFindingChanges($this->run, $this->asset, $diffs);
        $this->assertCount(4, $records);
        $types = array_map(fn ($r) => $r->change_type, $records);
        $this->assertContains('new', $types);
        $this->assertContains('resolved', $types);
        $this->assertContains('unchanged', $types);
        $this->assertContains('regressed', $types);
    }

    public function test_finding_change_has_correct_severity_fields(): void
    {
        $diffs = [
            ['finding_key' => 'tls_expired', 'change_type' => 'regressed', 'severity_before' => 'low', 'severity_after' => 'high'],
        ];
        $records = $this->svc->recordFindingChanges($this->run, $this->asset, $diffs);
        $this->assertSame('low', $records[0]->severity_before);
        $this->assertSame('high', $records[0]->severity_after);
    }

    // =========================================================================
    // Asset risk score (6 tests)
    // =========================================================================

    public function test_upsert_risk_score_creates_on_first_scan(): void
    {
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 85, null);

        $this->assertDatabaseHas('easm_asset_risk_scores', [
            'asset_id'   => $this->asset->id,
            'risk_score' => 85,
        ]);
        $this->assertSame('low', $rs->risk_tier);
        $this->assertSame('stable', $rs->trend_direction);
        $this->assertSame(1, $rs->total_scans);
    }

    public function test_upsert_risk_score_updates_on_second_scan(): void
    {
        $this->svc->upsertAssetRiskScore($this->asset, 85, null);
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 70, null);

        $this->assertSame(70, $rs->risk_score);
        $this->assertSame(85, $rs->previous_score);
        $this->assertSame('degrading', $rs->trend_direction);
        $this->assertSame(2, $rs->total_scans);
    }

    public function test_upsert_risk_score_delete_forbidden(): void
    {
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 80, null);
        $this->expectException(LogicException::class);
        $rs->delete();
    }

    public function test_upsert_risk_score_improving_trend(): void
    {
        $this->svc->upsertAssetRiskScore($this->asset, 60, null);
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 90, null);
        $this->assertSame('improving', $rs->trend_direction);
    }

    public function test_upsert_risk_score_stable_when_unchanged(): void
    {
        $this->svc->upsertAssetRiskScore($this->asset, 80, null);
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 80, null);
        $this->assertSame('stable', $rs->trend_direction);
    }

    public function test_upsert_risk_score_tier_critical(): void
    {
        $rs = $this->svc->upsertAssetRiskScore($this->asset, 20, null);
        $this->assertSame('critical', $rs->risk_tier);
    }

    // =========================================================================
    // Trend report (5 tests)
    // =========================================================================

    public function test_trend_report_structure(): void
    {
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertArrayHasKey('asset', $report);
        $this->assertArrayHasKey('tenant_id', $report);
        $this->assertArrayHasKey('is_advisory', $report);
        $this->assertArrayHasKey('current_score', $report);
        $this->assertArrayHasKey('risk_tier', $report);
        $this->assertArrayHasKey('trend', $report);
        $this->assertArrayHasKey('total_scans', $report);
        $this->assertArrayHasKey('snapshots', $report);
    }

    public function test_trend_report_returns_snapshots(): void
    {
        $this->svc->createPostureSnapshot($this->run, $this->asset, [], 100, []);
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertCount(1, $report['snapshots']);
    }

    public function test_trend_report_tenant_id_matches(): void
    {
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertSame('tenant-1', $report['tenant_id']);
    }

    public function test_trend_report_limit_respected(): void
    {
        // Create 12 scan runs and snapshots
        for ($i = 0; $i < 12; $i++) {
            $r = EasmScanRun::create([
                'tenant_id'       => 'tenant-1',
                'asset_id'        => $this->asset->id,
                'run_id'          => 'run-limit-' . $i,
                'scan_policy'     => 'passive',
                'target_hostname' => 'example.com',
                'target_url'      => 'https://example.com',
                'status'          => 'completed',
                'started_at'      => now()->subMinutes(12 - $i)->format('Y-m-d H:i:sP'),
                'finished_at'     => now()->subMinutes(12 - $i)->format('Y-m-d H:i:sP'),
                'finding_count'   => 0,
                'checks_run'      => [],
                'is_dry_run'      => false,
            ]);
            $this->svc->createPostureSnapshot($r, $this->asset, [], 100, []);
        }

        $report = $this->svc->generateTrendReport($this->asset, 10);
        $this->assertLessThanOrEqual(10, count($report['snapshots']));
    }

    public function test_trend_report_no_snapshots_returns_empty_array(): void
    {
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertIsArray($report['snapshots']);
        $this->assertCount(0, $report['snapshots']);
    }

    // =========================================================================
    // Tenant summary (5 tests)
    // =========================================================================

    public function test_tenant_summary_structure(): void
    {
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertArrayHasKey('tenant_id', $summary);
        $this->assertArrayHasKey('is_advisory', $summary);
        $this->assertArrayHasKey('asset_count', $summary);
        $this->assertArrayHasKey('total_findings', $summary);
        $this->assertArrayHasKey('open_findings', $summary);
        $this->assertArrayHasKey('by_severity', $summary);
        $this->assertArrayHasKey('highest_risk_asset', $summary);
    }

    public function test_tenant_summary_is_advisory_true(): void
    {
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertTrue($summary['is_advisory']);
    }

    public function test_tenant_summary_asset_count(): void
    {
        WebsiteAsset::create([
            'tenant_id' => 'tenant-1', 'name' => 'Site B',
            'url' => 'https://beta.example.com', 'hostname' => 'beta.example.com',
            'scheme' => 'https', 'scan_policy' => 'passive', 'status' => 'active',
        ]);
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertSame(2, $summary['asset_count']);
    }

    public function test_tenant_summary_by_severity(): void
    {
        EasmFinding::create([
            'tenant_id'   => 'tenant-1',
            'asset_id'    => $this->asset->id,
            'finding_key' => 'tls_expired',
            'category'    => 'tls',
            'severity'    => 'high',
            'title'       => 'TLS Expired',
            'description' => 'Test',
            'status'      => 'open',
            'is_advisory' => true,
            'first_seen_at' => now()->format('Y-m-d H:i:sP'),
            'last_seen_at'  => now()->format('Y-m-d H:i:sP'),
        ]);
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertSame(1, $summary['by_severity']['high']);
        $this->assertSame(1, $summary['open_findings']);
    }

    public function test_tenant_summary_highest_risk_asset_null_when_no_scores(): void
    {
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertNull($summary['highest_risk_asset']);
    }

    // =========================================================================
    // Edge cases (6 tests)
    // =========================================================================

    public function test_score_exactly_zero_is_critical(): void
    {
        $this->assertSame('critical', $this->svc->classifyRiskTier(0));
    }

    public function test_score_exactly_100_is_low(): void
    {
        $this->assertSame('low', $this->svc->classifyRiskTier(100));
    }

    public function test_diff_preserves_finding_key(): void
    {
        $diffs = $this->svc->diffFindings([], [['finding_key' => 'key123', 'severity' => 'info']]);
        $this->assertSame('key123', $diffs[0]['finding_key']);
    }

    public function test_snapshot_count_by_severity_correct(): void
    {
        $findings = [
            ['finding_key' => 'a', 'severity' => 'high'],
            ['finding_key' => 'b', 'severity' => 'high'],
            ['finding_key' => 'c', 'severity' => 'medium'],
            ['finding_key' => 'd', 'severity' => 'low'],
            ['finding_key' => 'e', 'severity' => 'info'],
        ];
        $snap = $this->svc->createPostureSnapshot($this->run, $this->asset, $findings, 46, []);
        $this->assertSame(2, $snap->finding_count_high);
        $this->assertSame(1, $snap->finding_count_medium);
        $this->assertSame(1, $snap->finding_count_low);
        $this->assertSame(1, $snap->finding_count_info);
        $this->assertSame(5, $snap->finding_count_total);
    }

    public function test_summary_isolated_by_tenant(): void
    {
        WebsiteAsset::create([
            'tenant_id' => 'other-tenant', 'name' => 'Other',
            'url' => 'https://other.example.com', 'hostname' => 'other.example.com',
            'scheme' => 'https', 'scan_policy' => 'passive', 'status' => 'active',
        ]);
        $summary = $this->svc->getTenantEasmSummary('tenant-1');
        $this->assertSame(1, $summary['asset_count']);
    }

    public function test_trend_report_asset_fields(): void
    {
        $report = $this->svc->generateTrendReport($this->asset);
        $this->assertSame($this->asset->id, $report['asset']['id']);
        $this->assertSame('Test Site', $report['asset']['name']);
        $this->assertSame('example.com', $report['asset']['hostname']);
    }
}
