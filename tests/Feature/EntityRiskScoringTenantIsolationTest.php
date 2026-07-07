<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EntityRiskScoringService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-TENANCY-RISK-SCORING — EntityRiskScoringService::calculateRisk() and
 * its factor collectors previously queried security_alerts/security_incidents
 * globally by entity key (actor_key/ip/trace_id), with no tenant_id filter
 * at all — an entity key that exists in two tenants (e.g. a shared IP or a
 * common email prefix) would silently aggregate and leak the other
 * tenant's alerts/incidents into the risk factor breakdown.
 *
 * Scope: only the two data-retrieval helpers named in the finding
 * (alertsForEntity/incidentsForEntity) plus the three type-specific
 * collectors that hit the same already-tenant-tagged security_alerts
 * table (collectUserFactors/collectHostFactors/collectNetworkFactors) are
 * fixed here. The remaining ~10 advisory amplification factor collectors
 * (cross-domain, active-response, UEBA, endpoint-operational, low-level
 * telemetry, investigation-graph, SOAR) query tables that don't have a
 * tenant_id column at all yet — each is covered by its own dedicated
 * tenant-isolation backlog item and deliberately left untouched here.
 */
class EntityRiskScoringTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntity(string $type, string $key, ?string $tenantId = null): int
    {
        return DB::table('entities')->insertGetId([
            'entity_type' => $type,
            'entity_key' => $key,
            'display_name' => $key,
            'tenant_id' => $tenantId,
            'first_seen_at' => '2026-05-16 09:00:00',
            'last_seen_at' => '2026-05-16 10:00:00',
            'observation_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAlert(string $alertId, string $severity, ?string $actorKey, ?string $ip, ?string $tenantId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_type' => 'IDENTITY_ANOMALY',
            'severity' => $severity,
            'detected_at' => now(),
            'actor_key' => $actorKey,
            'ip' => $ip,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertIncident(string $incidentId, ?string $traceId, ?string $tenantId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $incidentId,
            'title' => 'Test incident',
            'status' => 'open',
            'severity' => 'high',
            'trace_id' => $traceId,
            'tenant_id' => $tenantId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_alert_from_other_tenant_is_excluded_when_scoped(): void
    {
        $entityId = $this->makeEntity('ip', '203.0.113.9', 'tenant-a');
        $this->insertAlert('alert-a', 'critical', null, '203.0.113.9', 'tenant-a');
        $this->insertAlert('alert-b', 'critical', null, '203.0.113.9', 'tenant-b');

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $this->assertContains('alert-a', $result['alert_ids']);
        $this->assertNotContains('alert-b', $result['alert_ids']);
    }

    public function test_incident_from_other_tenant_is_excluded_when_scoped(): void
    {
        $entityId = $this->makeEntity('trace', 'trace-shared', 'tenant-a');
        $this->insertIncident('inc-a', 'trace-shared', 'tenant-a');
        $this->insertIncident('inc-b', 'trace-shared', 'tenant-b');

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $this->assertContains('inc-a', $result['incident_ids']);
        $this->assertNotContains('inc-b', $result['incident_ids']);
    }

    public function test_user_mfa_burst_factor_excludes_other_tenant_alerts(): void
    {
        $entityId = $this->makeEntity('user', 'alice@example.test', 'tenant-a');
        DB::table('security_alerts')->insert([
            ['alert_id' => 'a1', 'alert_type' => 'IDENTITY_MFA_FAILURE_BURST', 'severity' => 'high', 'detected_at' => now(), 'actor_key' => 'alice@example.test', 'tenant_id' => 'tenant-a', 'created_at' => now(), 'updated_at' => now()],
            ['alert_id' => 'a2', 'alert_type' => 'IDENTITY_MFA_FAILURE_BURST', 'severity' => 'high', 'detected_at' => now(), 'actor_key' => 'alice@example.test', 'tenant_id' => 'tenant-b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $mfaFactor = collect($result['factors'])->firstWhere('factor', 'mfa_failure_burst');
        $this->assertNotNull($mfaFactor);
        $this->assertSame(1, $mfaFactor['value'], 'only the tenant-a alert should count toward the burst');
    }

    public function test_null_tenant_id_preserves_legacy_unscoped_behavior(): void
    {
        // No tenant context (admin/legacy caller) — both rows are visible,
        // matching the pre-fix behavior for callers that don't pass a tenant.
        $entityId = $this->makeEntity('ip', '203.0.113.10', null);
        $this->insertAlert('alert-x', 'critical', null, '203.0.113.10', 'tenant-a');
        $this->insertAlert('alert-y', 'critical', null, '203.0.113.10', 'tenant-b');

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, null);

        $this->assertContains('alert-x', $result['alert_ids']);
        $this->assertContains('alert-y', $result['alert_ids']);
    }

    public function test_entity_owned_by_other_tenant_returns_empty_result(): void
    {
        $entityId = $this->makeEntity('ip', '203.0.113.11', 'tenant-b');
        $this->insertAlert('alert-z', 'critical', null, '203.0.113.11', 'tenant-b');

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $this->assertSame([], $result);
    }

    public function test_entity_with_null_tenant_is_accessible_from_any_tenant_context(): void
    {
        // legacy/unscoped entity (tenant_id null) — treated like assertAccess's
        // null-permissive convention, not silently hidden from every tenant.
        $entityId = $this->makeEntity('ip', '203.0.113.12', null);
        $this->insertAlert('alert-legacy', 'critical', null, '203.0.113.12', 'tenant-a');

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $this->assertNotSame([], $result);
        $this->assertContains('alert-legacy', $result['alert_ids']);
    }

    public function test_host_persistence_factor_excludes_other_tenant_alerts(): void
    {
        $entityId = $this->makeEntity('host', 'host-1', 'tenant-a');
        DB::table('security_alerts')->insert([
            ['alert_id' => 'p1', 'alert_type' => 'ENDPOINT_NEW_SERVICE_PERSISTENCE', 'severity' => 'high', 'detected_at' => now(), 'ip' => 'host-1', 'tenant_id' => 'tenant-a', 'created_at' => now(), 'updated_at' => now()],
            ['alert_id' => 'p2', 'alert_type' => 'ENDPOINT_NEW_SERVICE_PERSISTENCE', 'severity' => 'high', 'detected_at' => now(), 'ip' => 'host-1', 'tenant_id' => 'tenant-b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = app(EntityRiskScoringService::class)->calculateRisk($entityId, 'tenant-a');

        $factor = collect($result['factors'])->firstWhere('factor', 'persistence_indicator');
        $this->assertNotNull($factor);
        $this->assertSame(1, $factor['value']);
    }

    public function test_http_entity_risk_endpoint_excludes_other_tenant_alerts_via_header(): void
    {
        $entityId = $this->makeEntity('ip', '203.0.113.20', 'tenant-a');
        $this->insertAlert('http-a', 'critical', null, '203.0.113.20', 'tenant-a');
        $this->insertAlert('http-b', 'critical', null, '203.0.113.20', 'tenant-b');

        $analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'tenant-a', $analyst->id);

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson("/api/entities/{$entityId}/risk");

        $response->assertOk();
        $this->assertContains('http-a', $response->json('risk.alert_ids'));
        $this->assertNotContains('http-b', $response->json('risk.alert_ids'));
    }

    public function test_http_entity_risk_endpoint_returns_404_for_entity_in_other_tenant(): void
    {
        $entityId = $this->makeEntity('ip', '203.0.113.21', 'tenant-b');

        $analyst = User::factory()->create(['role' => 'analyst']);
        app(TenantContextAuthority::class)->grantMembership($analyst->id, 'tenant-a', $analyst->id);

        $response = $this->actingAs($analyst)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson("/api/entities/{$entityId}/risk");

        $response->assertStatus(404);
    }
}
