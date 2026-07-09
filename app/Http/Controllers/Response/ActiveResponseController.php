<?php

namespace App\Http\Controllers\Response;

use App\Http\Controllers\Controller;
use App\Models\ResponseExecution;
use App\Services\ActiveResponseExecutionService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Controlled Active Response web UI — Phase 2.
 * All actions require manual operator confirmation. No autonomous execution.
 *
 * ENT-TENANCY-RESPONSE-EXECUTION: every action resolves tenant context and
 * scopes reads/writes by it. findOrFail() below rejects (404) an execution
 * belonging to another tenant rather than exposing it — a compromised
 * tenant operator must not be able to view, simulate, approve, or execute
 * response plans targeting another tenant's resources.
 */
class ActiveResponseController extends Controller
{
    public function __construct(
        private ActiveResponseExecutionService $svc,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    public function dashboard(Request $request): View
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $recentExecutions = $this->svc->getRecentExecutions(20, $tenantId);
        $pendingApprovals = $this->svc->getPendingApprovals($tenantId);
        $rollbackCandidates = $this->svc->getRollbackCandidates($tenantId);
        $statusCounts = ResponseExecution::select('status', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('status')->pluck('count', 'status')->all();

        return view('active-response.dashboard', compact('recentExecutions', 'pendingApprovals', 'rollbackCandidates', 'statusCounts'));
    }

    public function approvalQueue(Request $request): View
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $pending = $this->svc->getPendingApprovals($tenantId);
        return view('active-response.approval-queue', compact('pending'));
    }

    public function show(Request $request, string $executionId): View
    {
        $execution = $this->findOrFail($request, $executionId);
        return view('active-response.show', compact('execution'));
    }

    public function simulationPreview(Request $request, string $executionId): View
    {
        $execution = $this->findOrFail($request, $executionId);
        $simulation = $execution->latestSimulation;
        return view('active-response.simulation-preview', compact('execution', 'simulation'));
    }

    public function blastRadiusView(Request $request, string $executionId): View
    {
        $execution = $this->findOrFail($request, $executionId);
        $simulation = $execution->latestSimulation;
        return view('active-response.blast-radius', compact('execution', 'simulation'));
    }

    public function rollbackCenter(Request $request): View
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $candidates = $this->svc->getRollbackCandidates($tenantId);
        return view('active-response.rollback-center', compact('candidates'));
    }

    public function executionTimeline(Request $request, string $executionId): View
    {
        $execution = $this->findOrFail($request, $executionId);
        return view('active-response.execution-timeline', compact('execution'));
    }

    public function auditExplorer(Request $request): View
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $actionType = $request->input('action_type', '');
        $status     = $request->input('status', '');
        $query = ResponseExecution::with(['creator'])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('created_at');
        if ($actionType) {
            $query->where('action_type', $actionType);
        }
        if ($status) {
            $query->where('status', $status);
        }
        $executions = $query->limit(50)->get();
        return view('active-response.audit-explorer', compact('executions', 'actionType', 'status'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'action_type'        => 'required|string|in:' . implode(',', ResponseExecution::ALLOWED_ACTIONS),
            'target_entity_type' => 'required|string|max:30',
            'target_entity_key'  => 'required|string|max:255',
            'rationale'          => 'required|string|min:10|max:2000',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        try {
            $exec = $this->svc->createExecution($request->all(), $request->user(), $tenantId);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['action_type' => $e->getMessage()]);
        }

        return redirect()->route('active-response.show', $exec->execution_id)
            ->with('success', "Execution {$exec->execution_id} created in draft state.");
    }

    public function submit(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->submitForApproval($exec, request()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, string $executionId): RedirectResponse
    {
        $request->validate(['rationale' => 'required|string|min:5|max:500']);
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->approve($exec, $request->user(), $request->input('rationale'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)->with('success', 'Approval recorded.');
    }

    public function reject(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->reject($exec, $request->user(), $request->input('reason', ''));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reject' => $e->getMessage()]);
        }
        return redirect()->route('active-response.dashboard')->with('success', 'Execution rejected.');
    }

    public function simulate(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $sim = $this->svc->runSimulation($exec, request()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['simulate' => $e->getMessage()]);
        }
        return redirect()->route('active-response.simulation-preview', $executionId)
            ->with('success', "Simulation {$sim->simulation_id} completed. Review blast radius before execution.");
    }

    public function requestExecution(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->requestExecution($exec, request()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['execute' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)->with('success', 'Execution ready. Confirm to proceed.');
    }

    public function execute(Request $request, string $executionId): RedirectResponse
    {
        $request->validate(['confirmation_note' => 'required|string|min:10|max:500']);
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->executeAction($exec, $request->user(), $request->input('confirmation_note'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['execute' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)->with('success', 'Execution recorded. Audit trail updated.');
    }

    public function initiateRollback(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $rollback = $this->svc->initiateRollback($exec, request()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['rollback' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)
            ->with('success', "Rollback {$rollback->rollback_id} initiated.");
    }

    public function completeRollback(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->completeRollback($exec, $request->user(), $request->input('note', ''));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['rollback' => $e->getMessage()]);
        }
        return redirect()->route('active-response.show', $executionId)->with('success', 'Rollback completed.');
    }

    public function cancel(Request $request, string $executionId): RedirectResponse
    {
        $exec = $this->findOrFail($request, $executionId);
        try {
            $this->svc->cancel($exec, $request->user(), $request->input('reason', ''));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }
        return redirect()->route('active-response.dashboard')->with('success', 'Execution cancelled.');
    }

    private function findOrFail(Request $request, string $executionId): ResponseExecution
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $exec = $this->svc->getExecution($executionId, $tenantId);
        abort_if(!$exec, 404);
        return $exec;
    }
}
