<?php

namespace App\Http\Controllers\Advisory;

use App\Exceptions\TenantBoundaryViolationException;
use App\Http\Controllers\Controller;
use App\Models\AdvisoryFinding;
use App\Services\AdvisoryFindingService;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\Request;

class AdvisoryFindingsController extends Controller
{
    public function __construct(
        private readonly AdvisoryFindingService $service,
        private readonly TenantBoundaryService  $tenantBoundary,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $filters  = $request->only(['status', 'domain', 'severity', 'rule_id', 'promotion_candidate']);

        if ($tenantId !== null) {
            $filters['tenant_id'] = $tenantId;
        }

        $findings = $this->service->getFindings($filters);
        $summary  = $this->service->getSummary($tenantId);
        $domains  = AdvisoryFinding::distinct()->pluck('domain')->sort()->values();

        return view('advisory.findings.index', compact('findings', 'summary', 'filters', 'domains'));
    }

    public function show(Request $request, string $findingId)
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: true);
        $finding  = AdvisoryFinding::where('finding_id', $findingId)->firstOrFail();

        $this->tenantBoundary->assertAccess($finding->tenant_id, $tenantId);

        $events = $finding->events()->orderByDesc('created_at')->get();

        return view('advisory.findings.show', compact('finding', 'events'));
    }

    public function review(Request $request, string $findingId)
    {
        $this->authorize('soc:advisory.review');

        $request->validate([
            'action' => 'required|in:review,dismiss,nominate',
            'note'   => 'nullable|string|max:1000',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: true);
        $finding  = AdvisoryFinding::where('finding_id', $findingId)->firstOrFail();

        $this->tenantBoundary->assertAccess($finding->tenant_id, $tenantId);

        $analystId = auth()->id();
        $note      = $request->input('note', '');

        match ($request->input('action')) {
            'review'   => $this->service->review($finding, $analystId, $note),
            'dismiss'  => $this->service->dismiss($finding, $analystId, $note),
            'nominate' => $this->service->nominate($finding, $analystId, $note),
        };

        return redirect()
            ->route('advisory.findings.show', $findingId)
            ->with('success', 'Finding updated.');
    }
}
