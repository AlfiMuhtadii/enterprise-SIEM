<?php

namespace App\Services;

use App\Models\SoarApprovalRequest;
use App\Models\SoarExecutionAudit;
use App\Models\SoarExecutionPlan;
use App\Models\SoarExecutionResult;
use App\Models\SoarExecutionStep;
use App\Models\SoarPlaybook;
use App\Models\SoarPlaybookVersion;
use App\Models\SoarRollbackPlan;
use App\Models\SoarSimulationResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * SOAR Governance & Response Orchestration Service — Phase 1.
 *
 * All orchestration is:
 *   - advisory-first
 *   - simulation-first (simulation must run before approval or execution)
 *   - approval-gated (creator cannot self-approve)
 *   - rollback-aware
 *   - fully audited (append-only audit trail)
 *   - replay-safe and deterministic
 *
 * NOT implemented: isolateHost, quarantineHost, executeShell, killProcess,
 * autoRemediate, unrestricted playbook execution, hidden orchestration logic,
 * destructive rollback, automatic approval bypass, autonomous production execution.
 */
class SoarOrchestrationService
{
    // Blast radius weights per action — deterministic, advisory
    private const ACTION_BLAST_WEIGHTS = [
        'notify_analyst'                => 0.0,
        'create_incident'               => 0.05,
        'create_ticket'                 => 0.05,
        'enrich_ioc'                    => 0.05,
        'request_approval'              => 0.0,
        'create_watchlist_entry'        => 0.10,
        'simulate_endpoint_isolation'   => 0.85,
        'simulate_credential_revocation'=> 0.70,
        'simulate_firewall_block'       => 0.65,
        'simulate_token_revocation'     => 0.60,
    ];

    // ─── Playbook lifecycle ──────────────────────────────────────────────────

    public function createPlaybook(
        string  $name,
        array   $allowedActions = [],
        string  $executionScope = 'advisory',
        bool    $simulationRequired = true,
        bool    $dualApproval = true,
        ?string $description = null,
        ?int    $createdBy = null,
        array   $approvalRequirements = []
    ): SoarPlaybook {
        $this->validateAllowedActions($allowedActions);

        $playbook = SoarPlaybook::create([
            'playbook_id'           => 'spb-' . Str::random(32),
            'name'                  => $name,
            'description'           => $description,
            'status'                => 'draft',
            'version'               => '1.0.0',
            'execution_scope'       => $executionScope,
            'allowed_actions'       => $allowedActions ?: SoarPlaybook::ALLOWED_PHASE1_ACTIONS,
            'approval_requirements' => $approvalRequirements ?: null,
            'simulation_required'   => $simulationRequired,
            'dual_approval_required'=> $dualApproval,
            'created_by'            => $createdBy,
        ]);

        $this->snapshotPlaybookVersion($playbook, 'Initial version', $createdBy);
        return $playbook;
    }

    public function activatePlaybook(SoarPlaybook $playbook): void
    {
        $playbook->update(['status' => 'active']);
    }

    public function deprecatePlaybook(SoarPlaybook $playbook): void
    {
        $playbook->update(['status' => 'deprecated']);
    }

    public function snapshotPlaybookVersion(
        SoarPlaybook $playbook,
        ?string      $changeReason = null,
        ?int         $createdBy = null
    ): SoarPlaybookVersion {
        $hash = SoarPlaybookVersion::hashPlaybook($playbook);

        return SoarPlaybookVersion::create([
            'version_id'       => 'spv-' . Str::random(32),
            'playbook_id'      => $playbook->playbook_id,
            'version'          => $playbook->version,
            'version_hash'     => $hash,
            'playbook_snapshot'=> $playbook->toArray(),
            'change_reason'    => $changeReason,
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);
    }

    public function getVersionHistory(string $playbookId): Collection
    {
        return SoarPlaybookVersion::where('playbook_id', $playbookId)
            ->orderBy('created_at')
            ->get();
    }

    // ─── Execution plans ────────────────────────────────────────────────────

    public function createExecutionPlan(
        string  $name,
        ?string $playbookId = null,
        ?string $targetInvestigationId = null,
        ?string $targetEntityId = null,
        ?int    $createdBy = null,
        array   $metadata = []
    ): SoarExecutionPlan {
        $playbook = $playbookId
            ? SoarPlaybook::where('playbook_id', $playbookId)->first()
            : null;

        $plan = SoarExecutionPlan::create([
            'plan_id'                => 'sep-' . Str::random(32),
            'playbook_id'            => $playbookId,
            'name'                   => $name,
            'status'                 => 'draft',
            'target_investigation_id'=> $targetInvestigationId,
            'target_entity_id'       => $targetEntityId,
            'blast_radius_score'     => 0.0,
            'simulation_required'    => $playbook ? $playbook->simulation_required : true,
            'simulation_completed'   => false,
            'dual_approval_required' => $playbook ? $playbook->dual_approval_required : true,
            'rollback_ready'         => false,
            'created_by'             => $createdBy,
            'metadata'               => $metadata ?: null,
        ]);

        $this->appendAudit($plan->plan_id, $playbookId, SoarExecutionAudit::EVENT_PLAN_CREATED, null, 'draft', 'Plan created', $createdBy);
        return $plan;
    }

    public function addStepToplan(
        SoarExecutionPlan $plan,
        string            $stepName,
        string            $actionType,
        int               $stepOrder,
        array             $actionParameters = [],
        ?string           $notes = null
    ): SoarExecutionStep {
        if (!in_array($actionType, SoarPlaybook::ALLOWED_PHASE1_ACTIONS, true)) {
            throw new \InvalidArgumentException("Action type '{$actionType}' is not allowed in SOAR Phase 1");
        }

        return SoarExecutionStep::create([
            'step_id'          => 'ses-' . Str::random(32),
            'plan_id'          => $plan->plan_id,
            'step_order'       => $stepOrder,
            'step_name'        => $stepName,
            'action_type'      => $actionType,
            'status'           => 'pending',
            'is_simulation_only'=> in_array($actionType, SoarPlaybook::SIMULATION_ONLY_ACTIONS, true),
            'action_parameters'=> $actionParameters ?: null,
            'notes'            => $notes,
        ]);
    }

    // ─── Simulation-first execution ──────────────────────────────────────────

    /**
     * Run deterministic simulation of an execution plan.
     * Produces a JSON artifact. Never executes live actions.
     * Advisory-only — all simulation outputs are clearly marked.
     */
    public function runSimulation(
        SoarExecutionPlan $plan,
        ?int              $simulatedBy = null
    ): SoarSimulationResult {
        $steps    = $plan->steps;
        $blastRadius = $this->calculatePlanBlastRadius($steps);
        $warnings = [];

        $stepPreviews = [];
        foreach ($steps as $step) {
            $isSimOnly = in_array($step->action_type, SoarPlaybook::SIMULATION_ONLY_ACTIONS, true);
            if ($isSimOnly) {
                $warnings[] = "Step '{$step->step_name}' ({$step->action_type}) is simulation-only — no live action will execute";
            }
            $stepPreviews[] = [
                'step_id'        => $step->step_id,
                'step_name'      => $step->step_name,
                'action_type'    => $step->action_type,
                'is_simulation'  => $isSimOnly,
                'blast_weight'   => self::ACTION_BLAST_WEIGHTS[$step->action_type] ?? 0.0,
                'preview_status' => 'simulation_preview',
            ];
        }

        $rollbackReady = $blastRadius < 0.7;
        $affectedCount = max(1, (int) round($blastRadius * 10));

        $simResult = SoarSimulationResult::create([
            'sim_result_id'              => 'ssr-' . Str::random(32),
            'plan_id'                    => $plan->plan_id,
            'playbook_id'               => $plan->playbook_id,
            'blast_radius_score'         => $blastRadius,
            'estimated_affected_entities'=> $affectedCount,
            'affected_entity_ids'        => $plan->target_entity_id ? [$plan->target_entity_id] : [],
            'step_previews'              => $stepPreviews,
            'rollback_ready'             => $rollbackReady,
            'approval_required'          => true,
            'warnings'                   => $warnings ?: null,
            'is_advisory'               => true,
            'simulated_by'               => $simulatedBy,
            'created_at'                 => now(),
        ]);

        $plan->update([
            'blast_radius_score'  => $blastRadius,
            'simulation_completed'=> true,
            'rollback_ready'      => $rollbackReady,
            'status'              => 'simulated',
        ]);

        $this->appendAudit($plan->plan_id, $plan->playbook_id, SoarExecutionAudit::EVENT_SIMULATION_COMPLETED, 'simulation_pending', 'simulated',
            "Simulation completed. Blast radius: {$blastRadius}. Rollback ready: " . ($rollbackReady ? 'yes' : 'no'), $simulatedBy);

        return $simResult;
    }

    private function calculatePlanBlastRadius(Collection $steps): float
    {
        if ($steps->isEmpty()) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($steps as $step) {
            $total += self::ACTION_BLAST_WEIGHTS[$step->action_type] ?? 0.0;
        }
        return min(1.0, $total / max(1, $steps->count()));
    }

    // ─── Approval workflow ───────────────────────────────────────────────────

    /**
     * Request approval for an execution plan.
     * Simulation must be completed before approval can be requested.
     * Appends an immutable approval request record.
     */
    public function requestApproval(
        SoarExecutionPlan $plan,
        int               $requestedBy,
        string            $approvalType = 'initial',
        ?string           $rationale = null
    ): SoarApprovalRequest {
        if (!$plan->simulation_completed) {
            throw new \LogicException('Simulation must be completed before requesting approval');
        }

        $gateSnapshot = [
            'blast_radius_score'         => $plan->blast_radius_score,
            'simulation_completed'       => $plan->simulation_completed,
            'rollback_ready'             => $plan->rollback_ready,
            'dual_approval_required'     => $plan->dual_approval_required,
        ];

        $request = SoarApprovalRequest::create([
            'approval_id'  => 'sar-' . Str::random(32),
            'plan_id'      => $plan->plan_id,
            'playbook_id'  => $plan->playbook_id,
            'approval_type'=> $approvalType,
            'decision'     => 'pending',
            'requested_by' => $requestedBy,
            'rationale'    => $rationale,
            'gate_snapshot'=> $gateSnapshot,
            'created_at'   => now(),
        ]);

        $plan->update(['status' => 'approval_pending']);
        $this->appendAudit($plan->plan_id, $plan->playbook_id, SoarExecutionAudit::EVENT_APPROVAL_REQUESTED, 'simulated', 'approval_pending',
            "Approval requested by user {$requestedBy}", $requestedBy);

        return $request;
    }

    /**
     * Grant approval for a plan. Creator CANNOT self-approve.
     * Records decision in an append-only approval request.
     */
    public function grantApproval(
        SoarExecutionPlan $plan,
        int               $decidedBy,
        string            $rationale = ''
    ): SoarApprovalRequest {
        // Creator cannot self-approve
        if ($plan->created_by === $decidedBy) {
            throw new \LogicException('Creator cannot self-approve a SOAR execution plan');
        }

        $record = SoarApprovalRequest::create([
            'approval_id'  => 'sar-' . Str::random(32),
            'plan_id'      => $plan->plan_id,
            'playbook_id'  => $plan->playbook_id,
            'approval_type'=> 'initial',
            'decision'     => 'approved',
            'requested_by' => $plan->created_by,
            'decided_by'   => $decidedBy,
            'rationale'    => $rationale,
            'decided_at'   => now(),
            'created_at'   => now(),
        ]);

        $approverField = $plan->approver_1_id ? 'approver_2_id' : 'approver_1_id';
        $allApproved = $plan->dual_approval_required
            ? ($plan->approver_1_id !== null && $approverField === 'approver_2_id')
            : true;

        $updates = [$approverField => $decidedBy];
        if ($allApproved || !$plan->dual_approval_required) {
            $updates['status']      = 'approved';
            $updates['approved_at'] = now();
        }
        $plan->update($updates);

        $this->appendAudit($plan->plan_id, $plan->playbook_id, SoarExecutionAudit::EVENT_APPROVAL_GRANTED, 'approval_pending', $plan->status,
            "Approval granted by user {$decidedBy}", $decidedBy);

        return $record;
    }

    public function rejectApproval(
        SoarExecutionPlan $plan,
        int               $decidedBy,
        string            $rationale = ''
    ): SoarApprovalRequest {
        $record = SoarApprovalRequest::create([
            'approval_id'  => 'sar-' . Str::random(32),
            'plan_id'      => $plan->plan_id,
            'playbook_id'  => $plan->playbook_id,
            'approval_type'=> 'initial',
            'decision'     => 'rejected',
            'requested_by' => $plan->created_by,
            'decided_by'   => $decidedBy,
            'rationale'    => $rationale,
            'decided_at'   => now(),
            'created_at'   => now(),
        ]);

        $plan->update(['status' => 'cancelled']);
        $this->appendAudit($plan->plan_id, $plan->playbook_id, SoarExecutionAudit::EVENT_APPROVAL_REJECTED, 'approval_pending', 'cancelled',
            "Approval rejected by user {$decidedBy}", $decidedBy);

        return $record;
    }

    // ─── Rollback plan management ────────────────────────────────────────────

    public function createRollbackPlan(
        SoarExecutionPlan $plan,
        array             $rollbackSteps = [],
        string            $rollbackScope = 'advisory',
        array             $dependencies = [],
        ?int              $createdBy = null
    ): SoarRollbackPlan {
        return SoarRollbackPlan::create([
            'rollback_id'             => 'srp-' . Str::random(32),
            'plan_id'                 => $plan->plan_id,
            'rollback_steps'          => $rollbackSteps ?: null,
            'rollback_scope'          => $rollbackScope,
            'rollback_readiness_state'=> 'not_ready',
            'rollback_dependencies'   => $dependencies ?: null,
            'simulation_validated'    => false,
            'approval_required'       => true,
            'created_by'              => $createdBy,
        ]);
    }

    public function validateRollback(SoarRollbackPlan $rollback): void
    {
        $rollback->update([
            'rollback_readiness_state'=> 'validated',
            'simulation_validated'    => true,
        ]);

        SoarExecutionPlan::where('plan_id', $rollback->plan_id)
            ->update(['rollback_ready' => true]);
    }

    // ─── Execution results (append-only) ────────────────────────────────────

    public function recordStepResult(
        SoarExecutionPlan $plan,
        SoarExecutionStep $step,
        bool              $success,
        string            $resultType,
        ?string           $summary = null,
        array             $resultData = [],
        ?int              $createdBy = null
    ): SoarExecutionResult {
        $result = SoarExecutionResult::create([
            'result_id'     => 'ser-' . Str::random(32),
            'plan_id'       => $plan->plan_id,
            'step_id'       => $step->step_id,
            'result_type'   => $resultType,
            'success'       => $success,
            'result_summary'=> $summary,
            'result_data'   => $resultData ?: null,
            'is_simulation' => $step->is_simulation_only,
            'is_advisory'   => true,
            'created_by'    => $createdBy,
            'created_at'    => now(),
        ]);

        $step->update(['status' => $success ? 'completed' : 'failed']);
        $this->appendAudit($plan->plan_id, $plan->playbook_id, SoarExecutionAudit::EVENT_STEP_COMPLETED, null, null,
            "Step '{$step->step_name}' completed (success={$success})", $createdBy);

        return $result;
    }

    // ─── Audit trail (append-only) ───────────────────────────────────────────

    private function appendAudit(
        ?string $planId,
        ?string $playbookId,
        string  $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $description,
        ?int    $actorId
    ): void {
        SoarExecutionAudit::create([
            'audit_id'    => 'sau-' . Str::random(32),
            'plan_id'     => $planId,
            'playbook_id' => $playbookId,
            'event_type'  => $eventType,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'description' => $description,
            'is_advisory' => true,
            'actor_id'    => $actorId,
            'created_at'  => now(),
        ]);
    }

    public function getAuditTrail(string $planId): Collection
    {
        return SoarExecutionAudit::where('plan_id', $planId)
            ->orderBy('created_at')
            ->get();
    }

    // ─── Dashboard stats ─────────────────────────────────────────────────────

    public function getDashboardStats(): array
    {
        return [
            'total_playbooks'          => SoarPlaybook::count(),
            'active_playbooks'         => SoarPlaybook::where('status', 'active')->count(),
            'total_plans'              => SoarExecutionPlan::count(),
            'plans_pending_approval'   => SoarExecutionPlan::where('status', 'approval_pending')->count(),
            'total_simulations'        => SoarSimulationResult::count(),
            'total_approval_requests'  => SoarApprovalRequest::count(),
            'pending_approvals'        => SoarApprovalRequest::where('decision', 'pending')->count(),
            'total_execution_results'  => SoarExecutionResult::count(),
            'total_audit_events'       => SoarExecutionAudit::count(),
            'advisory_only'            => true,
            'autonomous_execution'     => false,
            'simulation_required'      => true,
        ];
    }

    // ─── Validation helpers ──────────────────────────────────────────────────

    private function validateAllowedActions(array $actions): void
    {
        foreach ($actions as $action) {
            if (!in_array($action, SoarPlaybook::ALLOWED_PHASE1_ACTIONS, true)) {
                throw new \InvalidArgumentException("Action '{$action}' is not allowed in SOAR Phase 1");
            }
        }
    }
}
