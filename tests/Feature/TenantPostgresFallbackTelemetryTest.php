<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TENANT-POSTGRES-FALLBACK-TELEMETRY: Postgres's own telemetry_events never
 * carried tenant_id (ClickHouse's copy has since ARCH-DB-SPLIT), so the
 * default telemetry_write_target=postgres path -- and every ClickHouse
 * read-path's Postgres fallback -- ran completely unscoped. Adds tenant_id
 * (nullable, legacy rows stay visible everywhere, matching
 * TenantBoundaryService's documented convention), populates it on both
 * write paths (AgentIngestionController::telemetry() in PHP,
 * scripts/ingest_telemetry_events.py in Python -- covered by its own
 * Python tests), and scopes the same 4 Postgres fallback read sites
 * TENANT-CLICKHOUSE-LEAK already scoped on the ClickHouse side.
 *
 * Deliberately NOT scoped (documented, not an oversight): OpsHealthController,
 * the Xdr* CLI commands, and DetectionBacktestService -- global/ops-level or
 * intentionally cross-tenant (matching identityCloudSaasWindowPage()'s existing
 * no-tenant-param design on the ClickHouse side).
 */
class TenantPostgresFallbackTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_telemetry_events_has_a_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('telemetry_events', 'tenant_id'));
    }

    public function test_telemetry_events_is_registered_as_isolated_and_append_only(): void
    {
        $this->assertContains('telemetry_events', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('telemetry_events', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
        $this->assertNotContains('telemetry_events', TenantBoundaryService::UNISOLATED_TABLES);
        $this->assertNotContains('telemetry_events', TenantBoundaryService::MUTABLE_TABLES);
    }

    // Same registration/signing convention as EndpointAgentApiTest /
    // ClickHouseTelemetryWriterTest -- a real registered agent + $this->call()
    // with the exact raw JSON body used for the HMAC, since a mismatched
    // re-encoding (e.g. via postJson()'s own encoder) would produce a
    // signature the controller correctly rejects.
    private function registerAgent(string $hostId): array
    {
        Config::set('soc.agent_enrollment_token', 'test-token');

        return $this->postJson('/api/agents/register', [
            'host_fingerprint' => 'fp-'.$hostId,
            'host_id' => $hostId,
            'agent_version' => '0.2.0',
            'os_family' => 'linux',
        ], [
            'X-Agent-Enrollment-Token' => 'test-token',
        ])->json();
    }

    private function postSignedAgentJson(string $uri, string $agentId, string $secret, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_X_AGENT_ID' => $agentId,
            'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_AGENT_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_agent_ingestion_writes_tenant_id_on_the_postgres_path(): void
    {
        $registered = $this->registerAgent('host-tenant-write-test');
        DB::table('endpoint_agents')->where('agent_id', $registered['agent_id'])->update(['tenant_id' => 'tenant-write-x']);

        $telemetry = ['events' => [[
            'schema_version' => 1,
            'ts' => now()->toIso8601String(),
            'event_id' => 'evt-tenant-write-1',
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => 'host-tenant-write-test',
        ]]];

        $this->postSignedAgentJson('/api/agents/telemetry', $registered['agent_id'], $registered['agent_secret'], $telemetry)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('telemetry_events', [
            'event_id' => 'evt-tenant-write-1',
            'tenant_id' => 'tenant-write-x',
        ]);
    }

    private function seedTelemetry(string $eventId, string $hostId, ?string $tenantId, array $overrides = []): void
    {
        DB::table('telemetry_events')->insert(array_merge([
            'ts' => now(),
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'telemetry_type' => 'endpoint',
            'event_type' => 'process_observed',
            'host_id' => $hostId,
            'payload' => json_encode(['event_id' => $eventId]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function actingAsTenant(string $tenantId): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        app(TenantContextAuthority::class)->grantMembership($user->id, $tenantId, $user->id);

        return $user;
    }

    public function test_endpoint_timeline_postgres_fallback_shows_own_tenant_and_legacy_rows_only(): void
    {
        $host = 'host-timeline-scope-test';
        $this->seedTelemetry('evt-own-tenant', $host, 'tenant-a', ['event_type' => 'own_tenant_marker']);
        $this->seedTelemetry('evt-other-tenant', $host, 'tenant-b', ['event_type' => 'other_tenant_marker']);
        $this->seedTelemetry('evt-legacy', $host, null, ['event_type' => 'legacy_marker']);
        $user = $this->actingAsTenant('tenant-a');

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get("/soc/endpoints/{$host}");

        $response->assertOk();
        $response->assertSee('own_tenant_marker');
        $response->assertSee('legacy_marker');
        $response->assertDontSee('other_tenant_marker');
    }

    public function test_hunt_postgres_fallback_shows_own_tenant_and_legacy_rows_only(): void
    {
        $host = 'host-hunt-scope-test';
        $this->seedTelemetry('evt-hunt-own', $host, 'tenant-c', ['event_type' => 'hunt_own_marker']);
        $this->seedTelemetry('evt-hunt-other', $host, 'tenant-d', ['event_type' => 'hunt_other_marker']);
        $this->seedTelemetry('evt-hunt-legacy', $host, null, ['event_type' => 'hunt_legacy_marker']);
        $user = $this->actingAsTenant('tenant-c');

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-c'])
            ->get('/soc/hunts?run=1&host_id='.$host);

        $response->assertOk();
        $response->assertSee('hunt_own_marker');
        $response->assertSee('hunt_legacy_marker');
        $response->assertDontSee('hunt_other_marker');
    }

    public function test_forensic_postgres_fallback_shows_own_tenant_and_legacy_rows_only(): void
    {
        $host = 'host-forensic-scope-test';
        $this->seedTelemetry('evt-forensic-own', $host, 'tenant-e', ['event_type' => 'forensic_own_marker']);
        $this->seedTelemetry('evt-forensic-other', $host, 'tenant-f', ['event_type' => 'forensic_other_marker']);
        $this->seedTelemetry('evt-forensic-legacy', $host, null, ['event_type' => 'forensic_legacy_marker']);
        $user = $this->actingAsTenant('tenant-e');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-e'])
            ->post('/soc/forensics', ['host_id' => $host, 'collection_type' => 'telemetry-snapshot'])
            ->assertRedirect();

        $jobId = DB::table('forensic_collection_jobs')->where('host_id', $host)->orderByDesc('id')->value('job_id');
        $this->assertNotNull($jobId);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-e'])
            ->post("/soc/forensics/{$jobId}/decision", ['decision' => 'approve'])
            ->assertRedirect();

        $artifactPath = storage_path("app/forensics/{$jobId}.json");
        $this->assertFileExists($artifactPath);
        $artifact = json_decode(file_get_contents($artifactPath), true);
        $eventTypes = array_column($artifact['telemetry'], 'event_type');

        $this->assertContains('forensic_own_marker', $eventTypes);
        $this->assertContains('forensic_legacy_marker', $eventTypes);
        $this->assertNotContains('forensic_other_marker', $eventTypes);

        @unlink($artifactPath);
        @unlink(storage_path("app/forensics/{$jobId}.zip"));
    }

    public function test_dashboard_xdr_summary_counts_are_scoped_to_own_tenant_and_legacy(): void
    {
        $this->seedTelemetry('evt-dash-own', 'host-dash-a', 'tenant-g', [
            'telemetry_type' => 'identity', 'risk_score' => 0.9,
        ]);
        $this->seedTelemetry('evt-dash-other', 'host-dash-b', 'tenant-h', [
            'telemetry_type' => 'identity', 'risk_score' => 0.9,
        ]);
        $this->seedTelemetry('evt-dash-legacy', 'host-dash-c', null, [
            'telemetry_type' => 'identity', 'risk_score' => 0.9,
        ]);
        $user = $this->actingAsTenant('tenant-g');

        // Own tenant + legacy count = 2, but not the other tenant's row.
        $scoped = DB::table('telemetry_events')
            ->where('telemetry_type', 'identity')
            ->where('risk_score', '>=', 0.7)
            ->where(fn ($q) => $q->where('tenant_id', 'tenant-g')->orWhereNull('tenant_id'))
            ->count();
        $this->assertSame(2, $scoped);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => 'tenant-g'])
            ->get('/soc');

        $response->assertOk();
    }
}
