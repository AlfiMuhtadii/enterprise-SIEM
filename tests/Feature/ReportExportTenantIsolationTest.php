<?php

namespace Tests\Feature;

use App\Models\ExportAuditLog;
use App\Models\User;
use App\Services\ReportExportService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportExportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $analyst;
    private ReportExportService $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xdr.tenancy.strict_mode' => true]);
        $this->analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($this->analyst->id, 'tenant-a', $this->analyst->id);
        $this->exporter = app(ReportExportService::class);
    }

    public function test_api_cannot_export_another_tenants_investigation(): void
    {
        $id = $this->investigation('tenant-b');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson("/api/exports/investigation/{$id}", ['format' => 'json'])
            ->assertNotFound();

        $this->assertSame(0, ExportAuditLog::count());
    }

    public function test_api_exports_own_investigation_and_stamps_audit_tenant(): void
    {
        $id = $this->investigation('tenant-a');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson("/api/exports/investigation/{$id}", ['format' => 'json'])
            ->assertOk();

        $this->assertDatabaseHas('export_audit_logs', [
            'source_id' => (string) $id,
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_strict_mode_rejects_export_without_tenant_context(): void
    {
        $id = $this->investigation('tenant-a');

        $this->actingAs($this->analyst)
            ->postJson("/api/exports/investigation/{$id}", ['format' => 'json'])
            ->assertForbidden();
    }

    public function test_response_plan_and_entity_exports_reject_cross_tenant_ids(): void
    {
        $planId = $this->responsePlan('tenant-b');
        $entityId = $this->entity('tenant-b');

        foreach ([
            "/api/exports/response-plan/{$planId}",
            "/api/exports/entity-risk/{$entityId}",
        ] as $url) {
            $this->actingAs($this->analyst)
                ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
                ->postJson($url, ['format' => 'json'])
                ->assertNotFound();
        }

        $this->assertSame(0, ExportAuditLog::count());
    }

    public function test_trace_export_requires_a_trace_owned_by_request_tenant(): void
    {
        $this->alert('trace-tenant-b', 'tenant-b');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson('/api/exports/trace/trace-tenant-b', ['format' => 'json'])
            ->assertNotFound();
    }

    public function test_trace_export_excludes_other_tenant_rows_with_same_trace_id(): void
    {
        $this->alert('shared-trace', 'tenant-a', 'alert-a');
        $this->alert('shared-trace', 'tenant-b', 'alert-b');

        $response = $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson('/api/exports/trace/shared-trace', ['format' => 'json'])
            ->assertOk();

        $response->assertSee('alert-a', false);
        $response->assertDontSee('alert-b', false);
    }

    public function test_history_and_counts_are_tenant_scoped(): void
    {
        $tenantAId = $this->investigation('tenant-a');
        $tenantBId = $this->investigation('tenant-b');
        $this->exporter->exportInvestigation($tenantAId, 'json', $this->analyst->id, null, 'tenant-a');
        $this->exporter->exportInvestigation($tenantBId, 'json', $this->analyst->id, null, 'tenant-b');

        $response = $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/exports')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('counts.investigation.count', 1);

        $this->assertSame('tenant-a', $response->json('history.0.tenant_id'));
    }

    private function investigation(string $tenantId): int
    {
        return DB::table('investigations')->insertGetId([
            'investigation_id' => 'INV-TEN-' . uniqid(),
            'tenant_id' => $tenantId,
            'title' => 'Tenant export investigation',
            'state' => 'new',
            'severity' => 'high',
            'priority' => 3,
            'created_by' => $this->analyst->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function responsePlan(string $tenantId): int
    {
        return DB::table('response_plans')->insertGetId([
            'plan_id' => 'RP-TEN-' . uniqid(),
            'tenant_id' => $tenantId,
            'title' => 'Tenant export response plan',
            'state' => 'draft',
            'risk_level' => 'high',
            'created_by' => $this->analyst->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function entity(string $tenantId): int
    {
        return DB::table('entities')->insertGetId([
            'tenant_id' => $tenantId,
            'entity_type' => 'user',
            'entity_key' => uniqid('tenant-user-', true),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'observation_count' => 1,
            'risk_score' => 5,
            'risk_level' => 'medium',
            'risk_factors' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function alert(string $traceId, string $tenantId, ?string $alertId = null): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId ?? uniqid('alert-', true),
            'tenant_id' => $tenantId,
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'trace_id' => $traceId,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
