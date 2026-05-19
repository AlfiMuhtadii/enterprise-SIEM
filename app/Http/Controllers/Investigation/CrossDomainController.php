<?php

namespace App\Http\Controllers\Investigation;

use App\Http\Controllers\Controller;
use App\Models\AttackStageTimeline;
use App\Models\CrossDomainCorrelation;
use App\Services\CrossDomainCorrelationService;
use App\Services\ThreatHuntingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cross-Domain Correlation web UI — advisory-only, non-destructive.
 */
class CrossDomainController extends Controller
{
    public function __construct(
        private CrossDomainCorrelationService $correlationService,
        private ThreatHuntingService          $huntingService,
    ) {}

    public function dashboard(): View
    {
        $recentCorrelations = $this->correlationService->getRecentCorrelations(20);
        $stageDistribution  = $this->correlationService->getAttackStageDistribution();
        $typeBreakdown      = CrossDomainCorrelation::select('correlation_type',
                                \Illuminate\Support\Facades\DB::raw('count(*) as count'))
                            ->groupBy('correlation_type')->get()
                            ->pluck('count', 'correlation_type')->all();

        return view('cross-domain.dashboard', compact(
            'recentCorrelations', 'stageDistribution', 'typeBreakdown'
        ));
    }

    public function show(string $correlationId): View
    {
        $correlation = $this->correlationService->getCorrelation($correlationId);
        abort_if(!$correlation, 404);
        return view('cross-domain.show', compact('correlation'));
    }

    public function attackTimeline(Request $request): View
    {
        $stage  = $request->input('stage', '');
        $stages = AttackStageTimeline::STAGES;
        $result = $stage ? $this->huntingService->pivotAttackStage($stage) : [];

        $recentTimelines = \Illuminate\Support\Facades\DB::table('attack_stage_timelines')
            ->orderByDesc('created_at')->limit(30)->get();

        return view('cross-domain.attack-timeline', compact('stage', 'stages', 'result', 'recentTimelines'));
    }

    public function identityEndpointPivot(Request $request): View
    {
        $actorKey = $request->input('actor_key', '');
        $result   = $actorKey ? $this->huntingService->pivotIdentityToHost($actorKey) : [];

        $recentIdentityCorrelations = $this->correlationService
            ->getCorrelationsByType(CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT, 20);

        return view('cross-domain.identity-endpoint-pivot', compact(
            'actorKey', 'result', 'recentIdentityCorrelations'
        ));
    }

    public function investigationGraph(Request $request): View
    {
        $correlationId = $request->input('correlation_id', '');
        $correlation   = $correlationId
            ? $this->correlationService->getCorrelation($correlationId)
            : null;

        $allTypes = CrossDomainCorrelation::TYPES;
        return view('cross-domain.investigation-graph', compact('correlationId', 'correlation', 'allTypes'));
    }

    public function sharedDestination(Request $request): View
    {
        $ip     = $request->input('ip', '');
        $result = $ip ? $this->huntingService->pivotMultiHostDestination($ip) : [];

        $multiHostCorrelations = $this->correlationService
            ->getCorrelationsByType(CrossDomainCorrelation::TYPE_MULTI_HOST, 20);

        return view('cross-domain.shared-destination', compact('ip', 'result', 'multiHostCorrelations'));
    }

    public function traceExplorer(Request $request): View
    {
        $traceId = $request->input('trace_id', '');
        $result  = $traceId ? $this->correlationService->stitchCrossDomainTrace($traceId) : [];

        return view('cross-domain.trace-explorer', compact('traceId', 'result'));
    }

    public function runCorrelation(Request $request): RedirectResponse
    {
        $type = $request->input('type', 'identity_endpoint');

        $created = match ($type) {
            CrossDomainCorrelation::TYPE_IDENTITY_ENDPOINT => $this->correlationService->correlateIdentityToEndpoint(),
            CrossDomainCorrelation::TYPE_SAAS_ENDPOINT     => $this->correlationService->correlateSaaSToEndpoint(),
            CrossDomainCorrelation::TYPE_CLOUD_ENDPOINT    => $this->correlationService->correlateCloudToEndpoint(),
            CrossDomainCorrelation::TYPE_MULTI_HOST        => $this->correlationService->detectMultiHostSharedDestination(),
            default                                         => [],
        };

        $count = count($created);
        return redirect()->route('cross-domain.dashboard')
            ->with('success', "Cross-domain correlation run complete — {$count} advisory correlations created.");
    }
}
