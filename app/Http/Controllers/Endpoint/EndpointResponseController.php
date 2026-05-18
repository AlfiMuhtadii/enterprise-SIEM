<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointResponseCommand;
use App\Services\EndpointResponseCommandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web UI for the Endpoint Response Approval Framework.
 * All commands require explicit approval before dispatch — no auto-execution.
 */
class EndpointResponseController extends Controller
{
    public function __construct(private EndpointResponseCommandService $commandService) {}

    // -----------------------------------------------------------------------
    // Response queue — commands awaiting approval or recently dispatched
    // -----------------------------------------------------------------------

    public function queue(): View
    {
        $pending = EndpointResponseCommand::whereIn('status', [
            EndpointResponseCommand::STATUS_DRAFT,
            EndpointResponseCommand::STATUS_PENDING_APPROVAL,
            EndpointResponseCommand::STATUS_APPROVED,
            EndpointResponseCommand::STATUS_DISPATCHED,
        ])->with('agent')->orderByDesc('created_at')->paginate(20);

        $recent = EndpointResponseCommand::whereIn('status', [
            EndpointResponseCommand::STATUS_COMPLETED,
            EndpointResponseCommand::STATUS_FAILED,
            EndpointResponseCommand::STATUS_CANCELLED,
        ])->with('agent')->orderByDesc('updated_at')->limit(20)->get();

        return view('endpoint.response-queue', compact('pending', 'recent'));
    }

    // -----------------------------------------------------------------------
    // Command detail + approval panel
    // -----------------------------------------------------------------------

    public function show(string $commandId): View
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)
            ->with(['agent', 'events', 'createdBy', 'approvedBy'])
            ->firstOrFail();

        return view('endpoint.response-detail', compact('command'));
    }

    // -----------------------------------------------------------------------
    // Create command (draft)
    // -----------------------------------------------------------------------

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'agent_id'     => 'required|exists:endpoint_agents,id',
            'command_type' => 'required|string|max:40',
        ]);

        $agent = EndpointAgent::findOrFail($request->input('agent_id'));

        try {
            $command = $this->commandService->createCommand(
                $agent,
                $request->input('command_type'),
                $request->input('parameters', []),
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['command_type' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $command->command_id)
            ->with('success', "Command {$command->command_id} created in DRAFT state.");
    }

    // -----------------------------------------------------------------------
    // Submit for approval
    // -----------------------------------------------------------------------

    public function submit(string $commandId): RedirectResponse
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)->firstOrFail();

        try {
            $this->commandService->submitForApproval($command, request()->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $commandId)
            ->with('success', "Command {$commandId} submitted for approval.");
    }

    // -----------------------------------------------------------------------
    // Approve command
    // -----------------------------------------------------------------------

    public function approve(Request $request, string $commandId): RedirectResponse
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)->firstOrFail();

        try {
            $this->commandService->approve($command, $request->user()->id, $request->input('note'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $commandId)
            ->with('success', "Command {$commandId} approved.");
    }

    // -----------------------------------------------------------------------
    // Reject command
    // -----------------------------------------------------------------------

    public function reject(Request $request, string $commandId): RedirectResponse
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)->firstOrFail();

        try {
            $this->commandService->reject($command, $request->user()->id, $request->input('reason', ''));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $commandId)
            ->with('success', "Command {$commandId} rejected.");
    }

    // -----------------------------------------------------------------------
    // Cancel command
    // -----------------------------------------------------------------------

    public function cancel(string $commandId): RedirectResponse
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)->firstOrFail();

        try {
            $this->commandService->cancel($command, request()->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $commandId)
            ->with('success', "Command {$commandId} cancelled.");
    }

    // -----------------------------------------------------------------------
    // Dispatch approved command to agent
    // -----------------------------------------------------------------------

    public function dispatch(string $commandId): RedirectResponse
    {
        $command = EndpointResponseCommand::where('command_id', $commandId)->firstOrFail();

        try {
            $this->commandService->dispatch($command, request()->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('endpoint.response.show', $commandId)
            ->with('success', "Command {$commandId} dispatched to agent.");
    }
}
