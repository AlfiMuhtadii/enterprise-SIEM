<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\RuleEvidenceGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RuleEvidenceGovernanceController extends Controller
{
    public function __construct(private readonly RuleEvidenceGovernanceService $service) {}

    public function index(Request $request): View|JsonResponse
    {
        $domain = (string) $request->query('domain', '');
        $tier   = (string) $request->query('tier', '');

        $summary = $this->service->getBacklogSummary();
        $backlog = $this->service->getBacklog($domain, $tier);

        $viewData = [
            'summary'       => $summary,
            'backlog'       => $backlog,
            'domain_filter' => $domain,
            'tier_filter'   => $tier,
            'advisory_only' => true,
            'plan_approved' => false,
        ];

        if ($request->expectsJson()) {
            return response()->json(array_merge($viewData, ['backlog' => $backlog->toArray()]));
        }

        return view('detection.rule_evidence_governance', $viewData);
    }
}
