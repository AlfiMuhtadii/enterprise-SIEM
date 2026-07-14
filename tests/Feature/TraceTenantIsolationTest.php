<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xdr.tenancy.strict_mode' => true]);
        $this->analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($this->analyst->id, 'tenant-a', $this->analyst->id);
    }

    public function test_trace_index_only_lists_active_tenant_traces(): void
    {
        $this->alert('trace-a', 'tenant-a', 'alert-a');
        $this->alert('trace-b', 'tenant-b', 'alert-b');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/traces')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('traces.0.trace_id', 'trace-a')
            ->assertJsonMissing(['trace_id' => 'trace-b']);
    }

    public function test_trace_search_by_ip_cannot_discover_other_tenant_trace(): void
    {
        $this->alert('trace-b', 'tenant-b', 'alert-b', '203.0.113.8');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/traces?by=ip&q=203.0.113.8')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_all_trace_detail_endpoints_reject_another_tenants_trace(): void
    {
        $this->alert('trace-b', 'tenant-b', 'alert-b');
        $this->operationalEvent('trace-b');

        foreach (['', '/timeline', '/evidence', '/alerts', '/incidents'] as $suffix) {
            $this->actingAs($this->analyst)
                ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
                ->getJson('/api/traces/trace-b'.$suffix)
                ->assertNotFound();
        }
    }

    public function test_web_trace_detail_rejects_another_tenants_trace(): void
    {
        $this->alert('trace-b', 'tenant-b', 'alert-b');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get('/traces/trace-b')
            ->assertNotFound();
    }

    public function test_same_trace_id_only_returns_active_tenant_alerts(): void
    {
        $this->alert('shared-trace', 'tenant-a', 'alert-a');
        $this->alert('shared-trace', 'tenant-b', 'alert-b');

        $this->actingAs($this->analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/traces/shared-trace')
            ->assertOk()
            ->assertJsonPath('alerts_count', 1)
            ->assertJsonPath('alerts.0.alert_id', 'alert-a')
            ->assertJsonMissing(['alert_id' => 'alert-b']);
    }

    public function test_strict_mode_requires_tenant_context(): void
    {
        $this->alert('trace-a', 'tenant-a', 'alert-a');

        $this->actingAs($this->analyst)
            ->getJson('/api/traces')
            ->assertForbidden();
    }

    private function alert(string $traceId, string $tenantId, string $alertId, string $ip = '10.0.0.1'): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'tenant_id' => $tenantId,
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'trace_id' => $traceId,
            'ip' => $ip,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function operationalEvent(string $traceId): void
    {
        DB::table('xdr_operational_events')->insert([
            'event_id' => uniqid('event-', true),
            'event_type' => 'alert.created',
            'schema_version' => 1,
            'source_service' => 'alert-writer-service',
            'trace_id' => $traceId,
            'occurred_at' => now(),
            'payload' => json_encode(['secret' => 'other-tenant-evidence']),
            'replayable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
