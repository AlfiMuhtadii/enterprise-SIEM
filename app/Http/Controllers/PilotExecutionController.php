<?php

namespace App\Http\Controllers;

use App\Models\LivePilotRun;
use App\Models\PilotEndpointEnrollment;
use App\Models\PilotHealthCheckpoint;
use App\Models\PilotOperationalReview;
use App\Models\PilotDriftReview;
use App\Models\PilotRollbackAudit;
use App\Models\LiveTelemetryValidation;
use App\Models\ProductionObservationCheckpoint;
use App\Models\PilotExecutionAudit;
use App\Services\PilotExecutionService;
use Illuminate\View\View;

class PilotExecutionController extends Controller
{
    public function __construct(private PilotExecutionService $service) {}

    public function dashboard(): View
    {
        return view('pilot-execution.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function enrollment(): View
    {
        return view('pilot-execution.enrollment', [
            'enrollments' => PilotEndpointEnrollment::latest()->paginate(50),
        ]);
    }

    public function telemetry(): View
    {
        return view('pilot-execution.telemetry', [
            'validations' => LiveTelemetryValidation::latest()->paginate(50),
        ]);
    }

    public function reviews(): View
    {
        return view('pilot-execution.reviews', [
            'reviews' => PilotOperationalReview::latest()->paginate(50),
        ]);
    }

    public function rollback(): View
    {
        return view('pilot-execution.rollback', [
            'audits' => PilotRollbackAudit::latest()->paginate(50),
        ]);
    }

    public function observation(): View
    {
        return view('pilot-execution.observation', [
            'checkpoints' => ProductionObservationCheckpoint::latest()->paginate(50),
        ]);
    }

    public function drift(): View
    {
        return view('pilot-execution.drift', [
            'driftReviews' => PilotDriftReview::latest()->paginate(50),
        ]);
    }

    public function audit(): View
    {
        return view('pilot-execution.audit', [
            'auditEvents' => PilotExecutionAudit::latest()->paginate(50),
        ]);
    }

    public function health(): View
    {
        return view('pilot-execution.health', [
            'checkpoints' => PilotHealthCheckpoint::latest()->paginate(50),
        ]);
    }
}
