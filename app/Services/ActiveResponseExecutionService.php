<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\ResponseExecution;
use App\Models\ResponseExecutionEvent;
use App\Models\ResponseExecutionRollback;
use App\Models\ResponseExecutionSimulation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Controlled Active Response Execution Service — Phase 2.
 *
 * All execution is manually approved, operator-driven, and fully audited.
 * No autonomous execution. No mass fanout. No unrestricted shell execution.
 * Single target per execution. Simulation required before execution.
 * Creator cannot self-approve.
 */
class ActiveResponseExecutionService
{
    // Action severity weights for blast radius calculation — deterministic
    private const ACTION_SEVERITY = [
        ResponseExecution::ACTION_REVOKE_SESSION => 0.30,
        ResponseExecution::ACTION_DISABLE_USER   => 0.70,
        ResponseExecution::ACTION_ISOLATE_HOST   => 0.90,
        ResponseExecution::ACTION_BLOCK_IP       => 0.60,
        ResponseExecution::ACTION_BLOCK_DOMAIN   => 0.60,
    ];

    // -----------------------------------------------------------------------
    // Execution lifecycle methods
    // -----------------------------------------------------------------------

    /**
     * Create a new response execution in draft state.
     * Validates action type, sets blast radius and safety scores.
     */
    public function createExecution(array $data, User $creator): ResponseExecution
    {
        $actionType = $data['action_type'] ?? '';
        if (!in_array($actionType, ResponseExecution::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException("Action type '{$actionType}' is not allowed. Allowed: " . implode(', ', ResponseExecution::ALLOWED_ACTIONS));
        }

        $requiresDual  = in_array($actionType, ResponseExecution::DUAL_APPROVAL_REQUIRED, true);
        $rollbackSupported = in_array($actionType, ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS, true);
        $blastRadius   = $this->calculateBlastRadius($actionType, $data['target_entity_key'] ?? '');
        $safetyScore   = $this->calculateSafetyScore($actionType, $blastRadius);
        $confidence    = $this->calculateConfidenceScore($actionType);

        $exec = ResponseExecution::create([
            'execution_id'              => ResponseExecution::generateExecutionId(),
            'action_type'               => $actionType,
            'target_entity_type'        => $data['target_entity_type'] ?? '',
            'target_entity_key'         => substr($data['target_entity_key'] ?? '', 0, 255),
            'target_entity_id'          => $this->resolveEntityId($data['target_entity_type'] ?? '', $data['target_entity_key'] ?? ''),
            'created_by'                => $creator->id,
            'status'                    => ResponseExecution::STATUS_DRAFT,
            'requires_dual_approval'    => $requiresDual,
            'approval_expires_at'       => now()->addHours(ResponseExecution::APPROVAL_EXPIRES_HOURS),
            'simulation_required'       => true,
            'execution_timeout_seconds' => ResponseExecution::EXECUTION_TIMEOUT_SECONDS,
            'rollback_supported'        => $rollbackSupported,
            'blast_radius_score'        => $blastRadius,
            'execution_safety_score'    => $safetyScore,
            'execution_confidence_score'=> $confidence,
            'hunt_evidence'             => $data['hunt_evidence'] ?? [],
            'correlation_ids'           => $data['correlation_ids'] ?? [],
            'rationale'                 => substr($data['rationale'] ?? '', 0, 2000),
            'notes'                     => $data['notes'] ?? null,
            'trace_id'                  => 'trace-' . Str::uuid(),
        ]);

        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_SUBMITTED, null, ResponseExecution::STATUS_DRAFT, $creator, ['blast_radius_score' => $blastRadius]);
        return $exec;
    }

    /**
     * Submit a draft execution for approval.
     */
    public function submitForApproval(ResponseExecution $exec, User $actor): ResponseExecution
    {
        $this->enforceTransition($exec, ResponseExecution::STATUS_PENDING_APPROVAL, $actor);
        $exec->update(['status' => ResponseExecution::STATUS_PENDING_APPROVAL]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_SUBMITTED, ResponseExecution::STATUS_DRAFT, ResponseExecution::STATUS_PENDING_APPROVAL, $actor);
        return $exec->fresh();
    }

    /**
     * Approve an execution. Creator CANNOT self-approve.
     * For dual-approval actions, a second distinct approver is required.
     */
    public function approve(ResponseExecution $exec, User $approver, string $rationale = ''): ResponseExecution
    {
        if ($exec->status !== ResponseExecution::STATUS_PENDING_APPROVAL) {
            throw new \InvalidArgumentException("Execution must be in pending_approval state to approve.");
        }
        if ($exec->isApprovalExpired()) {
            throw new \InvalidArgumentException("Approval window has expired.");
        }
        if ($exec->created_by === $approver->id) {
            throw new \InvalidArgumentException("Creator cannot self-approve. A different operator must approve.");
        }

        if ($exec->approver_1_id === null) {
            // First approval
            $exec->update([
                'approver_1_id'          => $approver->id,
                'approver_1_approved_at' => now(),
                'approver_1_rationale'   => substr($rationale, 0, 500),
            ]);
            $this->appendEvent($exec, ResponseExecutionEvent::EVENT_APPROVED, null, null, $approver, ['approval_slot' => 1, 'rationale' => $rationale]);

            if (!$exec->requires_dual_approval) {
                $exec->update(['status' => ResponseExecution::STATUS_APPROVED]);
                $this->appendEvent($exec, ResponseExecutionEvent::EVENT_APPROVED, ResponseExecution::STATUS_PENDING_APPROVAL, ResponseExecution::STATUS_APPROVED, $approver);
            }
        } else {
            // Second approval — must be a different person from approver 1 and creator
            if ($exec->approver_1_id === $approver->id) {
                throw new \InvalidArgumentException("Second approver must be different from the first approver.");
            }
            $exec->update([
                'approver_2_id'          => $approver->id,
                'approver_2_approved_at' => now(),
                'approver_2_rationale'   => substr($rationale, 0, 500),
                'status'                 => ResponseExecution::STATUS_APPROVED,
            ]);
            $this->appendEvent($exec, ResponseExecutionEvent::EVENT_APPROVED, ResponseExecution::STATUS_PENDING_APPROVAL, ResponseExecution::STATUS_APPROVED, $approver, ['approval_slot' => 2, 'rationale' => $rationale]);
        }

        return $exec->fresh();
    }

    /**
     * Reject an execution (transitions to cancelled).
     */
    public function reject(ResponseExecution $exec, User $actor, string $reason = ''): ResponseExecution
    {
        if ($exec->created_by === $actor->id) {
            throw new \InvalidArgumentException("Creator cannot reject their own execution. Use cancel() instead.");
        }
        $exec->update(['status' => ResponseExecution::STATUS_CANCELLED]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_REJECTED, $exec->status, ResponseExecution::STATUS_CANCELLED, $actor, ['reason' => $reason]);
        return $exec->fresh();
    }

    /**
     * Run simulation — NEVER mutates infrastructure. Computes blast radius only.
     * Transitions: approved → simulation_ready → simulated.
     */
    public function runSimulation(ResponseExecution $exec, User $actor): ResponseExecutionSimulation
    {
        if ($exec->status !== ResponseExecution::STATUS_APPROVED) {
            throw new \InvalidArgumentException("Execution must be in approved state to simulate.");
        }

        $exec->update(['status' => ResponseExecution::STATUS_SIMULATION_READY]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_SIMULATION_STARTED, ResponseExecution::STATUS_APPROVED, ResponseExecution::STATUS_SIMULATION_READY, $actor);

        // Compute blast radius entities — deterministic, read-only
        $blastEntities  = $this->computeBlastRadiusEntities($exec);
        $impactedServices = $this->estimateImpactedServices($exec);
        $warnings       = $this->generateSimulationWarnings($exec);
        $rollbackAvail  = in_array($exec->action_type, ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS, true);

        $sim = ResponseExecutionSimulation::create([
            'simulation_id'        => ResponseExecutionSimulation::generateSimulationId(),
            'execution_id'         => $exec->id,
            'simulated_by'         => $actor->id,
            'blast_radius_entities'=> $blastEntities,
            'impacted_services'    => $impactedServices,
            'rollback_available'   => $rollbackAvail,
            'estimated_impact_score'=> $exec->blast_radius_score,
            'simulation_notes'     => "Simulation completed for {$exec->action_type} against {$exec->target_entity_key}. No infrastructure changes made.",
            'warnings'             => $warnings,
            'trace_id'             => $exec->trace_id,
        ]);

        $exec->update([
            'status'                  => ResponseExecution::STATUS_SIMULATED,
            'simulation_completed_at' => now(),
        ]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_SIMULATION_DONE, ResponseExecution::STATUS_SIMULATION_READY, ResponseExecution::STATUS_SIMULATED, $actor, ['simulation_id' => $sim->simulation_id]);

        return $sim;
    }

    /**
     * Request execution (simulated → execution_ready).
     * Enforces simulation-first constraint.
     */
    public function requestExecution(ResponseExecution $exec, User $actor): ResponseExecution
    {
        if ($exec->status !== ResponseExecution::STATUS_SIMULATED) {
            throw new \InvalidArgumentException("Simulation must be completed before requesting execution.");
        }
        if (!$exec->simulation_completed_at) {
            throw new \InvalidArgumentException("No simulation record found. Run simulation first.");
        }

        $exec->update(['status' => ResponseExecution::STATUS_EXECUTION_READY]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_EXECUTION_REQUESTED, ResponseExecution::STATUS_SIMULATED, ResponseExecution::STATUS_EXECUTION_READY, $actor);
        return $exec->fresh();
    }

    /**
     * Execute the action. Records execution — operator confirms they performed the action.
     * No autonomous infrastructure commands. Single-target only.
     */
    public function executeAction(ResponseExecution $exec, User $actor, string $confirmationNote = ''): ResponseExecution
    {
        if ($exec->status !== ResponseExecution::STATUS_EXECUTION_READY) {
            throw new \InvalidArgumentException("Execution must be in execution_ready state.");
        }
        if (!$exec->isFullyApproved()) {
            throw new \InvalidArgumentException("Execution is not fully approved.");
        }
        if ($exec->isApprovalExpired()) {
            throw new \InvalidArgumentException("Approval has expired. Re-submit for approval.");
        }

        $rollbackDeadline = in_array($exec->action_type, ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS, true)
            ? now()->addHours(ResponseExecution::ROLLBACK_DEADLINE_HOURS)
            : null;

        $exec->update([
            'status'                  => ResponseExecution::STATUS_EXECUTED,
            'execution_started_at'    => now(),
            'execution_completed_at'  => now(),
            'rollback_deadline_at'    => $rollbackDeadline,
        ]);

        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_EXECUTION_DONE,
            ResponseExecution::STATUS_EXECUTION_READY, ResponseExecution::STATUS_EXECUTED,
            $actor, [
                'confirmation_note' => substr($confirmationNote, 0, 500),
                'executed_at'       => now()->toIso8601String(),
                'rollback_supported'=> $exec->rollback_supported,
                'rollback_deadline' => $rollbackDeadline?->toIso8601String(),
                'advisory_note'     => 'Execution recorded by operator. No autonomous system action.',
            ]
        );

        $this->projectEntityRelationships($exec, 'response_executed_against_entity');
        return $exec->fresh();
    }

    /**
     * Initiate rollback for a reversible executed action.
     */
    public function initiateRollback(ResponseExecution $exec, User $actor): ResponseExecutionRollback
    {
        if ($exec->status !== ResponseExecution::STATUS_EXECUTED) {
            throw new \InvalidArgumentException("Rollback can only be initiated on executed actions.");
        }
        if (!$exec->rollback_supported) {
            throw new \InvalidArgumentException("Action type '{$exec->action_type}' does not support rollback.");
        }
        if ($exec->rollback_deadline_at && $exec->rollback_deadline_at->isPast()) {
            throw new \InvalidArgumentException("Rollback deadline has passed.");
        }

        $rollback = ResponseExecutionRollback::create([
            'rollback_id'       => ResponseExecutionRollback::generateRollbackId(),
            'execution_id'      => $exec->id,
            'rollback_type'     => ResponseExecutionRollback::TYPE_MANUAL,
            'initiated_by'      => $actor->id,
            'initiated_at'      => now(),
            'status'            => ResponseExecutionRollback::STATUS_INITIATED,
            'rollback_evidence' => ['initiated_by' => $actor->name, 'action_type' => $exec->action_type, 'target' => $exec->target_entity_key],
            'trace_id'          => $exec->trace_id,
        ]);

        $exec->update(['status' => ResponseExecution::STATUS_ROLLBACK_READY]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_ROLLBACK_INITIATED, ResponseExecution::STATUS_EXECUTED, ResponseExecution::STATUS_ROLLBACK_READY, $actor, ['rollback_id' => $rollback->rollback_id]);

        return $rollback;
    }

    /**
     * Complete a rollback.
     */
    public function completeRollback(ResponseExecution $exec, User $actor, string $note = ''): ResponseExecution
    {
        if ($exec->status !== ResponseExecution::STATUS_ROLLBACK_READY) {
            throw new \InvalidArgumentException("Rollback must be in rollback_ready state to complete.");
        }

        $rollback = $exec->rollbacks()->where('status', ResponseExecutionRollback::STATUS_INITIATED)->latest('id')->first();
        if ($rollback) {
            $rollback->update(['completed_at' => now(), 'status' => ResponseExecutionRollback::STATUS_COMPLETED]);
        }

        $exec->update(['status' => ResponseExecution::STATUS_ROLLED_BACK]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_ROLLBACK_DONE, ResponseExecution::STATUS_ROLLBACK_READY, ResponseExecution::STATUS_ROLLED_BACK, $actor, ['note' => substr($note, 0, 500)]);
        $this->projectEntityRelationships($exec, 'response_rolled_back_for_entity');
        return $exec->fresh();
    }

    /**
     * Cancel an execution (allowed before execution).
     */
    public function cancel(ResponseExecution $exec, User $actor, string $reason = ''): ResponseExecution
    {
        if ($exec->isTerminal()) {
            throw new \InvalidArgumentException("Cannot cancel an execution in terminal state: {$exec->status}.");
        }

        $exec->update(['status' => ResponseExecution::STATUS_CANCELLED]);
        $this->appendEvent($exec, ResponseExecutionEvent::EVENT_CANCELLED, $exec->status, ResponseExecution::STATUS_CANCELLED, $actor, ['reason' => substr($reason, 0, 500)]);
        return $exec->fresh();
    }

    // -----------------------------------------------------------------------
    // Query methods — read-only
    // -----------------------------------------------------------------------

    public function getRecentExecutions(int $limit = 30): Collection
    {
        return ResponseExecution::with(['creator'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getPendingApprovals(): Collection
    {
        return ResponseExecution::where('status', ResponseExecution::STATUS_PENDING_APPROVAL)
            ->where(fn ($q) => $q->whereNull('approval_expires_at')->orWhere('approval_expires_at', '>', now()))
            ->with(['creator'])
            ->orderBy('created_at')
            ->get();
    }

    public function getExecution(string $executionId): ?ResponseExecution
    {
        return ResponseExecution::where('execution_id', $executionId)
            ->with(['creator', 'approver1', 'approver2', 'events', 'simulations', 'rollbacks', 'latestSimulation'])
            ->first();
    }

    public function getRollbackCandidates(): Collection
    {
        return ResponseExecution::whereIn('status', [ResponseExecution::STATUS_EXECUTED, ResponseExecution::STATUS_ROLLBACK_READY])
            ->where('rollback_supported', true)
            ->orderByDesc('execution_completed_at')
            ->get();
    }

    // -----------------------------------------------------------------------
    // Scoring — deterministic, explainable
    // -----------------------------------------------------------------------

    public function calculateBlastRadius(string $actionType, string $targetKey): float
    {
        $severity = self::ACTION_SEVERITY[$actionType] ?? 0.5;

        // Entity observation count amplifies blast radius
        $entity = DB::table('entities')->where('entity_key', $targetKey)->first();
        $obsCount = $entity ? min((int) ($entity->observation_count ?? 0), 50) : 0;
        $relCount  = $entity
            ? DB::table('entity_relationships')->where('source_entity_id', $entity->id)->orWhere('target_entity_id', $entity->id)->count()
            : 0;

        $obsBoost = $obsCount > 0 ? min($obsCount * 0.005, 0.15) : 0.0;
        $relBoost  = $relCount > 0 ? min($relCount * 0.003, 0.10) : 0.0;

        return (float) round(min($severity + $obsBoost + $relBoost, 1.0), 2);
    }

    public function calculateSafetyScore(string $actionType, float $blastRadius): float
    {
        // Higher safety score = safer action (inverted blast radius)
        return (float) round(max(1.0 - $blastRadius, 0.05), 2);
    }

    public function calculateConfidenceScore(string $actionType): float
    {
        return match ($actionType) {
            ResponseExecution::ACTION_REVOKE_SESSION => 0.90,
            ResponseExecution::ACTION_BLOCK_IP       => 0.80,
            ResponseExecution::ACTION_BLOCK_DOMAIN   => 0.75,
            ResponseExecution::ACTION_DISABLE_USER   => 0.85,
            ResponseExecution::ACTION_ISOLATE_HOST   => 0.70,
            default                                   => 0.50,
        };
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function computeBlastRadiusEntities(ResponseExecution $exec): array
    {
        $entity = DB::table('entities')->where('entity_key', $exec->target_entity_key)->first();
        if (!$entity) {
            return [['entity_key' => $exec->target_entity_key, 'entity_type' => $exec->target_entity_type, 'direct' => true]];
        }

        $related = DB::table('entity_relationships as er')
            ->join('entities as e', fn ($j) => $j->on('e.id', '=', 'er.target_entity_id')->orOn('e.id', '=', 'er.source_entity_id'))
            ->where(fn ($q) => $q->where('er.source_entity_id', $entity->id)->orWhere('er.target_entity_id', $entity->id))
            ->where('e.id', '!=', $entity->id)
            ->select('e.entity_key', 'e.entity_type', 'er.relationship_type')
            ->limit(10)
            ->get();

        $result = [['entity_key' => $entity->entity_key, 'entity_type' => $entity->entity_type, 'direct' => true, 'risk_score' => $entity->risk_score]];
        foreach ($related as $r) {
            $result[] = ['entity_key' => $r->entity_key, 'entity_type' => $r->entity_type, 'direct' => false, 'relationship' => $r->relationship_type];
        }
        return $result;
    }

    private function estimateImpactedServices(ResponseExecution $exec): array
    {
        return match ($exec->action_type) {
            ResponseExecution::ACTION_REVOKE_SESSION => ['session_store', 'authentication'],
            ResponseExecution::ACTION_DISABLE_USER   => ['authentication', 'identity_provider', 'active_directory'],
            ResponseExecution::ACTION_ISOLATE_HOST   => ['endpoint_network', 'host_communications'],
            ResponseExecution::ACTION_BLOCK_IP       => ['network_egress', 'firewall'],
            ResponseExecution::ACTION_BLOCK_DOMAIN   => ['dns_resolver', 'proxy', 'network_egress'],
            default                                   => [],
        };
    }

    private function generateSimulationWarnings(ResponseExecution $exec): array
    {
        $warnings = [];

        if ($exec->blast_radius_score > 0.7) {
            $warnings[] = "High blast radius ({$exec->blast_radius_score}). Review all impacted entities before execution.";
        }
        if ($exec->action_type === ResponseExecution::ACTION_ISOLATE_HOST) {
            $warnings[] = "Host isolation is lab-only. Verify target is not a production system.";
            $warnings[] = "Isolation simulation only — no actual network change will be made.";
        }
        if ($exec->action_type === ResponseExecution::ACTION_DISABLE_USER) {
            $warnings[] = "Disabling a user may affect service accounts and automated processes.";
        }
        if (!$exec->rollback_supported) {
            $warnings[] = "This action type does not support automatic rollback. Manual reversal required.";
        }

        return $warnings;
    }

    private function resolveEntityId(string $type, string $key): ?int
    {
        return DB::table('entities')
            ->where('entity_type', $type)
            ->where('entity_key', $key)
            ->value('id');
    }

    private function appendEvent(
        ResponseExecution $exec,
        string            $eventType,
        ?string           $fromState,
        ?string           $toState,
        ?User             $actor,
        array             $details = []
    ): void {
        ResponseExecutionEvent::create([
            'execution_id' => $exec->id,
            'event_type'   => $eventType,
            'from_state'   => $fromState,
            'to_state'     => $toState,
            'actor_id'     => $actor?->id,
            'actor_name'   => $actor?->name ?? $actor?->email ?? 'system',
            'details'      => $details,
            'trace_id'     => $exec->trace_id,
        ]);
    }

    private function enforceTransition(ResponseExecution $exec, string $newStatus, User $actor): void
    {
        if (!$exec->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException("Cannot transition from '{$exec->status}' to '{$newStatus}'.");
        }
    }

    private function projectEntityRelationships(ResponseExecution $exec, string $relType): void
    {
        if (!$exec->target_entity_id) {
            return;
        }
        $graphService = app(EntityGraphService::class);
        $actor = User::find($exec->created_by);
        if (!$actor) {
            return;
        }
        $actorEntityId = $graphService->upsertEntity('user', $actor->email ?? "user-{$actor->id}", $actor->name ?? $actor->email, now()->toDateTimeString());
        $graphService->upsertRelationship(
            $actorEntityId, $exec->target_entity_id,
            $relType,
            now()->toDateTimeString(), 'active_response_execution', 'response_executions',
            $exec->execution_id, $exec->trace_id, 0.95
        );
    }
}
