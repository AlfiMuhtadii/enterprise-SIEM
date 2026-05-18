<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessEntry;
use App\Models\EndpointProcessSnapshot;
use App\Models\ThreatHunt;
use App\Models\ThreatHuntQuery;
use App\Models\ThreatHuntResult;
use App\Models\User;
use App\Services\ThreatHuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Threat Hunting & Investigation Query Engine Phase 1.
 * Advisory-only, non-destructive. Append-only hunt history.
 */
class ThreatHuntingQueryEngineTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create();
    }

    private function svc(): ThreatHuntingService
    {
        return app(ThreatHuntingService::class);
    }

    private function makeSnapshot(EndpointAgent $agent): EndpointProcessSnapshot
    {
        return EndpointProcessSnapshot::create([
            'snapshot_id'   => EndpointProcessSnapshot::generateSnapshotId(),
            'agent_id'      => $agent->id,
            'collected_at'  => now(),
            'process_count' => 0,
            'shell_count'   => 0,
            'long_lived_count' => 0,
            'suspicious_count' => 0,
            'trace_id'      => 'trace-hunt-test',
        ]);
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_threat_hunts_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('threat_hunts'));
    }

    public function test_threat_hunt_queries_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('threat_hunt_queries'));
    }

    public function test_threat_hunt_results_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('threat_hunt_results'));
    }

    public function test_threat_hunts_columns_exist(): void
    {
        foreach (['hunt_id', 'title', 'created_by', 'executed_at', 'replay_scope', 'status', 'result_count', 'trace_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('threat_hunts', $col), "Missing column: {$col}");
        }
    }

    // -----------------------------------------------------------------------
    // Query validation (safety enforcement)
    // -----------------------------------------------------------------------

    public function test_query_rejects_unsupported_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->validateQueryFilters('raw_sql_injection', []);
    }

    public function test_query_rejects_unsupported_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->validateQueryFilters('processes', [
            ['field' => 'DROP TABLE endpoint_process_entries', 'operator' => '=', 'value' => '1'],
        ]);
    }

    public function test_query_rejects_unsupported_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->validateQueryFilters('processes', [
            ['field' => 'process_name', 'operator' => 'UNION SELECT', 'value' => 'x'],
        ]);
    }

    public function test_all_supported_domains_are_defined(): void
    {
        foreach (ThreatHuntingService::SUPPORTED_DOMAINS as $domain) {
            $this->assertIsString($domain);
            $this->assertNotEmpty($domain);
        }
    }

    public function test_max_results_enforced(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        // Insert 10 process entries
        for ($i = 0; $i < 10; $i++) {
            EndpointProcessEntry::create([
                'snapshot_id'  => $snapshot->id,
                'agent_id'     => $agent->id,
                'process_name' => "test-process-{$i}",
                'trace_id'     => 'trace-maxresults',
                'created_at'   => now(),
            ]);
        }

        $results = $this->svc()->executeQuery('processes', [], null, null, 3);
        $this->assertLessThanOrEqual(3, $results->count(), 'max_results must be enforced');
    }

    public function test_max_results_cannot_exceed_hard_cap(): void
    {
        $hunt = $this->svc()->executeHunt([
            'query_domain'  => 'processes',
            'query_filters' => [],
            'max_results'   => 9999,  // exceeds hard cap
            'title'         => 'Test cap',
        ]);

        $query = ThreatHuntQuery::where('hunt_id', $hunt->id)->first();
        $this->assertLessThanOrEqual(ThreatHuntingService::MAX_RESULTS, $query->max_results);
    }

    public function test_time_range_window_clamped_to_30_days(): void
    {
        $hunt = $this->svc()->executeHunt([
            'query_domain'     => 'processes',
            'query_filters'    => [],
            'time_range_start' => now()->subDays(90)->toIso8601String(),
            'time_range_end'   => now()->toIso8601String(),
            'title'            => 'Time range clamp test',
        ]);

        $query = ThreatHuntQuery::where('hunt_id', $hunt->id)->first();
        $this->assertNotNull($query->time_range_start);
        $diff = \Carbon\Carbon::parse($query->time_range_start)->diffInDays(
            \Carbon\Carbon::parse($query->time_range_end)
        );
        $this->assertLessThanOrEqual(ThreatHuntingService::MAX_QUERY_WINDOW_DAYS, $diff);
    }

    // -----------------------------------------------------------------------
    // Hunt execution and history
    // -----------------------------------------------------------------------

    public function test_execute_hunt_creates_hunt_record(): void
    {
        $user = $this->admin();
        $hunt = $this->svc()->executeHunt([
            'query_domain'  => 'processes',
            'query_filters' => [],
            'title'         => 'Test Hunt',
        ], $user);

        $this->assertNotNull($hunt);
        $this->assertMatchesRegularExpression('/^HUNT-\d{4}-\d{5}$/', $hunt->hunt_id);
        $this->assertSame($user->id, $hunt->created_by);
    }

    public function test_hunt_creates_query_record(): void
    {
        $hunt = $this->svc()->executeHunt([
            'query_domain'  => 'persistence_items',
            'query_filters' => [['field' => 'item_type', 'operator' => '=', 'value' => 'systemd_service']],
            'title'         => 'Persistence Hunt',
        ]);

        $query = ThreatHuntQuery::where('hunt_id', $hunt->id)->first();
        $this->assertNotNull($query);
        $this->assertEquals('persistence_items', $query->query_domain);
        $this->assertEquals('systemd_service', $query->query_filters[0]['value']);
    }

    public function test_hunt_results_stored_as_snapshots(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        EndpointProcessEntry::create([
            'snapshot_id'  => $snapshot->id,
            'agent_id'     => $agent->id,
            'process_name' => 'bash',
            'is_shell'     => true,
            'trace_id'     => 'trace-bash-hunt',
            'created_at'   => now(),
        ]);

        $hunt = $this->svc()->executeHunt([
            'query_domain'  => 'processes',
            'query_filters' => [['field' => 'process_name', 'operator' => '=', 'value' => 'bash']],
            'title'         => 'Find bash',
        ]);

        $this->assertGreaterThan(0, $hunt->result_count);
        $results = ThreatHuntResult::where('hunt_id', $hunt->id)->get();
        $this->assertNotEmpty($results);
        $this->assertEquals(ThreatHuntResult::TYPE_PROCESS_ENTRY, $results->first()->result_type);
    }

    public function test_hunt_history_is_append_only(): void
    {
        $hunt1 = $this->svc()->executeHunt(['query_domain' => 'processes', 'title' => 'Hunt 1']);
        $hunt2 = $this->svc()->executeHunt(['query_domain' => 'processes', 'title' => 'Hunt 2']);

        // Both records must exist and be unchanged
        $this->assertDatabaseHas('threat_hunts', ['hunt_id' => $hunt1->hunt_id]);
        $this->assertDatabaseHas('threat_hunts', ['hunt_id' => $hunt2->hunt_id]);
        $this->assertEquals(2, ThreatHunt::count());
    }

    public function test_trace_id_propagated_to_hunt(): void
    {
        $hunt = $this->svc()->executeHunt([
            'query_domain' => 'processes',
            'title'        => 'Trace test',
            'trace_id'     => 'trace-hunt-propagation',
        ]);
        $this->assertEquals('trace-hunt-propagation', $hunt->trace_id);
    }

    // -----------------------------------------------------------------------
    // Filter queries
    // -----------------------------------------------------------------------

    public function test_process_name_contains_filter(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        foreach (['curl', 'wget', 'sshd', 'nginx'] as $name) {
            EndpointProcessEntry::create([
                'snapshot_id'  => $snapshot->id, 'agent_id' => $agent->id,
                'process_name' => $name, 'trace_id' => 'trace-x', 'created_at' => now(),
            ]);
        }

        $results = $this->svc()->executeQuery('processes',
            [['field' => 'process_name', 'operator' => 'contains', 'value' => 'curl']],
            null, null, 10
        );
        $names = $results->pluck('process_name')->toArray();
        $this->assertContains('curl', $names);
        $this->assertNotContains('sshd', $names);
    }

    public function test_is_shell_filter(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);

        EndpointProcessEntry::create(['snapshot_id' => $snapshot->id, 'agent_id' => $agent->id,
            'process_name' => 'bash', 'is_shell' => true, 'trace_id' => 't', 'created_at' => now()]);
        EndpointProcessEntry::create(['snapshot_id' => $snapshot->id, 'agent_id' => $agent->id,
            'process_name' => 'sshd', 'is_shell' => false, 'trace_id' => 't', 'created_at' => now()]);

        $results = $this->svc()->executeQuery('processes',
            [['field' => 'is_shell', 'operator' => '=', 'value' => true]],
            null, null, 10
        );
        $this->assertTrue($results->every(fn ($r) => (bool) $r->is_shell));
    }

    public function test_persistence_item_type_filter(): void
    {
        $agent = $this->makeAgent();
        EndpointPersistenceItem::create([
            'agent_id' => $agent->id, 'item_type' => 'systemd_service',
            'item_key' => 'test.service', 'item_name' => 'test',
            'is_new' => true, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        EndpointPersistenceItem::create([
            'agent_id' => $agent->id, 'item_type' => 'cron_job',
            'item_key' => 'cron:/etc/cron.d/test', 'item_name' => 'test',
            'is_new' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $results = $this->svc()->executeQuery('persistence_items',
            [['field' => 'item_type', 'operator' => '=', 'value' => 'systemd_service']],
            null, null, 10
        );
        $this->assertGreaterThan(0, $results->count());
        $this->assertTrue($results->every(fn ($r) => $r->item_type === 'systemd_service'));
    }

    // -----------------------------------------------------------------------
    // Replay
    // -----------------------------------------------------------------------

    public function test_replay_creates_new_hunt_record(): void
    {
        $original = $this->svc()->executeHunt([
            'query_domain' => 'processes', 'title' => 'Original Hunt',
        ]);

        $replay = $this->svc()->replayHunt($original);

        $this->assertNotEquals($original->hunt_id, $replay->hunt_id);
        $this->assertEquals(ThreatHunt::SCOPE_REPLAY, $replay->replay_scope);
        $this->assertStringContainsString($original->hunt_id, $replay->title);
    }

    public function test_replay_does_not_mutate_original(): void
    {
        $original = $this->svc()->executeHunt([
            'query_domain' => 'processes', 'title' => 'Original',
        ]);
        $originalResultCount = $original->result_count;
        $originalTitle       = $original->title;

        $this->svc()->replayHunt($original);

        $fresh = ThreatHunt::where('hunt_id', $original->hunt_id)->first();
        $this->assertEquals($originalResultCount, $fresh->result_count);
        $this->assertEquals($originalTitle, $fresh->title);
    }

    // -----------------------------------------------------------------------
    // Pivots
    // -----------------------------------------------------------------------

    public function test_host_pivot_returns_agent_data(): void
    {
        $agent  = $this->makeAgent();
        $result = $this->svc()->pivotHost($agent->agent_id);
        $this->assertArrayHasKey('agent', $result);
        $this->assertEquals($agent->agent_id, $result['agent']['agent_id']);
    }

    public function test_process_pivot_returns_occurrences(): void
    {
        $agent    = $this->makeAgent();
        $snapshot = $this->makeSnapshot($agent);
        EndpointProcessEntry::create([
            'snapshot_id' => $snapshot->id, 'agent_id' => $agent->id,
            'process_name' => 'curl', 'is_shell' => false, 'trace_id' => 't', 'created_at' => now(),
        ]);

        $result = $this->svc()->pivotProcess('curl');
        $this->assertArrayHasKey('occurrences', $result);
        $this->assertArrayHasKey('outbound_connections', $result);
    }

    public function test_persistence_pivot_returns_item_data(): void
    {
        $agent = $this->makeAgent();
        EndpointPersistenceItem::create([
            'agent_id' => $agent->id, 'item_type' => 'systemd_service',
            'item_key' => 'pivot-test.service', 'item_name' => 'pivot-test',
            'is_new' => true, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $result = $this->svc()->pivotPersistence('pivot-test.service');
        $this->assertArrayHasKey('item_key', $result);
        $this->assertEquals('pivot-test.service', $result['item_key']);
    }

    public function test_trace_pivot_returns_structured_data(): void
    {
        $result = $this->svc()->pivotTrace('trace-test-12345');
        $this->assertArrayHasKey('trace_id', $result);
        $this->assertArrayHasKey('snapshots', $result);
        $this->assertArrayHasKey('findings', $result);
    }

    public function test_trace_pivot_sanitizes_trace_id(): void
    {
        // Malicious trace_id should be sanitized
        $result = $this->svc()->pivotTrace('../../etc/passwd');
        $this->assertArrayHasKey('trace_id', $result);
        // Should be sanitized — no slashes or dots in trace_id
        $this->assertStringNotContainsString('/', $result['trace_id'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Graph traversal
    // -----------------------------------------------------------------------

    public function test_graph_traversal_bounded_by_max_depth(): void
    {
        $result = $this->svc()->graphTraversal(99999, 10); // non-existent entity, depth > MAX
        $this->assertLessThanOrEqual(ThreatHuntingService::MAX_GRAPH_DEPTH, $result['depth']);
    }

    public function test_graph_traversal_returns_structure(): void
    {
        $result = $this->svc()->graphTraversal(99999, 2);
        $this->assertArrayHasKey('root_id', $result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('edges', $result);
        $this->assertArrayHasKey('depth', $result);
    }

    // -----------------------------------------------------------------------
    // Shadow-only enforcement
    // -----------------------------------------------------------------------

    public function test_no_process_kill_in_hunting_service(): void
    {
        $src = file_get_contents(app_path('Services/ThreatHuntingService.php'));
        foreach (['proc_kill', 'posix_kill', 'shell_exec', 'system(', 'exec('] as $pattern) {
            $this->assertStringNotContainsString($pattern, $src,
                "Forbidden pattern in ThreatHuntingService: {$pattern}");
        }
    }

    public function test_no_raw_sql_in_hunting_service(): void
    {
        $src = file_get_contents(app_path('Services/ThreatHuntingService.php'));
        // Should not contain raw SQL string building (only Eloquent methods)
        foreach (['DB::statement', 'DB::unprepared', 'whereRaw(', 'selectRaw('] as $pattern) {
            $this->assertStringNotContainsString($pattern, $src,
                "Raw SQL pattern in ThreatHuntingService: {$pattern}");
        }
    }

    public function test_hunt_records_are_append_only_no_updated_at(): void
    {
        $this->assertNull(ThreatHunt::UPDATED_AT);
        $this->assertNull(ThreatHuntQuery::UPDATED_AT);
        $this->assertNull(ThreatHuntResult::UPDATED_AT);
    }

    // -----------------------------------------------------------------------
    // API endpoints
    // -----------------------------------------------------------------------

    public function test_api_execute_hunt_requires_auth(): void
    {
        $this->postJson('/api/threat-hunts/query', [])->assertStatus(401);
    }

    public function test_api_list_hunts_requires_auth(): void
    {
        $this->getJson('/api/threat-hunts')->assertStatus(401);
    }

    public function test_api_execute_hunt_returns_hunt_id(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/threat-hunts/query', [
                'query_domain'  => 'processes',
                'query_filters' => [],
                'title'         => 'API Test Hunt',
            ]);
        $response->assertStatus(201);
        $response->assertJsonStructure(['hunt_id', 'status', 'result_count', 'trace_id']);
    }

    public function test_api_execute_hunt_rejects_bad_domain(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/threat-hunts/query', [
                'query_domain' => 'invalid_domain_xyz',
                'title'        => 'Bad domain',
            ]);
        $response->assertStatus(422);
    }

    public function test_api_list_hunts_returns_array(): void
    {
        $this->svc()->executeHunt(['query_domain' => 'processes', 'title' => 'Test']);
        $response = $this->actingAs($this->admin())->getJson('/api/threat-hunts');
        $response->assertStatus(200);
        $response->assertJsonStructure(['hunts']);
    }

    public function test_api_get_hunt_returns_detail(): void
    {
        $hunt = $this->svc()->executeHunt(['query_domain' => 'processes', 'title' => 'Detail Test']);
        $response = $this->actingAs($this->admin())->getJson("/api/threat-hunts/{$hunt->hunt_id}");
        $response->assertStatus(200);
        $response->assertJsonPath('hunt_id', $hunt->hunt_id);
    }

    public function test_api_pivot_returns_structure(): void
    {
        $agent    = $this->makeAgent();
        $response = $this->actingAs($this->admin())
            ->getJson("/api/threat-hunts/pivot/host?id={$agent->agent_id}");
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // UI access
    // -----------------------------------------------------------------------

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/threat-hunts')->assertRedirect('/login');
    }

    public function test_dashboard_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/threat-hunts')->assertStatus(200);
    }

    public function test_query_builder_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/threat-hunts/new')->assertStatus(200);
    }

    public function test_beacon_investigation_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/threat-hunts/beacon')->assertStatus(200);
    }

    public function test_persistence_investigation_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/threat-hunts/persistence')->assertStatus(200);
    }

    public function test_chain_explorer_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())->get('/threat-hunts/chains')->assertStatus(200);
    }
}
