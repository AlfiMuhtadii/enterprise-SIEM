<?php

namespace App\Http\Controllers;

use App\Models\AnalystWorkloadSnapshot;
use App\Models\AlertPrioritizationScore;
use App\Models\FalsePositiveTuningReport;
use App\Models\AnalystAcknowledgmentAudit;
use App\Models\EscalationQualityReview;
use App\Models\InvestigationErgonomicView;
use App\Models\AlertRecurrenceReport;
use App\Models\OperationalFatigueIndicator;
use App\Models\ShiftHandoffValidation;
use App\Services\AnalystOptimizationService;
use Illuminate\View\View;

class AnalystOptimizationController extends Controller
{
    public function __construct(private AnalystOptimizationService $service) {}

    public function dashboard(): View
    {
        return view('analyst-optimization.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function prioritization(): View
    {
        return view('analyst-optimization.prioritization', [
            'scores' => AlertPrioritizationScore::latest()->paginate(50),
        ]);
    }

    public function fpTuning(): View
    {
        return view('analyst-optimization.fp-tuning', [
            'reports' => FalsePositiveTuningReport::latest()->paginate(50),
        ]);
    }

    public function workload(): View
    {
        return view('analyst-optimization.workload', [
            'snapshots' => AnalystWorkloadSnapshot::latest()->paginate(50),
        ]);
    }

    public function escalationQuality(): View
    {
        return view('analyst-optimization.escalation-quality', [
            'reviews' => EscalationQualityReview::latest()->paginate(50),
        ]);
    }

    public function ergonomics(): View
    {
        return view('analyst-optimization.ergonomics', [
            'views' => InvestigationErgonomicView::latest()->paginate(50),
        ]);
    }

    public function fatigue(): View
    {
        return view('analyst-optimization.fatigue', [
            'indicators' => OperationalFatigueIndicator::latest()->paginate(50),
        ]);
    }

    public function handoffs(): View
    {
        return view('analyst-optimization.handoffs', [
            'handoffs' => ShiftHandoffValidation::latest()->paginate(50),
        ]);
    }

    public function efficiency(): View
    {
        return view('analyst-optimization.efficiency', [
            'acknowledgments' => AnalystAcknowledgmentAudit::latest()->paginate(50),
            'recurrences'     => AlertRecurrenceReport::latest()->paginate(10),
        ]);
    }
}
