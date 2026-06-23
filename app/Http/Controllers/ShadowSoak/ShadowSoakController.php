<?php

namespace App\Http\Controllers\ShadowSoak;

use App\Http\Controllers\Controller;
use App\Models\ShadowSoakRun;
use App\Services\DomainSoakHarnessService;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\Request;

class ShadowSoakController extends Controller
{
    public function __construct(
        private readonly DomainSoakHarnessService $service,
        private readonly TenantBoundaryService    $tenantBoundary,
        private readonly TenantContextAuthority   $tenantAuthority,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['domain', 'status']);
        $runs    = $this->service->getRuns($filters);
        $summary = $this->service->getSummary($filters['domain'] ?? null);

        return view('shadow-soak.index', compact('runs', 'summary', 'filters'));
    }

    public function show(Request $request, string $runId)
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $detail   = $this->service->getRunDetail($runId);

        $this->tenantBoundary->assertAccess($detail['run']->tenant_id ?? null, $tenantId);

        return view('shadow-soak.show', $detail);
    }

    public function store(Request $request)
    {
        $this->authorize('soc:shadow.soak.run');

        $request->validate([
            'domain'  => 'required|in:' . implode(',', DomainSoakHarnessService::SUPPORTED_DOMAINS),
            'hours'   => 'required|integer|min:1|max:168',
            'dry_run' => 'boolean',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $run = $this->service->startSoakRun(
            domain:      $request->input('domain'),
            windowHours: (int) $request->input('hours'),
            dryRun:      (bool) $request->input('dry_run', false),
            actor:       (string) auth()->id(),
            tenantId:    $tenantId,
        );

        if ($run->dry_run) {
            return redirect()
                ->route('shadow-soak.index')
                ->with('success', "Dry-run soak for domain '{$run->domain}' initiated (run_id={$run->run_id}). No evidence written.");
        }

        try {
            $snapshot   = $this->service->computeEvidence($run);
            $gates      = $this->service->checkGates($run, $snapshot);
            $assessment = $this->service->buildAssessment($run, $gates);
            $this->service->recordFindingSummaries($run);
            $this->service->recordConfidenceBands($run, $snapshot);
            $this->service->recordSuppressionStats($run, $snapshot);
            $this->service->recordCoverageStats($run, $snapshot);
            $this->service->completeSoakRun($run, $assessment);

            $result = $assessment->evidence_pass ? 'PASS' : 'FAIL';
            return redirect()
                ->route('shadow-soak.show', $run->run_id)
                ->with('success', "Soak complete — evidence {$result}. Promotion still requires domain-specific 6h soak.");
        } catch (\Throwable $e) {
            $this->service->failSoakRun($run, $e->getMessage());
            return redirect()
                ->route('shadow-soak.index')
                ->with('error', "Soak run failed: {$e->getMessage()}");
        }
    }
}
