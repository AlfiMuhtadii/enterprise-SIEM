<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointStreamCheckpoint;
use App\Models\EndpointStreamEvent;
use App\Models\EndpointStreamHealth;
use App\Models\EndpointStreamOffset;
use App\Models\User;
use App\Services\EndpointStreamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Endpoint Streaming Telemetry Phase 1 tests.
 *
 * Explicitly asserts:
 * - No eBPF / kernel module / syscall hooking
 * - No packet sniffing
 * - No autonomous containment
 * - No unrestricted streaming execution
 * - Stream ordering and replay-safety
 * - Sequence gap handling
 * - Append-only guarantees
 * - Bounded replay window
 * - Deterministic replay
 * - Bounded memory enforcement (model constants)
 *
 * Advisory-only: Near-real-time telemetry. Not kernel-level EDR.
 */
class EndpointStreamingTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function svc(): EndpointStreamingService
    {
        return app(EndpointStreamingService::class);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create(['hostname' => 'stream-host-' . uniqid()]);
    }

    private function makeEvents(int $count, string $eventType = EndpointStreamEvent::TYPE_PROCESS_STARTED, int $startSeq = 1): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = [
                'event_id'    => 'sev-' . \Illuminate\Support\Str::uuid(),
                'event_type'  => $eventType,
                'sequence_id' => $startSeq + $i,
                'occurred_at' => now()->subSeconds($count - $i)->toIso8601String(),
                'process_name'=> 'bash',
                'process_pid' => 1000 + $i,
                'trace_id'    => 'trace-test',
            ];
        }
        return $events;
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_endpoint_stream_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_stream_events'));
    }

    public function test_endpoint_stream_offsets_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_stream_offsets'));
    }

    public function test_endpoint_stream_checkpoints_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_stream_checkpoints'));
    }

    public function test_endpoint_stream_health_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_stream_health'));
    }

    public function test_stream_events_table_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('endpoint_stream_events', 'updated_at'),
            'endpoint_stream_events must be append-only (no updated_at)');
    }

    public function test_stream_offsets_table_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('endpoint_stream_offsets', 'updated_at'),
            'endpoint_stream_offsets must be append-only (no updated_at)');
    }

    public function test_stream_checkpoints_table_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('endpoint_stream_checkpoints', 'updated_at'),
            'endpoint_stream_checkpoints must be append-only (no updated_at)');
    }

    public function test_stream_health_table_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('endpoint_stream_health', 'updated_at'),
            'endpoint_stream_health must be append-only (no updated_at)');
    }

    public function test_stream_events_table_has_required_columns(): void
    {
        foreach (['event_id', 'agent_id', 'host_id', 'event_type', 'sequence_id',
                  'batch_id', 'occurred_at', 'trace_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('endpoint_stream_events', $col), "Missing: $col");
        }
    }

    // -----------------------------------------------------------------------
    // Event types
    // -----------------------------------------------------------------------

    public function test_event_types_constant_has_eight_types(): void
    {
        $this->assertCount(8, EndpointStreamEvent::EVENT_TYPES);
    }

    public function test_event_types_include_streaming_types(): void
    {
        $required = [
            'process_started', 'process_terminated', 'outbound_connection_opened',
            'persistence_item_created', 'persistence_item_modified',
            'shell_execution_detected', 'response_execution_ack', 'response_execution_rollback',
        ];
        foreach ($required as $type) {
            $this->assertContains($type, EndpointStreamEvent::EVENT_TYPES, "Missing event type: $type");
        }
    }

    // -----------------------------------------------------------------------
    // Batch ingestion — idempotent and replay-safe
    // -----------------------------------------------------------------------

    public function test_ingest_batch_persists_events(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3);
        $result = $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $this->assertSame(3, $result['accepted']);
        $this->assertSame(0, $result['duplicate']);
        $this->assertSame(3, EndpointStreamEvent::where('agent_id', $agent->agent_id)->count());
    }

    public function test_ingest_batch_is_idempotent_for_duplicate_event_ids(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3);

        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);
        $result2 = $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $this->assertSame(0, $result2['accepted']);
        $this->assertSame(3, $result2['duplicate']);
        // DB count should still be 3 — no duplication
        $this->assertSame(3, EndpointStreamEvent::where('agent_id', $agent->agent_id)->count());
    }

    public function test_ingest_batch_rejects_invalid_event_types(): void
    {
        $agent = $this->makeAgent();
        $events = [['event_id' => 'bad-1', 'event_type' => 'inject_dll', 'sequence_id' => 1]];
        $result = $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $this->assertSame(0, $result['accepted']);
        $this->assertSame(1, $result['invalid']);
    }

    public function test_ingest_batch_enforces_max_batch_size(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(EndpointStreamingService::MAX_BATCH_SIZE + 50);
        $result = $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $this->assertLessThanOrEqual(EndpointStreamingService::MAX_BATCH_SIZE, $result['accepted'],
            'Batch must not exceed MAX_BATCH_SIZE');
    }

    // -----------------------------------------------------------------------
    // Stream ordering and sequence gaps
    // -----------------------------------------------------------------------

    public function test_stream_events_preserve_sequence_order(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(5);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $stored = EndpointStreamEvent::where('agent_id', $agent->agent_id)
            ->orderBy('sequence_id')
            ->pluck('sequence_id')
            ->toArray();

        $this->assertSame($stored, array_unique($stored), 'Sequence IDs must be unique');
        for ($i = 0; $i < count($stored) - 1; $i++) {
            $this->assertLessThan($stored[$i + 1], $stored[$i], 'Sequence IDs must increase');
        }
    }

    public function test_detect_sequence_gaps_returns_gaps(): void
    {
        $agent = $this->makeAgent();
        // Ingest events with a gap: sequences 1,2,3 then 7,8,9 (gap 4-6 missing)
        $eventsA = $this->makeEvents(3, EndpointStreamEvent::TYPE_PROCESS_STARTED, 1);
        $eventsB = $this->makeEvents(3, EndpointStreamEvent::TYPE_PROCESS_STARTED, 7);

        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $eventsA);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $eventsB);

        $gaps = $this->svc()->detectSequenceGaps($agent->agent_id, 3600);
        $this->assertNotEmpty($gaps, 'Should detect gap between sequences 3 and 7');
        $this->assertArrayHasKey('missing_count', $gaps[0]);
    }

    public function test_detect_sequence_gaps_returns_empty_for_contiguous_sequences(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(5, EndpointStreamEvent::TYPE_PROCESS_STARTED, 1);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $gaps = $this->svc()->detectSequenceGaps($agent->agent_id, 3600);
        $this->assertEmpty($gaps, 'No gaps expected for contiguous sequences 1-5');
    }

    // -----------------------------------------------------------------------
    // Replay window — bounded
    // -----------------------------------------------------------------------

    public function test_get_replay_window_returns_events_from_sequence(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(10, EndpointStreamEvent::TYPE_PROCESS_STARTED, 1);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $window = $this->svc()->getReplayWindow($agent->agent_id, 5);
        $this->assertGreaterThan(0, $window->count());
        $this->assertTrue($window->every(fn ($e) => $e->sequence_id >= 5));
    }

    public function test_replay_window_bounded_constant_is_set(): void
    {
        $this->assertSame(300, EndpointStreamingService::REPLAY_WINDOW_SECONDS);
    }

    public function test_get_replay_window_respects_from_sequence(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(10, EndpointStreamEvent::TYPE_PROCESS_STARTED, 1);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $window = $this->svc()->getReplayWindow($agent->agent_id, 6);
        foreach ($window as $ev) {
            $this->assertGreaterThanOrEqual(6, $ev->sequence_id);
        }
    }

    // -----------------------------------------------------------------------
    // Rapid behavioral analytics — advisory-only
    // -----------------------------------------------------------------------

    public function test_detect_rapid_shell_chains_returns_finding(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3, EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $findings = $this->svc()->detectRapidShellChains($agent->agent_id, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('rapid_shell_chain', $findings[0]['finding_type']);
        $this->assertTrue($findings[0]['advisory_only']);
    }

    public function test_detect_burst_outbound_returns_finding(): void
    {
        $agent = $this->makeAgent();
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = [
                'event_id'       => 'sev-' . \Illuminate\Support\Str::uuid(),
                'event_type'     => EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED,
                'sequence_id'    => $i + 1,
                'occurred_at'    => now()->toIso8601String(),
                'connection_dest'=> "192.168.1.$i",
                'connection_port'=> 443,
                'trace_id'       => 'trace-test',
            ];
        }
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $findings = $this->svc()->detectBurstOutbound($agent->agent_id, 300);
        $this->assertNotEmpty($findings);
        $this->assertSame('burst_outbound_activity', $findings[0]['finding_type']);
        $this->assertTrue($findings[0]['advisory_only']);
    }

    public function test_detect_rapid_persistence_creation_returns_finding(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3, EndpointStreamEvent::TYPE_PERSISTENCE_ITEM_CREATED);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $findings = $this->svc()->detectRapidPersistenceCreation($agent->agent_id, 300);
        $this->assertNotEmpty($findings);
        $this->assertTrue($findings[0]['advisory_only']);
    }

    public function test_rapid_analytics_never_trigger_autonomous_response(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(5, EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $findings = $this->svc()->detectRapidShellChains($agent->agent_id, 300);
        foreach ($findings as $f) {
            $this->assertTrue($f['advisory_only'],    'All analytics must be advisory_only');
            $this->assertArrayNotHasKey('execute',    $f, 'Analytics must not contain execute key');
            $this->assertArrayNotHasKey('isolate',    $f, 'Analytics must not trigger isolation');
            $this->assertArrayNotHasKey('kill',       $f, 'Analytics must not contain kill action');
        }
    }

    // -----------------------------------------------------------------------
    // Stream health and checkpoint tracking
    // -----------------------------------------------------------------------

    public function test_ingest_batch_creates_stream_checkpoint(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $checkpoint = EndpointStreamCheckpoint::where('agent_id', $agent->agent_id)->latest('id')->first();
        $this->assertNotNull($checkpoint);
        $this->assertStringStartsWith('chk-', $checkpoint->checkpoint_id);
    }

    public function test_ingest_batch_creates_stream_offset(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $offset = EndpointStreamOffset::latestForAgent($agent->agent_id);
        $this->assertNotNull($offset);
        $this->assertGreaterThan(0, $offset->sequence_id);
    }

    public function test_ingest_batch_creates_stream_health_record(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(3);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $health = EndpointStreamHealth::latestForAgent($agent->agent_id);
        $this->assertNotNull($health);
        $this->assertContains($health->stream_health_state, EndpointStreamHealth::STATES);
    }

    public function test_stream_health_classify_state_returns_healthy_for_low_lag(): void
    {
        $state = EndpointStreamHealth::classifyState(0, 0);
        $this->assertSame(EndpointStreamHealth::STATE_HEALTHY, $state);
    }

    public function test_stream_health_classify_state_returns_lagging(): void
    {
        $state = EndpointStreamHealth::classifyState(EndpointStreamHealth::LAG_LAGGING_SECONDS + 1, 0);
        $this->assertSame(EndpointStreamHealth::STATE_LAGGING, $state);
    }

    public function test_stream_health_classify_state_returns_degraded(): void
    {
        $state = EndpointStreamHealth::classifyState(EndpointStreamHealth::LAG_DEGRADED_SECONDS + 1, 0);
        $this->assertSame(EndpointStreamHealth::STATE_DEGRADED, $state);
    }

    public function test_stream_health_has_four_states(): void
    {
        $this->assertCount(4, EndpointStreamHealth::STATES);
        $this->assertContains('healthy',  EndpointStreamHealth::STATES);
        $this->assertContains('lagging',  EndpointStreamHealth::STATES);
        $this->assertContains('degraded', EndpointStreamHealth::STATES);
        $this->assertContains('offline',  EndpointStreamHealth::STATES);
    }

    // -----------------------------------------------------------------------
    // Append-only guarantees
    // -----------------------------------------------------------------------

    public function test_stream_events_are_never_updated(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(2);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $ev = EndpointStreamEvent::where('agent_id', $agent->agent_id)->first();
        $this->assertNull($ev->updated_at, 'Stream events must have no updated_at (append-only)');
    }

    public function test_stream_offsets_are_never_updated(): void
    {
        $agent  = $this->makeAgent();
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $this->makeEvents(2));

        $offset = EndpointStreamOffset::where('agent_id', $agent->agent_id)->first();
        $this->assertNull($offset->updated_at);
    }

    public function test_stream_checkpoints_are_never_updated(): void
    {
        $agent  = $this->makeAgent();
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $this->makeEvents(2));

        $cp = EndpointStreamCheckpoint::where('agent_id', $agent->agent_id)->first();
        $this->assertNull($cp->updated_at);
    }

    // -----------------------------------------------------------------------
    // Routes and views
    // -----------------------------------------------------------------------

    public function test_activity_feed_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get(route('endpoint.stream.feed'))
             ->assertOk();
    }

    public function test_stream_monitor_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get(route('endpoint.stream.monitor'))
             ->assertOk();
    }

    public function test_stream_health_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get(route('endpoint.stream.health'))
             ->assertOk();
    }

    public function test_burst_activity_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get(route('endpoint.stream.burst'))
             ->assertOk();
    }

    public function test_replay_inspector_accessible_to_admin(): void
    {
        $this->actingAs($this->admin())
             ->get(route('endpoint.stream.replay'))
             ->assertOk();
    }

    public function test_stream_routes_require_authentication(): void
    {
        $this->get(route('endpoint.stream.feed'))->assertRedirect('/login');
    }

    public function test_stream_api_health_endpoint(): void
    {
        $this->actingAs($this->admin())
             ->getJson('/api/endpoint/stream/health')
             ->assertOk()
             ->assertJsonFragment(['advisory_note' => 'Near-real-time advisory telemetry. Not kernel-level EDR.']);
    }

    public function test_stream_api_events_endpoint(): void
    {
        $this->actingAs($this->admin())
             ->getJson('/api/endpoint/stream/events')
             ->assertOk()
             ->assertJsonStructure(['events']);
    }

    // -----------------------------------------------------------------------
    // Safety boundary assertions
    // -----------------------------------------------------------------------

    public function test_streaming_service_has_no_autonomous_containment(): void
    {
        $src = file_get_contents(app_path('Services/EndpointStreamingService.php'));
        $this->assertStringNotContainsString('isolate_host',    $src);
        $this->assertStringNotContainsString('kill_process',    $src);
        $this->assertStringNotContainsString('block_network',   $src);
        $this->assertStringNotContainsString('auto_containment', $src);
    }

    public function test_streaming_service_advisory_note_in_docblock(): void
    {
        $src = file_get_contents(app_path('Services/EndpointStreamingService.php'));
        $this->assertStringContainsString('advisory', strtolower($src));
        $this->assertStringContainsString('no autonomous containment', strtolower($src));
    }

    public function test_all_analytics_findings_have_advisory_only_flag(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(5, EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED);
        $this->svc()->ingestBatch($agent->agent_id, $agent->hostname, $events);

        $findings = $this->svc()->detectRapidShellChains($agent->agent_id, 300);
        foreach ($findings as $f) {
            $this->assertArrayHasKey('advisory_only', $f);
            $this->assertTrue($f['advisory_only']);
        }
    }

    public function test_streaming_rules_in_registry_are_all_shadow(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/detection/rules/registry.v1.json')), true);
        $streamingRules = array_filter($registry['rules'], fn ($r) => str_starts_with($r['rule_id'], 'STREAM_'));

        $this->assertCount(5, $streamingRules, 'Expected exactly 5 STREAM_* rules');
        foreach ($streamingRules as $rule) {
            $this->assertSame('shadow', $rule['status'], "{$rule['rule_id']} must remain shadow");
            $this->assertTrue($rule['shadow_only'],       "{$rule['rule_id']} must have shadow_only=true");
            $this->assertSame('xdr.alerts.shadow.endpoint', $rule['output_topic'],
                "{$rule['rule_id']} must use shadow output topic");
        }
    }

    public function test_stream_ingest_api_accepts_valid_batch(): void
    {
        $agent  = $this->makeAgent();
        $events = $this->makeEvents(2);

        $this->postJson("/api/agents/{$agent->agent_id}/stream-batch", [
            'batch_id' => 'test-batch-1',
            'events'   => $events,
        ])->assertStatus(202)->assertJsonStructure(['accepted', 'duplicate', 'invalid', 'batch_id']);
    }

    public function test_stream_ingest_api_returns_404_for_unknown_agent(): void
    {
        $this->postJson('/api/agents/unknown-agent-id/stream-batch', ['events' => []])
             ->assertStatus(404);
    }
}
