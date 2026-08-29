<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\ThreatHunt;
use App\Services\TenantContextAuthority;
use App\Services\ThreatHuntingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Threat Hunting web UI — advisory-only, non-destructive.
 */
class ThreatHuntController extends Controller
{
    public function __construct(
        private ThreatHuntingService $huntingService,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    public function dashboard(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $recentHunts = $this->huntingService->getHuntHistory(20, $tenantId);
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;

        return view('threat-hunt.dashboard', compact('recentHunts', 'domains'));
    }

    public function queryBuilder(Request $request): View
    {
        $this->tenantId($request);
        $domains = ThreatHuntingService::SUPPORTED_DOMAINS;

        return view('threat-hunt.query-builder', compact('domains'));
    }

    public function executeQuery(Request $request): RedirectResponse
    {
        $request->validate([
            'query_domain' => 'required|string|max:60',
            'title' => 'required|string|max:255',
        ]);

        try {
            $filters = [];
            foreach ($request->input('filters', []) as $filter) {
                if (! empty($filter['field']) && ! empty($filter['operator']) && isset($filter['value'])) {
                    $filters[] = $filter;
                }
            }

            $hunt = $this->huntingService->executeHunt([
                'query_domain' => $request->input('query_domain'),
                'query_filters' => $filters,
                'time_range_start' => $request->input('time_range_start'),
                'time_range_end' => $request->input('time_range_end'),
                'max_results' => $request->input('max_results', 100),
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'tenant_id' => $this->tenantId($request),
            ], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['query' => $e->getMessage()]);
        }

        return redirect()->route('threat-hunt.show', $hunt->hunt_id)
            ->with('success', "Hunt {$hunt->hunt_id} completed — {$hunt->result_count} results.");
    }

    public function show(Request $request, string $huntId): View
    {
        $tenantId = $this->tenantId($request);
        $hunt = ThreatHunt::where('hunt_id', $huntId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->with(['queries', 'results', 'creator'])
            ->firstOrFail();

        return view('threat-hunt.show', compact('hunt'));
    }

    public function replay(string $huntId, Request $request): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $original = ThreatHunt::where('hunt_id', $huntId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->firstOrFail();
        $replay = $this->huntingService->replayHunt($original, $request->user());

        return redirect()->route('threat-hunt.show', $replay->hunt_id)
            ->with('success', "Replay {$replay->hunt_id} completed — {$replay->result_count} results.");
    }

    public function pivotExplorer(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $pivotType = $request->input('type', 'host');
        $pivotId = $request->input('id', '');
        $result = [];

        if ($pivotId) {
            $result = match ($pivotType) {
                'host' => $this->huntingService->pivotHost($pivotId, $tenantId),
                'process' => $this->huntingService->pivotProcess($pivotId, tenantId: $tenantId),
                'persistence' => $this->huntingService->pivotPersistence($pivotId, tenantId: $tenantId),
                'trace' => $this->huntingService->pivotTrace($pivotId, $tenantId),
                default => ['error' => 'Unsupported pivot type'],
            };
        }

        $agents = $this->agents($tenantId)->orderBy('hostname')->limit(50)->get(['id', 'agent_id', 'hostname']);

        return view('threat-hunt.pivot-explorer', compact('pivotType', 'pivotId', 'result', 'agents'));
    }

    public function historyReplay(Request $request): View
    {
        $hunts = $this->huntingService->getHuntHistory(50, $this->tenantId($request));

        return view('threat-hunt.history-replay', compact('hunts'));
    }

    public function beaconInvestigation(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $agentId = $request->input('agent_id');
        $patterns = [];

        if ($agentId) {
            $agent = $this->agents($tenantId)->where('agent_id', $agentId)->first();
            $patterns = $agent
                ? app(\App\Services\BehavioralAnalyticsService::class)->getBeaconPatterns($agent)
                : [];
        }

        $agents = $this->agents($tenantId)->orderBy('hostname')->limit(50)->get(['agent_id', 'hostname']);

        return view('threat-hunt.beacon-investigation', compact('agentId', 'patterns', 'agents'));
    }

    public function persistenceInvestigation(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $agentId = $request->input('agent_id');
        $items = [];

        if ($agentId) {
            $agent = $this->agents($tenantId)->where('agent_id', $agentId)->first();
            $items = $agent
                ? app(\App\Services\EndpointBehavioralService::class)->getPersistenceInventory($agent)
                : [];
        }

        $agents = $this->agents($tenantId)->orderBy('hostname')->limit(50)->get(['agent_id', 'hostname']);

        return view('threat-hunt.persistence-investigation', compact('agentId', 'items', 'agents'));
    }

    public function chainExplorer(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $agentId = $request->input('agent_id');
        $chains = [];

        if ($agentId) {
            $agent = $this->agents($tenantId)->where('agent_id', $agentId)->first();
            $chains = $agent
                ? app(\App\Services\BehavioralAnalyticsService::class)->getExecutionChains($agent)
                : [];
        }

        $agents = $this->agents($tenantId)->orderBy('hostname')->limit(50)->get(['agent_id', 'hostname']);

        return view('threat-hunt.chain-explorer', compact('agentId', 'chains', 'agents'));
    }

    private function tenantId(Request $request): ?string
    {
        return $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: true);
    }

    private function agents(?string $tenantId): Builder
    {
        return EndpointAgent::query()->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId));
    }
}
