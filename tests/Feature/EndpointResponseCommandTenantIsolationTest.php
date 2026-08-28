<?php

namespace Tests\Feature;

use App\Models\EndpointAgent;
use App\Models\EndpointResponseCommand;
use App\Models\User;
use App\Services\EndpointResponseCommandService;
use App\Services\TenantBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EndpointResponseCommandTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EndpointResponseCommandService $commands;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xdr.tenancy.strict_mode' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->commands = app(EndpointResponseCommandService::class);
    }

    public function test_command_tables_are_registered_as_mutable_tenant_resources(): void
    {
        foreach (['endpoint_response_commands', 'agent_commands'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'));
            $this->assertContains($table, TenantBoundaryService::ISOLATED_TABLES);
            $this->assertContains($table, TenantBoundaryService::MUTABLE_TABLES);
            $this->assertNotContains($table, TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
        }
    }

    public function test_command_creation_inherits_agent_tenant(): void
    {
        $agent = $this->agent('tenant-a');

        $command = $this->commands->createCommand($agent, 'noop', [], $this->admin->id);

        $this->assertSame('tenant-a', $command->tenant_id);
        $this->assertDatabaseHas('endpoint_response_commands', [
            'command_id' => $command->command_id,
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function test_response_queue_only_renders_active_tenant_commands(): void
    {
        $pendingA = $this->commands->createCommand($this->agent('tenant-a'), 'noop');
        $pendingB = $this->commands->createCommand($this->agent('tenant-b'), 'noop');
        $recentA = $this->commands->createCommand($this->agent('tenant-a'), 'noop');
        $recentB = $this->commands->createCommand($this->agent('tenant-b'), 'noop');
        $recentA->update(['status' => EndpointResponseCommand::STATUS_CANCELLED]);
        $recentB->update(['status' => EndpointResponseCommand::STATUS_CANCELLED]);

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('endpoint.response.queue'))
            ->assertOk()
            ->assertSee($pendingA->command_id)
            ->assertSee($recentA->command_id)
            ->assertDontSee($pendingB->command_id)
            ->assertDontSee($recentB->command_id);
    }

    public function test_detail_and_every_transition_reject_other_tenant_command(): void
    {
        $command = $this->commands->createCommand(
            $this->agent('tenant-b'),
            'noop',
            [],
            $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('endpoint.response.show', $command->command_id))
            ->assertForbidden();

        foreach (['submit', 'approve', 'reject', 'cancel', 'dispatch'] as $action) {
            $this->actingAs($this->admin)
                ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
                ->post(route('endpoint.response.'.$action, $command->command_id))
                ->assertForbidden();
        }

        $this->assertSame(EndpointResponseCommand::STATUS_DRAFT, $command->fresh()->status);
    }

    public function test_store_rejects_agent_from_another_tenant(): void
    {
        $agent = $this->agent('tenant-b');

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post(route('endpoint.response.store'), [
                'agent_id' => $agent->id,
                'command_type' => 'noop',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('endpoint_response_commands', ['agent_id' => $agent->id]);
    }

    public function test_store_persists_matching_tenant(): void
    {
        $agent = $this->agent('tenant-a');

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->post(route('endpoint.response.store'), [
                'agent_id' => $agent->id,
                'command_type' => 'noop',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('endpoint_response_commands', [
            'agent_id' => $agent->id,
            'tenant_id' => 'tenant-a',
            'command_type' => 'noop',
        ]);
    }

    public function test_soc_agent_command_history_only_renders_active_tenant(): void
    {
        $this->legacyCommand('legacy-command-visible-a', 'tenant-a');
        $this->legacyCommand('legacy-command-hidden-b', 'tenant-b');

        $this->actingAs($this->admin)
            ->withHeaders(['X-Tenant-ID' => 'tenant-a'])
            ->get(route('soc.agents'))
            ->assertOk()
            ->assertSee('legacy-command-visible-a')
            ->assertDontSee('legacy-command-hidden-b');
    }

    private function agent(string $tenantId): EndpointAgent
    {
        return EndpointAgent::factory()->create(['tenant_id' => $tenantId]);
    }

    private function legacyCommand(string $commandId, string $tenantId): void
    {
        DB::table('agent_commands')->insert([
            'command_id' => $commandId,
            'agent_id' => 'agent-'.$tenantId,
            'tenant_id' => $tenantId,
            'command_type' => 'collect-now',
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
