<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\ThreatHunt;
use App\Models\User;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ThreatHuntTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ThreatHuntingService $hunting;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xdr.tenancy.strict_mode' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->hunting = app(ThreatHuntingService::class);
    }

    public function test_hunt_lineage_tables_have_tenant_id(): void
    {
        foreach (['threat_hunts', 'threat_hunt_queries', 'threat_hunt_results'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'));
        }
    }

    public function test_alert_hunt_only_snapshots_active_tenant_rows(): void
    {
        $this->alert('alert-a', 'tenant-a');
        $this->alert('alert-b', 'tenant-b');

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson('/api/threat-hunts/query', [
                'query_domain' => 'alerts',
                'query_filters' => [],
                'title' => 'Tenant A Hunt',
            ])
            ->assertCreated()
            ->assertJsonPath('result_count', 1);

        $hunt = ThreatHunt::where('hunt_id', $response->json('hunt_id'))->firstOrFail();
        $this->assertSame('tenant-a', $hunt->tenant_id);
        $this->assertSame('tenant-a', $hunt->queries()->firstOrFail()->tenant_id);
        $this->assertSame('tenant-a', $hunt->results()->firstOrFail()->tenant_id);
        $this->assertSame('alert-a', $hunt->results()->firstOrFail()->result_data['alert_id']);
    }

    public function test_history_and_detail_hide_other_tenant_hunts(): void
    {
        $huntA = $this->hunt('tenant-a', 'Visible Hunt A');
        $huntB = $this->hunt('tenant-b', 'Hidden Hunt B');

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/threat-hunts')
            ->assertOk()
            ->assertJsonFragment(['hunt_id' => $huntA->hunt_id])
            ->assertJsonMissing(['hunt_id' => $huntB->hunt_id]);

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/threat-hunts/'.$huntB->hunt_id)
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->getJson('/api/threat-hunts/'.$huntB->hunt_id.'/results')
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get('/threat-hunts/'.$huntB->hunt_id)
            ->assertNotFound();
    }

    public function test_model_backed_hunt_filters_rows_before_snapshotting(): void
    {
        $tenantAAgent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-a']);
        EndpointAgent::factory()->create(['tenant_id' => 'tenant-b']);

        $hunt = $this->hunting->executeHunt([
            'query_domain' => 'endpoint_agents',
            'query_filters' => [],
            'title' => 'Tenant A Endpoint Hunt',
            'tenant_id' => 'tenant-a',
        ], $this->admin);

        $this->assertSame(1, $hunt->result_count);
        $this->assertSame($tenantAAgent->id, $hunt->results()->sole()->result_source_id);
    }

    public function test_hunt_is_written_once_with_final_append_only_state(): void
    {
        $this->alert('alert-a', 'tenant-a');
        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            $sql = strtolower(trim($query->sql));
            if (str_starts_with($sql, 'update "threat_hunts"')
                || str_starts_with($sql, 'update threat_hunts')) {
                $updates[] = $query->sql;
            }
        });

        $hunt = $this->hunt('tenant-a', 'Append Only Hunt');

        $this->assertSame(ThreatHunt::STATUS_COMPLETED, $hunt->status);
        $this->assertSame(1, $hunt->result_count);
        $this->assertSame([], $updates, 'Append-only threat_hunts must not be updated after insert.');
    }

    public function test_host_pivot_cannot_access_other_tenant_agent(): void
    {
        $agent = EndpointAgent::factory()->create(['tenant_id' => 'tenant-b']);

        $result = $this->hunting->pivotHost($agent->agent_id, 'tenant-a');
        $this->assertSame(['error' => 'agent_not_found'], $result);
    }

    public function test_unisolated_domain_fails_closed_without_creating_hunt(): void
    {
        $before = ThreatHunt::count();

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->postJson('/api/threat-hunts/query', [
                'query_domain' => 'processes',
                'query_filters' => [],
                'title' => 'Must Not Persist',
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => "Hunt domain 'processes' is unavailable in tenant mode because 'endpoint_process_entries' has no enforced tenant boundary"]);

        $this->assertSame($before, ThreatHunt::count());
    }

    public function test_strict_mode_requires_tenant_context_for_non_admin(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($analyst)
            ->getJson('/api/threat-hunts')
            ->assertForbidden();
    }

    private function hunt(string $tenantId, string $title): ThreatHunt
    {
        return $this->hunting->executeHunt([
            'query_domain' => 'alerts',
            'query_filters' => [],
            'title' => $title,
            'tenant_id' => $tenantId,
        ], $this->admin);
    }

    private function alert(string $alertId, string $tenantId): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'tenant_id' => $tenantId,
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
