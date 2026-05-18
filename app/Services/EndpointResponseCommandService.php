<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointResponseCommand;
use App\Models\EndpointResponseCommandEvent;
use App\Models\SecurityHardeningEvent;
use Illuminate\Support\Str;

/**
 * Manages the endpoint response command lifecycle.
 *
 * Phase 1 safety rules (enforced here AND in agent.py):
 *  - Only ALLOWED_TYPES may be created or dispatched
 *  - Every state transition requires explicit approval (no auto-dispatch)
 *  - All transitions are recorded in append-only command events
 *  - No destructive action is ever created, approved, or dispatched
 */
class EndpointResponseCommandService
{
    // -----------------------------------------------------------------------
    // Command creation
    // -----------------------------------------------------------------------

    /**
     * Create a new command in DRAFT state.
     * Rejects any command_type not in EndpointResponseCommand::ALLOWED_TYPES.
     */
    public function createCommand(
        EndpointAgent $agent,
        string $commandType,
        array $parameters = [],
        ?int $createdBy = null,
        ?string $traceId = null
    ): EndpointResponseCommand {
        if (!EndpointResponseCommand::isAllowedType($commandType)) {
            if (EndpointResponseCommand::isForbiddenType($commandType)) {
                throw new \InvalidArgumentException(
                    "Forbidden command type: '{$commandType}'. Phase 1 prohibits destructive commands."
                );
            }
            throw new \InvalidArgumentException(
                "Unsupported command type: '{$commandType}'. Allowed: " .
                implode(', ', EndpointResponseCommand::ALLOWED_TYPES)
            );
        }

        $command = EndpointResponseCommand::create([
            'command_id'   => EndpointResponseCommand::generateCommandId(),
            'agent_id'     => $agent->id,
            'command_type' => $commandType,
            'status'       => EndpointResponseCommand::STATUS_DRAFT,
            'parameters'   => $parameters,
            'created_by'   => $createdBy,
            'trace_id'     => $traceId ?? (string) Str::uuid(),
        ]);

        $this->appendEvent($command, EndpointResponseCommandEvent::EVENT_CREATED, null,
            EndpointResponseCommand::STATUS_DRAFT, $createdBy ? "user:{$createdBy}" : 'system', $createdBy
        );

        return $command;
    }

    // -----------------------------------------------------------------------
    // State transitions (all require explicit actor)
    // -----------------------------------------------------------------------

    public function submitForApproval(EndpointResponseCommand $command, int $actorId): EndpointResponseCommand
    {
        return $this->transition(
            $command,
            EndpointResponseCommand::STATUS_PENDING_APPROVAL,
            EndpointResponseCommandEvent::EVENT_SUBMITTED,
            "user:{$actorId}",
            $actorId
        );
    }

    public function approve(EndpointResponseCommand $command, int $approverId, ?string $note = null): EndpointResponseCommand
    {
        $this->assertValidTransition($command, EndpointResponseCommand::STATUS_APPROVED);

        $from = $command->status;
        $command->update([
            'status'      => EndpointResponseCommand::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);

        $this->appendEvent(
            $command,
            EndpointResponseCommandEvent::EVENT_APPROVED,
            $from,
            EndpointResponseCommand::STATUS_APPROVED,
            "user:{$approverId}",
            $approverId,
            ['note' => $note]
        );

        return $command->fresh();
    }

    public function reject(EndpointResponseCommand $command, int $actorId, string $reason = ''): EndpointResponseCommand
    {
        return $this->cancel($command, $actorId,
            EndpointResponseCommandEvent::EVENT_REJECTED,
            ['reason' => $reason]
        );
    }

    public function cancel(
        EndpointResponseCommand $command,
        int $actorId,
        string $eventType = EndpointResponseCommandEvent::EVENT_CANCELLED,
        array $details = []
    ): EndpointResponseCommand {
        return $this->transition(
            $command,
            EndpointResponseCommand::STATUS_CANCELLED,
            $eventType,
            "user:{$actorId}",
            $actorId,
            $details
        );
    }

    /**
     * Dispatch an approved command to the agent.
     * Only transitions from APPROVED status — enforces approval gate.
     */
    public function dispatch(EndpointResponseCommand $command, ?int $actorId = null): EndpointResponseCommand
    {
        if ($command->status !== EndpointResponseCommand::STATUS_APPROVED) {
            throw new \InvalidArgumentException(
                "Command must be in APPROVED state before dispatch. Current: {$command->status}"
            );
        }

        $from = $command->status;
        $command->update([
            'status'        => EndpointResponseCommand::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);

        $this->appendEvent(
            $command,
            EndpointResponseCommandEvent::EVENT_DISPATCHED,
            $from,
            EndpointResponseCommand::STATUS_DISPATCHED,
            $actorId ? "user:{$actorId}" : 'system',
            $actorId
        );

        return $command->fresh();
    }

    // -----------------------------------------------------------------------
    // Agent-facing transitions
    // -----------------------------------------------------------------------

    /**
     * Agent acknowledges receipt of a command.
     * Verifies the X-Agent-Signature; logs a hardening event on failure.
     */
    public function acknowledge(
        EndpointResponseCommand $command,
        string $agentId,
        string $signature,
        string $rawPayload
    ): EndpointResponseCommand {
        $sigValid = $this->verifyAgentSignature($signature, $rawPayload);

        if (!$sigValid) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE,
                'endpoint-agent',
                ['command_id' => $command->command_id, 'reason' => 'ack_signature_invalid'],
                $agentId,
                $command->trace_id
            );
        }

        $from = $command->status;
        $command->update([
            'status'          => EndpointResponseCommand::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);

        $this->appendEvent(
            $command,
            EndpointResponseCommandEvent::EVENT_ACKNOWLEDGED,
            $from,
            EndpointResponseCommand::STATUS_ACKNOWLEDGED,
            "agent:{$agentId}",
            null,
            ['signature_valid' => $sigValid]
        );

        return $command->fresh();
    }

    /**
     * Agent submits command result.
     * Verifies signature; transitions to completed or failed based on result status.
     */
    public function receiveResult(
        EndpointResponseCommand $command,
        string $agentId,
        string $signature,
        string $rawPayload,
        array $payload
    ): EndpointResponseCommand {
        $sigValid  = $this->verifyAgentSignature($signature, $rawPayload);
        $succeeded = ($payload['status'] ?? '') === 'completed';

        if (!$sigValid) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE,
                'endpoint-agent',
                ['command_id' => $command->command_id, 'reason' => 'result_signature_invalid'],
                $agentId,
                $command->trace_id
            );
        }

        $newStatus = $succeeded
            ? EndpointResponseCommand::STATUS_COMPLETED
            : EndpointResponseCommand::STATUS_FAILED;

        $from = $command->status;
        $command->update([
            'status'       => $newStatus,
            'result'       => $payload['result'] ?? null,
            'error_message'=> $payload['error'] ?? null,
            'completed_at' => now(),
        ]);

        $eventType = $succeeded
            ? EndpointResponseCommandEvent::EVENT_COMPLETED
            : EndpointResponseCommandEvent::EVENT_FAILED;

        $this->appendEvent(
            $command,
            $eventType,
            $from,
            $newStatus,
            "agent:{$agentId}",
            null,
            ['signature_valid' => $sigValid, 'result_status' => $payload['status'] ?? null]
        );

        return $command->fresh();
    }

    // -----------------------------------------------------------------------
    // Queries
    // -----------------------------------------------------------------------

    /**
     * Get pending commands for an agent (status=dispatched, not yet acknowledged).
     */
    public function getPendingCommands(EndpointAgent $agent): \Illuminate\Database\Eloquent\Collection
    {
        return EndpointResponseCommand::where('agent_id', $agent->id)
            ->where('status', EndpointResponseCommand::STATUS_DISPATCHED)
            ->orderBy('dispatched_at')
            ->get();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function transition(
        EndpointResponseCommand $command,
        string $newStatus,
        string $eventType,
        string $actor,
        ?int $actorId,
        array $details = []
    ): EndpointResponseCommand {
        $this->assertValidTransition($command, $newStatus);
        $from = $command->status;
        $command->update(['status' => $newStatus]);
        $this->appendEvent($command, $eventType, $from, $newStatus, $actor, $actorId, $details);
        return $command->fresh();
    }

    private function assertValidTransition(EndpointResponseCommand $command, string $newStatus): void
    {
        if (!$command->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid transition: {$command->status} → {$newStatus} for command {$command->command_id}"
            );
        }
    }

    public function appendEvent(
        EndpointResponseCommand $command,
        string $eventType,
        ?string $fromState,
        string $toState,
        ?string $actor = null,
        ?int $actorId = null,
        array $details = []
    ): EndpointResponseCommandEvent {
        return EndpointResponseCommandEvent::create([
            'command_id' => $command->id,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state'   => $toState,
            'actor'      => $actor,
            'actor_id'   => $actorId,
            'details'    => empty($details) ? null : $details,
            'trace_id'   => $command->trace_id,
            'occurred_at'=> now(),
            'created_at' => now(),
        ]);
    }

    private function verifyAgentSignature(string $signature, string $rawPayload): bool
    {
        $token = config('soc.agent_enrollment_token', '');
        if ($token === '' || $signature === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $token);
        return hash_equals($expected, $signature);
    }
}
