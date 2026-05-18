<?php

namespace Tests\Feature;

use App\Models\ExportAuditLog;
use App\Models\User;
use App\Services\ReportExportService;
use App\Support\TraceRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private ReportExportService $exporter;
    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new ReportExportService();
        $this->analyst  = User::factory()->create(['role' => 'analyst']);
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function test_export_audit_log_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('export_audit_logs'));

        foreach (['export_id', 'export_type', 'export_format', 'exported_by', 'exported_at', 'source_id', 'export_size_bytes'] as $col) {
            $this->assertTrue(Schema::hasColumn('export_audit_logs', $col), "Missing column: {$col}");
        }
    }

    // -------------------------------------------------------------------------
    // Investigation JSON export
    // -------------------------------------------------------------------------

    public function test_investigation_json_export_is_valid_json(): void
    {
        $invId = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertNotEmpty($result['content']);
        $decoded = json_decode($result['content'], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Export must be valid JSON');
        $this->assertIsArray($decoded);
    }

    public function test_investigation_json_export_has_export_meta(): void
    {
        $invId = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('export_meta', $decoded);
        $this->assertSame('investigation', $decoded['export_meta']['report_type']);
    }

    public function test_json_export_preserves_trace_id(): void
    {
        $invId = $this->makeInvestigation(['trace_id' => 'trace-export-preserve-001']);
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringContainsString('trace-export-preserve-001', $result['content']);
    }

    public function test_json_export_preserves_timestamps(): void
    {
        $invId = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('generated_at', $decoded['export_meta']);
        $this->assertNotEmpty($decoded['export_meta']['generated_at']);
    }

    public function test_json_export_preserves_append_only_ordering_of_timeline(): void
    {
        $invId = $this->makeInvestigation();

        // Force-insert events in known order
        foreach (['2026-05-17 09:00:00', '2026-05-17 10:00:00', '2026-05-17 11:00:00'] as $ts) {
            DB::table('investigation_events')->insert([
                'investigation_id' => $invId,
                'event_type'       => 'state_transition',
                'actor_user_id'    => $this->analyst->id,
                'from_state'       => 'new',
                'to_state'         => 'triaged',
                'occurred_at'      => $ts,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        $result  = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);
        $decoded = json_decode($result['content'], true);

        $times = array_column($decoded['timeline_summary'], 'occurred_at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Timeline must be in ascending chronological order');
    }

    // -------------------------------------------------------------------------
    // TraceRedactor enforcement
    // -------------------------------------------------------------------------

    public function test_trace_redactor_removes_password_from_export(): void
    {
        $invId = $this->makeInvestigationWithSensitiveAlert('alert-pw-001', [
            'password' => 'supersecret-pw-123',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
        ]);

        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringNotContainsString('supersecret-pw-123', $result['content'],
            'Exported content must not contain raw password value');
        $this->assertStringContainsString('[REDACTED]', $result['content']);
    }

    public function test_trace_redactor_removes_token_from_export(): void
    {
        $invId = $this->makeInvestigationWithSensitiveAlert('alert-tok-001', [
            'token' => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.secret-token-value',
            'alert_type' => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
        ]);

        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringNotContainsString('eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9', $result['content'],
            'Token values must be redacted from export');
    }

    public function test_trace_redactor_removes_authorization_header_from_export(): void
    {
        $invId = $this->makeInvestigationWithSensitiveAlert('alert-auth-001', [
            'authorization' => 'Bearer secret-bearer-token-abc123',
            'alert_type'    => 'IDENTITY_RISKY_LOGIN',
        ]);

        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringNotContainsString('secret-bearer-token-abc123', $result['content'],
            'Authorization header value must be redacted');
        $this->assertStringNotContainsString('Bearer secret-bearer-token-abc123', $result['content']);
    }

    public function test_trace_redactor_removes_cookie_from_export(): void
    {
        $invId = $this->makeInvestigationWithSensitiveAlert('alert-cookie-001', [
            'cookie'    => 'session_id=cookie-secret-value-xyz; Path=/',
            'alert_type'=> 'ANOMALY_BEHAVIOR',
        ]);

        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringNotContainsString('cookie-secret-value-xyz', $result['content'],
            'Cookie value must be redacted');
    }

    public function test_trace_redactor_removes_secret_from_export(): void
    {
        $invId = $this->makeInvestigationWithSensitiveAlert('alert-secret-001', [
            'api_key'   => 'sk-very-secret-api-key-9999',
            'alert_type'=> 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
        ]);

        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringNotContainsString('sk-very-secret-api-key-9999', $result['content'],
            'API key must be redacted');
    }

    // -------------------------------------------------------------------------
    // Advisory disclaimer
    // -------------------------------------------------------------------------

    public function test_advisory_only_disclaimer_present_in_json_export(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $result['content'],
            'JSON export must contain the advisory disclaimer');
    }

    public function test_advisory_only_disclaimer_present_in_markdown_export(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'markdown', $this->analyst->id);

        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $result['content'],
            'Markdown export must contain the advisory disclaimer');
    }

    public function test_advisory_only_disclaimer_present_in_html_export(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'html', $this->analyst->id);

        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $result['content'],
            'HTML export must contain the advisory disclaimer');
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    public function test_export_creates_audit_log_record(): void
    {
        $invId = $this->makeInvestigation();
        $countBefore = ExportAuditLog::count();

        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id, 'audit test');

        $this->assertSame($countBefore + 1, ExportAuditLog::count());
    }

    public function test_audit_log_records_who_exported(): void
    {
        $invId = $this->makeInvestigation();
        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id, 'who test');

        $log = ExportAuditLog::orderByDesc('id')->first();
        $this->assertSame($this->analyst->id, $log->exported_by);
        $this->assertSame('json', $log->export_format);
        $this->assertSame('investigation', $log->export_type);
        $this->assertSame('who test', $log->export_reason);
    }

    public function test_audit_log_records_export_size(): void
    {
        $invId = $this->makeInvestigation();
        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $log = ExportAuditLog::orderByDesc('id')->first();
        $this->assertGreaterThan(0, $log->export_size_bytes);
    }

    public function test_audit_log_is_append_only(): void
    {
        $invId = $this->makeInvestigation();

        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);
        $this->exporter->exportInvestigation($invId, 'markdown', $this->analyst->id);
        $this->exporter->exportInvestigation($invId, 'html', $this->analyst->id);

        $this->assertSame(3, ExportAuditLog::where('export_type', 'investigation')
            ->where('source_id', (string) $invId)->count(),
            'Each export must create a new audit log — never update existing');
    }

    public function test_audit_log_has_unique_export_id(): void
    {
        $invId = $this->makeInvestigation();
        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);
        $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $ids = ExportAuditLog::pluck('export_id')->toArray();
        $this->assertSame(count($ids), count(array_unique($ids)), 'Export IDs must be unique');
    }

    // -------------------------------------------------------------------------
    // Response plan export
    // -------------------------------------------------------------------------

    public function test_response_plan_json_export_contains_actions(): void
    {
        $planId = $this->makeResponsePlan();
        $result = $this->exporter->exportResponsePlan($planId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('actions', $decoded);
        $this->assertIsArray($decoded['actions']);
    }

    public function test_response_plan_export_contains_advisory_disclaimer(): void
    {
        $planId = $this->makeResponsePlan();
        $result = $this->exporter->exportResponsePlan($planId, 'json', $this->analyst->id);

        $this->assertStringContainsString(ReportExportService::DISCLAIMER, $result['content']);
    }

    public function test_response_plan_export_contains_approval_chain(): void
    {
        $planId = $this->makeResponsePlan();
        $result = $this->exporter->exportResponsePlan($planId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('full_approval_history', $decoded);
    }

    // -------------------------------------------------------------------------
    // Entity risk export
    // -------------------------------------------------------------------------

    public function test_entity_risk_json_export_contains_risk_factors(): void
    {
        $entityId = $this->makeEntityWithRisk();
        $result   = $this->exporter->exportEntityRisk($entityId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('current_risk', $decoded);
        $this->assertArrayHasKey('risk_factors', $decoded['current_risk']);
    }

    public function test_entity_risk_export_contains_historical_snapshots(): void
    {
        $entityId = $this->makeEntityWithRisk();
        $result   = $this->exporter->exportEntityRisk($entityId, 'json', $this->analyst->id);

        $decoded = json_decode($result['content'], true);
        $this->assertArrayHasKey('historical_snapshots', $decoded);
    }

    // -------------------------------------------------------------------------
    // Trace export
    // -------------------------------------------------------------------------

    public function test_trace_json_export_contains_timeline(): void
    {
        $traceId = 'trace-export-test-001';
        $this->makeTraceData($traceId);

        $result  = $this->exporter->exportTrace($traceId, 'json', $this->analyst->id);
        $decoded = json_decode($result['content'], true);

        $this->assertArrayHasKey('trace', $decoded);
        $this->assertSame($traceId, $decoded['trace']['trace_id']);
        $this->assertArrayHasKey('alerts_generated', $decoded);
    }

    public function test_trace_export_preserves_trace_id_in_all_formats(): void
    {
        $traceId = 'trace-format-test-001';
        $this->makeTraceData($traceId);

        foreach (['json', 'markdown', 'html'] as $format) {
            $result = $this->exporter->exportTrace($traceId, $format, $this->analyst->id);
            $this->assertStringContainsString($traceId, $result['content'],
                "trace_id must appear in {$format} export");
        }
    }

    // -------------------------------------------------------------------------
    // Format outputs
    // -------------------------------------------------------------------------

    public function test_markdown_export_has_section_headers(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'markdown', $this->analyst->id);

        $this->assertStringContainsString('# Investigation Summary Report', $result['content']);
        $this->assertStringContainsString('## Export Metadata', $result['content']);
    }

    public function test_html_export_is_self_contained(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'html', $this->analyst->id);

        $this->assertStringContainsString('<!DOCTYPE html>', $result['content']);
        $this->assertStringContainsString('<style>', $result['content']);

        // Must not load external resources
        $this->assertStringNotContainsString('cdn.', $result['content']);
        $this->assertStringNotContainsString('googleapis.com', $result['content']);
        $this->assertStringNotContainsString('<link rel="stylesheet" href="http', $result['content']);
        $this->assertStringNotContainsString('<script src="http', $result['content']);
    }

    public function test_html_export_mime_type_is_text_html(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'html', $this->analyst->id);

        $this->assertSame('text/html', $result['mime']);
    }

    public function test_json_export_mime_type_is_application_json(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $this->assertSame('application/json', $result['mime']);
    }

    public function test_markdown_export_mime_type_is_text_markdown(): void
    {
        $invId  = $this->makeInvestigation();
        $result = $this->exporter->exportInvestigation($invId, 'markdown', $this->analyst->id);

        $this->assertSame('text/markdown', $result['mime']);
    }

    // -------------------------------------------------------------------------
    // Replay consistency
    // -------------------------------------------------------------------------

    public function test_export_replay_consistency(): void
    {
        $invId   = $this->makeInvestigation();
        $result1 = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);
        $result2 = $this->exporter->exportInvestigation($invId, 'json', $this->analyst->id);

        $decoded1 = json_decode($result1['content'], true);
        $decoded2 = json_decode($result2['content'], true);

        // Core data must be identical (generated_at will differ — check source data)
        $this->assertSame($decoded1['investigation'], $decoded2['investigation'],
            'Investigation data must be identical across exports of same record');
        $this->assertSame(
            count($decoded1['linked_alerts']),
            count($decoded2['linked_alerts']),
            'Linked alert count must be consistent'
        );
    }

    public function test_unsupported_format_throws_exception(): void
    {
        $invId = $this->makeInvestigation();

        $this->expectException(\InvalidArgumentException::class);
        $this->exporter->exportInvestigation($invId, 'pdf', $this->analyst->id);
    }

    // -------------------------------------------------------------------------
    // RBAC / Authorization
    // -------------------------------------------------------------------------

    public function test_api_export_investigation_requires_authentication(): void
    {
        $this->postJson('/api/exports/investigation/1', ['format' => 'json'])
            ->assertUnauthorized();
    }

    public function test_api_export_response_plan_requires_authentication(): void
    {
        $this->postJson('/api/exports/response-plan/1', ['format' => 'json'])
            ->assertUnauthorized();
    }

    public function test_api_export_entity_risk_requires_authentication(): void
    {
        $this->postJson('/api/exports/entity-risk/1', ['format' => 'json'])
            ->assertUnauthorized();
    }

    public function test_api_export_trace_requires_authentication(): void
    {
        $this->postJson('/api/exports/trace/trace-id-001', ['format' => 'json'])
            ->assertUnauthorized();
    }

    public function test_export_center_requires_authentication(): void
    {
        $this->get('/exports')->assertRedirect();
    }

    public function test_analyst_can_access_export_center(): void
    {
        $this->actingAs($this->analyst)->get('/exports')->assertOk();
    }

    public function test_viewer_cannot_access_export_center(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/exports')->assertForbidden();
    }

    public function test_api_export_history_requires_auth(): void
    {
        $this->get('/api/exports')->assertRedirect();
    }

    public function test_api_export_history_returns_json_for_analyst(): void
    {
        $this->actingAs($this->analyst)->get('/api/exports')
            ->assertOk()
            ->assertJsonStructure(['history', 'total', 'counts']);
    }

    // -------------------------------------------------------------------------
    // Grafana dashboard
    // -------------------------------------------------------------------------

    public function test_export_grafana_dashboard_exists(): void
    {
        $path = base_path('docs/grafana/export-reporting-dashboard.json');
        $this->assertFileExists($path);

        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('panels', $json);
        $this->assertSame('XDR Export & Reporting Dashboard', $json['title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInvestigation(array $overrides = []): int
    {
        return DB::table('investigations')->insertGetId(array_merge([
            'investigation_id' => 'INV-EXP-' . uniqid(),
            'title'            => 'Export Test Investigation',
            'state'            => 'new',
            'severity'         => 'high',
            'priority'         => 3,
            'created_by'       => $this->analyst->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $overrides));
    }

    private function makeInvestigationWithSensitiveAlert(string $alertId, array $evidence): int
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => $alertId,
            'alert_type'  => $evidence['alert_type'] ?? 'IDENTITY_MFA_FAILURE_BURST',
            'severity'    => 'high',
            'detected_at' => '2026-05-17 10:00:00',
            'evidence'    => json_encode($evidence),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $this->makeInvestigation([
            'alert_ids' => json_encode([$alertId]),
        ]);
    }

    private function makeResponsePlan(): int
    {
        $id = DB::table('response_plans')->insertGetId([
            'plan_id'    => 'RP-EXP-' . uniqid(),
            'title'      => 'Export Test Plan',
            'state'      => 'draft',
            'risk_level' => 'high',
            'created_by' => $this->analyst->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('response_plan_actions')->insert([
            'response_plan_id'   => $id,
            'action_type'        => 'recommend_reset_password',
            'target_entity_type' => 'user',
            'target_entity_key'  => 'testuser@example.com',
            'reasoning'          => 'High risk user detected.',
            'advisory_only'      => false,
            'status'             => 'pending',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('response_plan_approvals')->insert([
            'response_plan_id' => $id,
            'approver_user_id' => $this->analyst->id,
            'decision'         => 'submitted',
            'decision_at'      => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $id;
    }

    private function makeEntityWithRisk(): int
    {
        $id = DB::table('entities')->insertGetId([
            'entity_type'       => 'user',
            'entity_key'        => 'export-user@test.local',
            'display_name'      => 'Export Test User',
            'first_seen_at'     => '2026-05-17 09:00:00',
            'last_seen_at'      => '2026-05-17 10:00:00',
            'observation_count' => 5,
            'risk_score'        => 7.5,
            'risk_level'        => 'high',
            'risk_factors'      => json_encode([
                ['factor' => 'high_alerts', 'value' => 2, 'weight' => 2.0, 'contribution' => 4.0],
                ['factor' => 'mfa_failure_burst', 'value' => 1, 'weight' => 2.5, 'contribution' => 2.5],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('entity_risk_snapshots')->insert([
            'entity_id'     => $id,
            'entity_type'   => 'user',
            'entity_key'    => 'export-user@test.local',
            'risk_score'    => 7.5,
            'risk_level'    => 'high',
            'risk_factors'  => json_encode([]),
            'calculated_at' => '2026-05-17 10:00:00',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $id;
    }

    private function makeTraceData(string $traceId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'trace-exp-alert-' . uniqid(),
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'    => 'high',
            'detected_at' => '2026-05-17 10:00:00',
            'trace_id'    => $traceId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
