<?php

namespace App\Http\Controllers\Soar;

use App\Http\Controllers\Controller;
use App\Models\SoarApprovalRequest;
use App\Models\SoarExecutionAudit;
use App\Models\SoarExecutionPlan;
use App\Models\SoarExecutionResult;
use App\Models\SoarPlaybook;
use App\Models\SoarRollbackPlan;
use App\Models\SoarSimulationResult;
use App\Services\SoarOrchestrationService;
use Illuminate\View\View;

/**
 * SOAR Governance & Response Orchestration UI controller.
 * Advisory-first, simulation-first, approval-gated.
 * No autonomous enforcement. No live destructive response.
 */
class SoarOrchestrationController extends Controller
{
    public function __construct(
        private SoarOrchestrationService $soar,
    ) {}

    public function playbookRegistry(): View
    {
        $stats     = $this->soar->getDashboardStats();
        $playbooks = SoarPlaybook::orderByDesc('updated_at')->limit(100)->get();
        return view('soar.playbook-registry', compact('playbooks', 'stats'));
    }

    public function playbookVersionHistory(string $playbookId): View
    {
        $playbook = SoarPlaybook::where('playbook_id', $playbookId)->firstOrFail();
        $versions = $this->soar->getVersionHistory($playbookId);
        return view('soar.playbook-version-history', compact('playbook', 'versions'));
    }

    public function simulationRunner(): View
    {
        $plans = SoarExecutionPlan::whereIn('status', ['draft', 'simulation_pending', 'simulated'])
            ->orderByDesc('updated_at')->limit(50)->get();
        $allowedActions = SoarPlaybook::ALLOWED_PHASE1_ACTIONS;
        return view('soar.simulation-runner', compact('plans', 'allowedActions'));
    }

    public function executionPlanViewer(string $planId): View
    {
        $plan      = SoarExecutionPlan::where('plan_id', $planId)->firstOrFail();
        $steps     = $plan->steps;
        $simulation= SoarSimulationResult::where('plan_id', $planId)->orderByDesc('created_at')->first();
        $audit     = $this->soar->getAuditTrail($planId);
        return view('soar.execution-plan-viewer', compact('plan', 'steps', 'simulation', 'audit'));
    }

    public function approvalConsole(): View
    {
        $pending  = SoarApprovalRequest::where('decision', 'pending')
            ->orderBy('created_at')->limit(50)->get();
        $recent   = SoarApprovalRequest::where('decision', '!=', 'pending')
            ->orderByDesc('decided_at')->limit(50)->get();
        return view('soar.approval-console', compact('pending', 'recent'));
    }

    public function rollbackViewer(): View
    {
        $rollbacks = SoarRollbackPlan::orderByDesc('updated_at')->limit(100)->get();
        return view('soar.rollback-viewer', compact('rollbacks'));
    }

    public function auditTimeline(): View
    {
        $events = SoarExecutionAudit::orderByDesc('created_at')->limit(200)->get();
        $byType = $events->groupBy('event_type');
        return view('soar.audit-timeline', compact('events', 'byType'));
    }

    public function simulationArtifacts(): View
    {
        $artifacts = SoarSimulationResult::orderByDesc('created_at')->limit(100)->get();
        $avgBlast  = $artifacts->avg('blast_radius_score') ?? 0.0;
        return view('soar.simulation-artifacts', compact('artifacts', 'avgBlast'));
    }

    public function governanceDashboard(): View
    {
        $stats   = $this->soar->getDashboardStats();
        $results = SoarExecutionResult::orderByDesc('created_at')->limit(50)->get();
        return view('soar.governance-dashboard', compact('stats', 'results'));
    }
}
