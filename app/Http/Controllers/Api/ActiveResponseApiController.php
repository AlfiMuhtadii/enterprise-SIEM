<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResponseExecution;
use App\Services\ActiveResponseExecutionService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Active Response API — controlled manual execution only.
 * No autonomous execution. No mass fanout. All actions require approval.
 *
 * ENT-TENANCY-RESPONSE-EXECUTION: every read resolves tenant context and
 * scopes by it, matching the web controller's equivalent gating.
 */
class ActiveResponseApiController extends Controller
{
    public function __construct(
        private ActiveResponseExecutionService $svc,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    public function listExecutions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $status = $request->query('status', '');
        $query  = ResponseExecution::orderByDesc('created_at')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId));
        if ($status && in_array($status, ResponseExecution::STATUSES, true)) {
            $query->where('status', $status);
        }
        $executions = $query->limit(50)->get();

        return response()->json([
            'executions'   => $executions->map(fn ($e) => $this->summarize($e))->all(),
            'advisory_note'=> 'Controlled manual response only. No autonomous containment.',
        ]);
    }

    public function getExecution(Request $request, string $executionId): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $exec = $this->svc->getExecution($executionId, $tenantId);
        if (!$exec) {
            return response()->json(['error' => 'execution_not_found'], 404);
        }

        return response()->json([
            'execution'    => $this->summarize($exec),
            'timelines'    => $exec->events->map(fn ($e) => [
                'event_type' => $e->event_type,
                'from_state' => $e->from_state,
                'to_state'   => $e->to_state,
                'actor'      => $e->actor_name,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'advisory_note'=> 'Controlled manual response only. No autonomous containment.',
        ]);
    }

    public function getPendingApprovals(Request $request): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $pending = $this->svc->getPendingApprovals($tenantId);
        return response()->json([
            'pending_approvals' => $pending->map(fn ($e) => $this->summarize($e))->all(),
        ]);
    }

    public function getSimulation(Request $request, string $executionId): JsonResponse
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $exec = $this->svc->getExecution($executionId, $tenantId);
        if (!$exec) {
            return response()->json(['error' => 'execution_not_found'], 404);
        }

        $sim = $exec->latestSimulation;
        if (!$sim) {
            return response()->json(['error' => 'no_simulation_found'], 404);
        }

        return response()->json([
            'simulation_id'         => $sim->simulation_id,
            'blast_radius_entities' => $sim->blast_radius_entities,
            'impacted_services'     => $sim->impacted_services,
            'rollback_available'    => $sim->rollback_available,
            'estimated_impact_score'=> $sim->estimated_impact_score,
            'warnings'              => $sim->warnings,
            'simulation_notes'      => $sim->simulation_notes,
            'advisory_note'         => 'Simulation only. No infrastructure was changed.',
        ]);
    }

    public function getAllowedActions(): JsonResponse
    {
        return response()->json([
            'allowed_actions'       => ResponseExecution::ALLOWED_ACTIONS,
            'dual_approval_required'=> ResponseExecution::DUAL_APPROVAL_REQUIRED,
            'rollback_supported'    => ResponseExecution::ROLLBACK_SUPPORTED_ACTIONS,
            'simulation_required'   => ResponseExecution::SIMULATION_REQUIRED_ACTIONS,
            'advisory_note'         => 'All actions require manual approval and simulation before execution.',
        ]);
    }

    private function summarize(ResponseExecution $e): array
    {
        return [
            'execution_id'           => $e->execution_id,
            'action_type'            => $e->action_type,
            'target_entity_type'     => $e->target_entity_type,
            'target_entity_key'      => $e->target_entity_key,
            'status'                 => $e->status,
            'requires_dual_approval' => $e->requires_dual_approval,
            'blast_radius_score'     => $e->blast_radius_score,
            'execution_safety_score' => $e->execution_safety_score,
            'rollback_supported'     => $e->rollback_supported,
            'created_at'             => $e->created_at?->toIso8601String(),
        ];
    }
}
