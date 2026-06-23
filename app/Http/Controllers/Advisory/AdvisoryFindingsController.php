<?php

namespace App\Http\Controllers\Advisory;

use App\Http\Controllers\Controller;
use App\Models\AdvisoryFinding;
use App\Services\AdvisoryFindingService;
use Illuminate\Http\Request;

class AdvisoryFindingsController extends Controller
{
    public function __construct(private readonly AdvisoryFindingService $service) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'domain', 'severity', 'rule_id', 'promotion_candidate']);
        $findings = $this->service->getFindings($filters);
        $summary  = $this->service->getSummary();
        $domains  = AdvisoryFinding::distinct()->pluck('domain')->sort()->values();

        return view('advisory.findings.index', compact('findings', 'summary', 'filters', 'domains'));
    }

    public function show(string $findingId)
    {
        $finding = AdvisoryFinding::where('finding_id', $findingId)->firstOrFail();
        $events  = $finding->events()->orderByDesc('created_at')->get();

        return view('advisory.findings.show', compact('finding', 'events'));
    }

    public function review(Request $request, string $findingId)
    {
        $this->authorize('soc:advisory.review');

        $request->validate([
            'action' => 'required|in:review,dismiss,nominate',
            'note'   => 'nullable|string|max:1000',
        ]);

        $finding  = AdvisoryFinding::where('finding_id', $findingId)->firstOrFail();
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
