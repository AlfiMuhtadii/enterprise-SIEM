<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointAgentEnrollmentEvent;
use App\Models\EndpointAgentPolicyAssignment;
use App\Models\EndpointFleetPolicy;
use App\Models\EndpointTamperEvent;
use App\Services\EndpointFleetService;
use Illuminate\Http\Request;

/**
 * Endpoint Fleet Hardening Phase 1 — Production management UI controller.
 *
 * All views display the advisory disclaimer:
 * "Endpoint operations are advisory-only. No autonomous containment or enforcement is executed."
 *
 * No autonomous isolation, no remote shell, no process kill.
 */
class EndpointFleetController extends Controller
{
    public function __construct(private readonly EndpointFleetService $fleetService) {}

    // -----------------------------------------------------------------------
    // 1. Endpoint Fleet Dashboard
    // -----------------------------------------------------------------------

    public function dashboard()
    {
        $stats         = $this->fleetService->getDashboardStats();
        $staleAgents   = $this->fleetService->getStaleAgents()->take(10);
        $recentTamper  = EndpointTamperEvent::orderByDesc('detected_at')->limit(10)->get();
        $fleetPolicies = EndpointFleetPolicy::where('is_active', true)->orderBy('name')->get();

        return view('endpoint-fleet.dashboard', compact('stats', 'staleAgents', 'recentTamper', 'fleetPolicies'));
    }

    // -----------------------------------------------------------------------
    // 2. Agent Health Explorer
    // -----------------------------------------------------------------------

    public function agentHealth(Request $request)
    {
        $healthFilter = $request->input('health_state');
        $search       = $request->input('search');

        $query = EndpointAgent::orderBy('health_state')->orderByDesc('last_seen_at');

        if ($healthFilter) {
            $query->where('health_state', $healthFilter);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('hostname', 'like', "%{$search}%")
                  ->orWhere('agent_id', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $agents      = $query->limit(200)->get();
        $healthStates = EndpointAgent::HEALTH_STATES;

        return view('endpoint-fleet.agent-health', compact('agents', 'healthFilter', 'search', 'healthStates'));
    }

    // -----------------------------------------------------------------------
    // 3. Policy Assignment View
    // -----------------------------------------------------------------------

    public function policyAssignment(Request $request)
    {
        $policyId = $request->input('policy_id');

        $policies      = EndpointFleetPolicy::orderByDesc('created_at')->limit(50)->get();
        $selectedPolicy = $policyId
            ? EndpointFleetPolicy::where('policy_id', $policyId)->first()
            : null;

        $assignments = collect();
        if ($selectedPolicy) {
            $assignments = EndpointAgentPolicyAssignment::where('policy_id', $selectedPolicy->policy_id)
                ->with('agent')
                ->orderByDesc('assigned_at')
                ->limit(200)
                ->get();
        }

        return view('endpoint-fleet.policy-assignment', compact('policies', 'selectedPolicy', 'assignments', 'policyId'));
    }

    // -----------------------------------------------------------------------
    // 4. Enrollment Audit View
    // -----------------------------------------------------------------------

    public function enrollmentAudit(Request $request)
    {
        $agentId   = $request->input('agent_id');
        $eventType = $request->input('event_type');
        $days      = min((int) $request->input('days', 7), 30);

        $query = EndpointAgentEnrollmentEvent::with('agent')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at');

        if ($agentId) {
            $query->whereHas('agent', fn ($q) => $q->where('agent_id', $agentId));
        }
        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $events     = $query->limit(200)->get();
        $eventTypes = EndpointAgentEnrollmentEvent::EVENT_TYPES;

        return view('endpoint-fleet.enrollment-audit', compact('events', 'eventTypes', 'agentId', 'eventType', 'days'));
    }

    // -----------------------------------------------------------------------
    // 5. Telemetry Lag Monitor
    // -----------------------------------------------------------------------

    public function telemetryLag()
    {
        $lagSummary  = $this->fleetService->getTelemetryLagSummary(100);
        $staleAgents = $this->fleetService->getStaleAgents();
        $totalAgents = EndpointAgent::count();

        return view('endpoint-fleet.telemetry-lag', compact('lagSummary', 'staleAgents', 'totalAgents'));
    }

    // -----------------------------------------------------------------------
    // 6. Tamper Visibility Explorer
    // -----------------------------------------------------------------------

    public function tamperVisibility(Request $request)
    {
        $tamperType = $request->input('tamper_type');
        $severity   = $request->input('severity');
        $days       = min((int) $request->input('days', 7), 30);

        $query = EndpointTamperEvent::with('agent')
            ->where('is_advisory', true)
            ->where('detected_at', '>=', now()->subDays($days))
            ->orderByDesc('detected_at');

        if ($tamperType) {
            $query->where('tamper_type', $tamperType);
        }
        if ($severity) {
            $query->where('severity', $severity);
        }

        $events       = $query->limit(200)->get();
        $summary      = $this->fleetService->getTamperVisibilitySummary($days);
        $tamperTypes  = EndpointTamperEvent::TAMPER_TYPES;

        return view('endpoint-fleet.tamper-visibility', compact('events', 'summary', 'tamperTypes', 'tamperType', 'severity', 'days'));
    }

    // -----------------------------------------------------------------------
    // 7. Spool Health Dashboard
    // -----------------------------------------------------------------------

    public function spoolHealth()
    {
        $spoolSummary  = $this->fleetService->getSpoolHealthSummary(100);
        $warnings      = $this->fleetService->countSpoolWarnings();
        $totalAgents   = EndpointAgent::count();

        return view('endpoint-fleet.spool-health', compact('spoolSummary', 'warnings', 'totalAgents'));
    }

    // -----------------------------------------------------------------------
    // 8. Endpoint Policy Drift View
    // -----------------------------------------------------------------------

    public function policyDrift()
    {
        $driftAgents = $this->fleetService->getAgentsWithPolicyDrift();
        $totalAgents = EndpointAgent::count();

        return view('endpoint-fleet.policy-drift', compact('driftAgents', 'totalAgents'));
    }
}
