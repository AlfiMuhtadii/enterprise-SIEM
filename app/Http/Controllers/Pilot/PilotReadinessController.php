<?php

namespace App\Http\Controllers\Pilot;

use App\Http\Controllers\Controller;
use App\Models\PilotOnboardingRun;
use App\Models\PilotHealthValidation;
use App\Models\PilotSuccessMetric;
use App\Models\PilotRollbackValidation;
use App\Models\TelemetryOnboardingPressure;
use App\Models\OperatorReadinessReview;
use App\Models\PilotAuditEvent;
use App\Models\OnboardingApprovalRequest;
use App\Models\PilotObservationWindow;
use App\Services\PilotReadinessService;

class PilotReadinessController extends Controller
{
    public function __construct(private PilotReadinessService $service) {}

    public function pilotDashboard()
    {
        $stats   = $this->service->dashboardStats();
        $recent  = PilotOnboardingRun::latest()->limit(20)->get();
        return view('pilot-readiness.pilot-dashboard', compact('stats', 'recent'));
    }

    public function onboardingConsole()
    {
        $runs      = PilotOnboardingRun::latest()->limit(50)->get();
        $approvals = OnboardingApprovalRequest::where('status', 'pending')->latest()->get();
        return view('pilot-readiness.onboarding-console', compact('runs', 'approvals'));
    }

    public function telemetryPressure()
    {
        $snapshots = TelemetryOnboardingPressure::latest()->limit(50)->get();
        $byLevel   = TelemetryOnboardingPressure::selectRaw('pressure_level, count(*) as cnt')
            ->groupBy('pressure_level')->pluck('cnt', 'pressure_level');
        return view('pilot-readiness.telemetry-pressure', compact('snapshots', 'byLevel'));
    }

    public function healthValidation()
    {
        $checks = PilotHealthValidation::latest()->limit(50)->get();
        $stats  = [
            'pass'    => PilotHealthValidation::where('verdict', 'pass')->count(),
            'fail'    => PilotHealthValidation::where('verdict', 'fail')->count(),
            'degraded'=> PilotHealthValidation::where('verdict', 'degraded')->count(),
        ];
        return view('pilot-readiness.health-validation', compact('checks', 'stats'));
    }

    public function rollbackTimeline()
    {
        $validations = PilotRollbackValidation::latest()->limit(50)->get();
        $stats = [
            'pass'           => PilotRollbackValidation::where('verdict', 'pass')->count(),
            'fail'           => PilotRollbackValidation::where('verdict', 'fail')->count(),
            'pending'        => PilotRollbackValidation::where('verdict', 'pending_approval')->count(),
        ];
        return view('pilot-readiness.rollback-timeline', compact('validations', 'stats'));
    }

    public function operatorReadiness()
    {
        $reviews = OperatorReadinessReview::latest()->limit(50)->get();
        $stats   = [
            'ready'    => OperatorReadinessReview::where('operator_ready', true)->count(),
            'not_ready'=> OperatorReadinessReview::where('operator_ready', false)->count(),
        ];
        return view('pilot-readiness.operator-readiness', compact('reviews', 'stats'));
    }

    public function successMetrics()
    {
        $metrics   = PilotSuccessMetric::latest()->limit(50)->get();
        $byName    = PilotSuccessMetric::selectRaw('metric_name, avg(metric_value) as avg_val, bool_or(target_met) as any_met')
            ->groupBy('metric_name')->get()->keyBy('metric_name');
        return view('pilot-readiness.success-metrics', compact('metrics', 'byName'));
    }

    public function auditTimeline()
    {
        $events  = PilotAuditEvent::latest()->limit(100)->get();
        $byType  = PilotAuditEvent::selectRaw('event_type, count(*) as cnt')
            ->groupBy('event_type')->pluck('cnt', 'event_type');
        return view('pilot-readiness.audit-timeline', compact('events', 'byType'));
    }

    public function observationWindows()
    {
        $windows  = PilotObservationWindow::latest()->limit(50)->get();
        $active   = PilotObservationWindow::where('status', 'active')->get();
        return view('pilot-readiness.observation-windows', compact('windows', 'active'));
    }
}
