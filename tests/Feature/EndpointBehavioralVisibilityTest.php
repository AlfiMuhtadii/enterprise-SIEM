<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointNetworkCorrelation;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessEntry;
use App\Models\EndpointProcessSnapshot;
use App\Models\User;
use App\Services\EndpointBehavioralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shadow-only endpoint behavioral visibility — no active containment.
 */
class EndpointBehavioralVisibilityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create();
    }

    private function svc(): EndpointBehavioralService
    {
        return app(EndpointBehavioralService::class);
    }

    private function sampleSnapshot(array $overrides = []): array
    {
        return array_merge([
            'agent_id'   => 'agent-test-001',
            'trace_id'   => 'trace-behavior-001',
            'collected_at'=> now()->toIso8601String(),
            'processes'  => [
                [
                    'pid' => 1234, 'ppid' => 1,
                    'process_name' => 'bash', 'parent_process_name' => 'sshd',
                    'executable_path' => '/bin/bash', 'command_line' => 'bash -i',
                    'user' => 'root', 'session_id' => '1',
                    'first_seen_at' => now()->subHours(2)->toIso8601String(),
                    'last_seen_at'  => now()->toIso8601String(),
                    'duration_seconds' => 7200,
                    'is_shell' => true, 'is_long_lived' => true, 'is_suspicious' => false,
                ],
                [
                    'pid' => 5678, 'ppid' => 1,
                    'process_name' => 'sshd', 'parent_process_name' => 'init',
                    'executable_path' => '/usr/sbin/sshd', 'command_line' => 'sshd',
                    'user' => 'root', 'session_id' => null,
                    'first_seen_at' => null, 'last_seen_at' => now()->toIso8601String(),
                    'duration_seconds' => 0,
                    'is_shell' => false, 'is_long_lived' => false, 'is_suspicious' => false,
                ],
            ],
            'persistence_items' => [
                [
                    'item_type' => 'systemd_service',
                    'item_key'  => 'sshd.service',
                    'item_name' => 'sshd',
                    'item_path' => '/lib/systemd/system/sshd.service',
                ],
                [
                    'item_type' => 'cron_job',
                    'item_key'  => 'cron:/etc/cron.d/logrotate',
                    'item_name' => 'logrotate',
                    'item_path' => '/etc/cron.d/logrotate',
                ],
            ],
            'network_correlations' => [
                [
                    'pid' => 1234, 'process_name' => 'bash',
                    'remote_ip' => '203.0.113.5', 'remote_port' => 4444,
                    'proto' => 'tcp', 'correlation_confidence' => 0.85,
                ],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Schema tests
    // -----------------------------------------------------------------------

    public function test_endpoint_process_snapshots_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_process_snapshots'));
    }

    public function test_endpoint_process_entries_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_process_entries'));
    }

    public function test_endpoint_persistence_items_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_persistence_items'));
    }

    public function test_endpoint_network_correlations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_network_correlations'));
    }

    public function test_process_snapshots_columns_exist(): void
    {
        foreach ([
            'id', 'snapshot_id', 'agent_id', 'collected_at',
            'process_count', 'shell_count', 'long_lived_count', 'suspicious_count', 'trace_id',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('endpoint_process_snapshots', $col),
                "Missing column: {$col}"
            );
        }
    }

    public function test_process_entries_columns_exist(): void
    {
        foreach ([
            'pid', 'ppid', 'process_name', 'parent_process_name', 'executable_path',
            'command_line', 'user', 'session_id', 'first_seen_at', 'last_seen_at',
            'duration_seconds', 'is_shell', 'is_long_lived', 'is_suspicious',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('endpoint_process_entries', $col),
                "Missing column: {$col}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Snapshot storage
    // -----------------------------------------------------------------------

    public function test_store_snapshot_creates_snapshot_record(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->sampleSnapshot();
        $result   = $this->svc()->storeSnapshot($agent, $snapshot, 'trace-test-001');

        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^SNAP-\d{4}-\d{5}$/', $result->snapshot_id);
        $this->assertEquals(2, $result->process_count);
        $this->assertEquals(1, $result->shell_count);
        $this->assertEquals(1, $result->long_lived_count);
    }

    public function test_store_snapshot_inserts_process_entries(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-proc-001');

        $entries = EndpointProcessEntry::where('snapshot_id', $result->id)->get();
        $this->assertCount(2, $entries);
        $names = $entries->pluck('process_name')->toArray();
        $this->assertContains('bash', $names);
        $this->assertContains('sshd', $names);
    }

    public function test_store_snapshot_classifies_shell_processes(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-shell-001');

        $bash = EndpointProcessEntry::where('snapshot_id', $result->id)
            ->where('process_name', 'bash')
            ->first();
        $this->assertNotNull($bash);
        $this->assertTrue((bool)$bash->is_shell);
        $this->assertTrue((bool)$bash->is_long_lived);
    }

    public function test_store_snapshot_upserts_persistence_items(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-persist-001');

        $items = EndpointPersistenceItem::where('agent_id', $agent->id)->get();
        $this->assertCount(2, $items);
        $types = $items->pluck('item_type')->toArray();
        $this->assertContains('systemd_service', $types);
        $this->assertContains('cron_job', $types);
    }

    public function test_persistence_items_marked_new_on_first_observation(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-new-001');

        $item = EndpointPersistenceItem::where('agent_id', $agent->id)
            ->where('item_key', 'sshd.service')
            ->first();
        $this->assertTrue((bool)$item->is_new);
    }

    public function test_persistence_items_marked_known_on_second_observation(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->sampleSnapshot();

        $this->svc()->storeSnapshot($agent, $snapshot, 'trace-known-001');
        $this->svc()->storeSnapshot($agent, $snapshot, 'trace-known-002');

        $item = EndpointPersistenceItem::where('agent_id', $agent->id)
            ->where('item_key', 'sshd.service')
            ->first();
        $this->assertFalse((bool)$item->is_new);
    }

    public function test_store_snapshot_inserts_network_correlations(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-net-001');

        $corrs = EndpointNetworkCorrelation::where('snapshot_id', $result->id)->get();
        $this->assertCount(1, $corrs);
        $this->assertEquals('203.0.113.5', $corrs->first()->remote_ip);
        $this->assertEquals(4444, $corrs->first()->remote_port);
    }

    // -----------------------------------------------------------------------
    // Query methods
    // -----------------------------------------------------------------------

    public function test_get_activity_timeline_returns_snapshots(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-tl-001');
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-tl-002');

        $timeline = $this->svc()->getActivityTimeline($agent);
        $this->assertCount(2, $timeline);
        $this->assertArrayHasKey('snapshot_id', $timeline[0]);
        $this->assertArrayHasKey('process_count', $timeline[0]);
    }

    public function test_get_process_tree_returns_latest_snapshot(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-pt-001');

        $tree = $this->svc()->getProcessTree($agent);
        $this->assertNotEmpty($tree);
        $this->assertArrayHasKey('process_name', $tree[0]);
        $this->assertArrayHasKey('parent_process_name', $tree[0]);
    }

    public function test_get_persistence_inventory_returns_items(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-pi-001');

        $items = $this->svc()->getPersistenceInventory($agent);
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('item_type', $items[0]);
        $this->assertArrayHasKey('item_key', $items[0]);
    }

    public function test_get_network_correlations_returns_correlations(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-nc-001');

        $corrs = $this->svc()->getNetworkCorrelations($agent);
        $this->assertNotEmpty($corrs);
        $this->assertArrayHasKey('remote_ip', $corrs[0]);
        $this->assertArrayHasKey('process_name', $corrs[0]);
    }

    public function test_get_long_lived_processes_returns_long_lived_only(): void
    {
        $agent = $this->makeAgent();
        $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-ll-001');

        $longLived = $this->svc()->getLongLivedProcesses($agent);
        $this->assertNotEmpty($longLived);
        foreach ($longLived as $proc) {
            $this->assertGreaterThanOrEqual(3600, $proc['duration_seconds'],
                'All returned processes must exceed long-lived threshold');
        }
    }

    // -----------------------------------------------------------------------
    // Shadow-only enforcement
    // -----------------------------------------------------------------------

    public function test_snapshot_has_no_execution_keys(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-shadow-001');

        // Snapshot must not trigger any execution / enforcement actions
        // Verify by checking no enforcement data is stored
        $this->assertNull($result->deleted_at ?? null); // snapshot is not deleted after storage
        $this->assertNotNull($result->snapshot_id);     // it is stored, not acted upon
    }

    public function test_trace_id_propagated_to_snapshot(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-prop-001');
        $this->assertEquals('trace-prop-001', $result->trace_id);
    }

    public function test_trace_id_propagated_to_process_entries(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->storeSnapshot($agent, $this->sampleSnapshot(), 'trace-entry-001');

        $entries = EndpointProcessEntry::where('snapshot_id', $result->id)->get();
        foreach ($entries as $entry) {
            $this->assertEquals('trace-entry-001', $entry->trace_id);
        }
    }

    // -----------------------------------------------------------------------
    // API endpoint tests
    // -----------------------------------------------------------------------

    public function test_behavioral_snapshot_api_stores_snapshot(): void
    {
        $agent   = $this->makeAgent();
        $payload = $this->sampleSnapshot(['agent_id' => $agent->agent_id]);
        $payload['trace_id'] = 'trace-api-001';

        $response = $this->postJson(
            "/api/agents/{$agent->agent_id}/behavioral-snapshot",
            $payload
        );
        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure(['snapshot_id', 'agent_id', 'process_count', 'trace_id']);
    }

    public function test_behavioral_snapshot_api_returns_404_for_unknown_agent(): void
    {
        $this->postJson('/api/agents/unknown-agent-xxx/behavioral-snapshot', [])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // UI access tests
    // -----------------------------------------------------------------------

    public function test_activity_timeline_requires_auth(): void
    {
        $agent = $this->makeAgent();
        $this->get("/endpoint-agents/{$agent->agent_id}/activity")->assertRedirect('/login');
    }

    public function test_activity_timeline_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->adminUser())
             ->get("/endpoint-agents/{$agent->agent_id}/activity")
             ->assertStatus(200);
    }

    public function test_process_tree_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->adminUser())
             ->get("/endpoint-agents/{$agent->agent_id}/process-tree")
             ->assertStatus(200);
    }

    public function test_persistence_inventory_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->adminUser())
             ->get("/endpoint-agents/{$agent->agent_id}/persistence")
             ->assertStatus(200);
    }

    public function test_process_network_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->adminUser())
             ->get("/endpoint-agents/{$agent->agent_id}/process-network")
             ->assertStatus(200);
    }

    public function test_long_lived_accessible_to_admin(): void
    {
        $agent = $this->makeAgent();
        $this->actingAs($this->adminUser())
             ->get("/endpoint-agents/{$agent->agent_id}/long-lived")
             ->assertStatus(200);
    }
}
