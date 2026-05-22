<?php

namespace App\Http\Controllers;

use App\Models\OperationalIntelligenceSnapshot;
use App\Models\AnalystInvestigationSummary;
use App\Models\DetectionConfidenceHistory;
use App\Models\FalsePositiveDriftReport;
use App\Models\AttackProgressionScore;
use App\Models\ChainedInvestigationView;
use App\Models\ReplayConfidenceValidation;
use App\Models\SuppressionEffectivenessReport;
use App\Models\AnalystAcknowledgmentPattern;
use App\Services\OperationalIntelligenceService;
use Illuminate\View\View;

class OperationalIntelligenceController extends Controller
{
    public function __construct(private OperationalIntelligenceService $service) {}

    public function dashboard(): View
    {
        return view('operational-intelligence.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function confidence(): View
    {
        return view('operational-intelligence.confidence', [
            'history' => DetectionConfidenceHistory::latest()->paginate(50),
        ]);
    }

    public function investigations(): View
    {
        return view('operational-intelligence.investigations', [
            'summaries' => AnalystInvestigationSummary::latest()->paginate(50),
        ]);
    }

    public function fpDrift(): View
    {
        return view('operational-intelligence.fp-drift', [
            'reports' => FalsePositiveDriftReport::latest()->paginate(50),
        ]);
    }

    public function progression(): View
    {
        return view('operational-intelligence.progression', [
            'scores' => AttackProgressionScore::latest()->paginate(50),
        ]);
    }

    public function crossHost(): View
    {
        return view('operational-intelligence.cross-host', [
            'views' => ChainedInvestigationView::latest()->paginate(50),
        ]);
    }

    public function suppression(): View
    {
        return view('operational-intelligence.suppression', [
            'reports' => SuppressionEffectivenessReport::latest()->paginate(50),
        ]);
    }

    public function replayConfidence(): View
    {
        return view('operational-intelligence.replay-confidence', [
            'validations' => ReplayConfidenceValidation::latest()->paginate(50),
        ]);
    }

    public function acceleration(): View
    {
        return view('operational-intelligence.acceleration', [
            'patterns' => AnalystAcknowledgmentPattern::latest()->paginate(50),
        ]);
    }
}
