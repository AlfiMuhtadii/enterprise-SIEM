<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\DetectionEngineeringService;
use App\Services\RuleRegistryService;
use Illuminate\View\View;

/**
 * Detection Engineering Lifecycle UI controller.
 * All views are governed, replay-validated, and do not execute autonomous response.
 */
class DetectionLifecycleController extends Controller
{
    public function __construct(
        private DetectionEngineeringService $engineering,
        private RuleRegistryService $registry,
    ) {}

    public function versionHistory(string $ruleId): View
    {
        $rule     = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);
        $versions = $this->engineering->getVersionHistory($ruleId);
        return view('detection.version-history', compact('rule', 'versions'));
    }

    public function replayPacks(): View
    {
        $rules = $this->registry->allRules();
        $packs = \App\Models\DetectionReplayPack::where('is_active', true)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        return view('detection.replay-packs', compact('packs', 'rules'));
    }

    public function replayResults(): View
    {
        $results = \App\Models\DetectionReplayResult::orderByDesc('created_at')
            ->limit(200)
            ->get();
        $passRate = \App\Models\DetectionReplayResult::count() > 0
            ? round(\App\Models\DetectionReplayResult::where('passed', true)->count() / \App\Models\DetectionReplayResult::count(), 4)
            : 0.0;
        return view('detection.replay-results', compact('results', 'passRate'));
    }

    public function falsePositives(): View
    {
        $reports   = \App\Models\DetectionFalsePositiveReport::orderByDesc('created_at')->limit(200)->get();
        $byReason  = \Illuminate\Support\Facades\DB::table('detection_false_positive_reports')
            ->select('reason_type', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('reason_type')
            ->get();
        return view('detection.false-positives', compact('reports', 'byReason'));
    }

    public function suppressions(): View
    {
        $suppressions = \App\Models\DetectionSuppression::orderByDesc('created_at')->limit(200)->get();
        $expiring     = $this->engineering->getExpiringSuppressions(7);
        return view('detection.suppressions', compact('suppressions', 'expiring'));
    }

    public function attackMap(): View
    {
        $mappings = \App\Models\DetectionAttackMapping::where('is_active', true)
            ->orderBy('tactic')->orderBy('technique_id')
            ->get();
        $coverage = $this->engineering->getAttackCoverageSummary();
        $mitre    = $this->registry->mitreCoverageMap($this->registry->allRules());
        return view('detection.attack-map', compact('mappings', 'coverage', 'mitre'));
    }

    /**
     * CAP-MITRE-COVERAGE-NAV: downloads a MITRE ATT&CK Navigator layer.json
     * built from the current rule registry's coverage map. Read-only —
     * this endpoint performs no writes and triggers no autonomous action.
     */
    public function downloadNavigatorLayer(): \Illuminate\Http\Response
    {
        $mitre = $this->registry->mitreCoverageMap($this->registry->allRules());
        $layer = $this->registry->navigatorLayer($mitre);

        return response(json_encode($layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="detector-xdr-attack-navigator-layer.json"',
        ]);
    }

    public function promotions(): View
    {
        $pending  = $this->engineering->getPendingPromotionRequests();
        $all      = \App\Models\DetectionPromotionRequest::orderByDesc('created_at')->limit(200)->get();
        return view('detection.promotions', compact('pending', 'all'));
    }

    public function qualityDashboard(): View
    {
        $metrics   = \App\Models\DetectionQualityMetric::orderBy('quality_score')->limit(100)->get();
        $stats     = $this->engineering->getDashboardStats();
        return view('detection.quality-dashboard', compact('metrics', 'stats'));
    }

    public function lifecycleOverview(): View
    {
        $stats = $this->engineering->getDashboardStats();
        return view('detection.lifecycle-overview', compact('stats'));
    }
}
