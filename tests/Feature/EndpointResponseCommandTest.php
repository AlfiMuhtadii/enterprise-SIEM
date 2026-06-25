<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointResponseCommand;
use App\Models\EndpointResponseCommandEvent;
use App\Models\SecurityHardeningEvent;
use App\Models\User;
use App\Services\EndpointResponseCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EndpointResponseCommandTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function approverUser(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeAgent(): EndpointAgent
    {
        return EndpointAgent::factory()->create();
    }

    private function svc(): EndpointResponseCommandService
    {
        return app(EndpointResponseCommandService::class);
    }

    // -----------------------------------------------------------------------
    // Schema tests
    // -----------------------------------------------------------------------

    public function test_endpoint_response_commands_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_response_commands'));
    }

    public function test_endpoint_response_command_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('endpoint_response_command_events'));
    }

    public function test_commands_table_has_expected_columns(): void
    {
        foreach ([
            'id', 'command_id', 'agent_id', 'command_type', 'status',
            'parameters', 'result', 'error_message',
            'created_by', 'approved_by', 'approved_at',
            'dispatched_at', 'acknowledged_at', 'completed_at', 'trace_id',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('endpoint_response_commands', $col),
                "Missing column: {$col}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Model constants
    // -----------------------------------------------------------------------

    public function test_allowed_types_never_contain_destructive_commands(): void
    {
        $destructive = ['isolate_host', 'kill_process', 'quarantine_file',
                        'delete_file', 'remove_persistence', 'block_ip',
                        'disable_service', 'wipe_disk'];
        foreach ($destructive as $type) {
            $this->assertNotContains(
                $type, EndpointResponseCommand::ALLOWED_TYPES,
                "Destructive type '{$type}' must not appear in ALLOWED_TYPES"
            );
        }
    }

    public function test_forbidden_types_are_recognised(): void
    {
        $this->assertTrue(EndpointResponseCommand::isForbiddenType('isolate_host'));
        $this->assertTrue(EndpointResponseCommand::isForbiddenType('kill_process'));
        $this->assertTrue(EndpointResponseCommand::isForbiddenType('wipe_disk'));
        $this->assertFalse(EndpointResponseCommand::isForbiddenType('noop'));
        $this->assertFalse(EndpointResponseCommand::isForbiddenType('collect_diagnostics'));
    }

    public function test_allowed_and_forbidden_sets_do_not_overlap(): void
    {
        $overlap = array_intersect(
            EndpointResponseCommand::ALLOWED_TYPES,
            EndpointResponseCommand::FORBIDDEN_TYPES
        );
        $this->assertEmpty($overlap, 'ALLOWED_TYPES and FORBIDDEN_TYPES must not overlap');
    }

    // -----------------------------------------------------------------------
    // Command creation
    // -----------------------------------------------------------------------

    public function test_create_command_produces_draft_status(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop');
        $this->assertEquals(EndpointResponseCommand::STATUS_DRAFT, $command->status);
    }

    public function test_create_command_generates_cmd_id_format(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop');
        $this->assertMatchesRegularExpression('/^CMD-\d{4}-\d{5}$/', $command->command_id);
    }

    public function test_create_command_appends_created_event(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'collect_diagnostics');
        $events = EndpointResponseCommandEvent::where('command_id', $command->id)->get();
        $this->assertCount(1, $events);
        $this->assertEquals(EndpointResponseCommandEvent::EVENT_CREATED, $events->first()->event_type);
    }

    public function test_forbidden_command_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Ff]orbidden/');
        $this->svc()->createCommand($this->makeAgent(), 'isolate_host');
    }

    public function test_unsupported_command_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->createCommand($this->makeAgent(), 'unknown_action');
    }

    // -----------------------------------------------------------------------
    // State machine transitions
    // -----------------------------------------------------------------------

    public function test_submit_for_approval_transitions_to_pending(): void
    {
        $user    = $this->adminUser();
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop', [], $user->id);
        $command = $this->svc()->submitForApproval($command, $user->id);
        $this->assertEquals(EndpointResponseCommand::STATUS_PENDING_APPROVAL, $command->status);
    }

    public function test_approve_sets_approved_by_and_timestamp(): void
    {
        $user     = $this->adminUser();
        $approver = $this->approverUser();
        $agent    = $this->makeAgent();
        $command  = $this->svc()->createCommand($agent, 'noop', [], $user->id);
        $command  = $this->svc()->submitForApproval($command, $user->id);
        $command  = $this->svc()->approve($command, $approver->id, 'looks good');

        $this->assertEquals(EndpointResponseCommand::STATUS_APPROVED, $command->status);
        $this->assertEquals($approver->id, $command->approved_by);
        $this->assertNotNull($command->approved_at);
    }

    public function test_command_requires_approval_before_dispatch(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop');
        // Attempt to dispatch a DRAFT command — must throw
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/APPROVED/');
        $this->svc()->dispatch($command);
    }

    public function test_dispatch_only_allowed_from_approved_state(): void
    {
        $user    = $this->adminUser();
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop', [], $user->id);
        $command = $this->svc()->submitForApproval($command, $user->id);
        // pending_approval → dispatch must throw (skipped approval)
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->dispatch($command);
    }

    public function test_full_lifecycle_draft_to_completed(): void
    {
        $user     = $this->adminUser();
        $approver = $this->approverUser();
        $agent    = $this->makeAgent();
        $command  = $this->svc()->createCommand($agent, 'noop', [], $user->id);
        $command  = $this->svc()->submitForApproval($command, $user->id);
        $command  = $this->svc()->approve($command, $approver->id);
        $command  = $this->svc()->dispatch($command, $approver->id);

        // Ack from agent (no real signature in unit test — verifyAgentSignature returns false
        // for empty token; that's expected and hardening event is logged)
        $command = $this->svc()->acknowledge($command, $agent->agent_id, '', '');
        $command = $this->svc()->receiveResult(
            $command, $agent->agent_id, '', '',
            ['status' => 'completed', 'result' => ['message' => 'noop done']]
        );

        $this->assertEquals(EndpointResponseCommand::STATUS_COMPLETED, $command->status);
    }

    public function test_reject_transitions_to_cancelled(): void
    {
        $user    = $this->adminUser();
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop', [], $user->id);
        $command = $this->svc()->submitForApproval($command, $user->id);
        $command = $this->svc()->reject($command, $user->id, 'not needed');
        $this->assertEquals(EndpointResponseCommand::STATUS_CANCELLED, $command->status);
    }

    public function test_cancel_from_draft_is_valid(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop');
        $command = $this->svc()->cancel($command, $this->adminUser()->id);
        $this->assertEquals(EndpointResponseCommand::STATUS_CANCELLED, $command->status);
    }

    public function test_invalid_state_transition_throws(): void
    {
        $command = $this->svc()->createCommand($this->makeAgent(), 'noop');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid transition/');
        // completed → pending_approval is invalid
        $this->svc()->submitForApproval($command->fresh(), 1);
        $command->update(['status' => 'completed']);
        $this->svc()->submitForApproval($command->fresh(), 1);
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    public function test_all_transitions_are_audited(): void
    {
        $user     = $this->adminUser();
        $approver = $this->approverUser();
        $agent    = $this->makeAgent();
        $command  = $this->svc()->createCommand($agent, 'collect_diagnostics', [], $user->id);
        $command  = $this->svc()->submitForApproval($command, $user->id);
        $command  = $this->svc()->approve($command, $approver->id);
        $command  = $this->svc()->dispatch($command, $approver->id);

        $events = EndpointResponseCommandEvent::where('command_id', $command->id)
            ->orderBy('occurred_at')
            ->get();

        $types = $events->pluck('event_type')->toArray();
        $this->assertContains(EndpointResponseCommandEvent::EVENT_CREATED,    $types);
        $this->assertContains(EndpointResponseCommandEvent::EVENT_SUBMITTED,  $types);
        $this->assertContains(EndpointResponseCommandEvent::EVENT_APPROVED,   $types);
        $this->assertContains(EndpointResponseCommandEvent::EVENT_DISPATCHED, $types);
    }

    public function test_events_table_has_no_updated_at(): void
    {
        $this->assertNull(EndpointResponseCommandEvent::UPDATED_AT);
    }

    // -----------------------------------------------------------------------
    // Agent API — poll commands
    // -----------------------------------------------------------------------

    public function test_agent_can_poll_dispatched_commands(): void
    {
        $user     = $this->adminUser();
        $approver = $this->approverUser();
        $agent    = $this->makeAgent();
        $command  = $this->svc()->createCommand($agent, 'noop', [], $user->id);
        $this->svc()->submitForApproval($command, $user->id);
        $this->svc()->approve($command->fresh(), $approver->id);
        $this->svc()->dispatch($command->fresh(), $approver->id);

        $response = $this->getJson("/api/agents/{$agent->agent_id}/commands");
        $response->assertStatus(200);
        $response->assertJsonPath('agent_id', $agent->agent_id);
        $data = $response->json('commands');
        $this->assertNotEmpty($data);
        $this->assertEquals('CMD', substr($data[0]['command_id'], 0, 3));
    }

    public function test_agent_poll_returns_empty_when_no_dispatched_commands(): void
    {
        $agent    = $this->makeAgent();
        $response = $this->getJson("/api/agents/{$agent->agent_id}/commands");
        $response->assertStatus(200);
        $response->assertJsonPath('commands', []);
    }

    public function test_agent_poll_unknown_agent_returns_404(): void
    {
        $this->getJson('/api/agents/unknown-agent-xyz/commands')->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Invalid signature is rejected and logged
    // -----------------------------------------------------------------------

    public function test_acknowledge_with_invalid_signature_logs_hardening_event(): void
    {
        $user     = $this->adminUser();
        $approver = $this->approverUser();
        $agent    = $this->makeAgent();
        $command  = $this->svc()->createCommand($agent, 'noop', [], $user->id);
        $this->svc()->submitForApproval($command, $user->id);
        $this->svc()->approve($command->fresh(), $approver->id);
        $this->svc()->dispatch($command->fresh(), $approver->id);

        // Post ack with a bad signature
        $payload = json_encode(['agent_id' => $agent->agent_id, 'command_id' => $command->command_id]);
        $response = $this->call(
            'POST',
            "/api/agents/{$agent->agent_id}/commands/{$command->command_id}/ack",
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AGENT_SIGNATURE' => 'sha256=badhex'],
            $payload
        );
        $response->assertStatus(200); // ack still proceeds but logs event

        $this->assertDatabaseHas('security_hardening_events', [
            'event_type'   => SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE,
            'service_name' => 'endpoint-agent',
        ]);
    }

    // -----------------------------------------------------------------------
    // UI access requires authentication
    // -----------------------------------------------------------------------

    public function test_response_queue_requires_auth(): void
    {
        $this->get('/endpoint-agents/response-queue')->assertRedirect('/login');
    }

    public function test_response_queue_accessible_to_admin(): void
    {
        $this->actingAs($this->adminUser())
             ->get('/endpoint-agents/response-queue')
             ->assertStatus(200);
    }
}
