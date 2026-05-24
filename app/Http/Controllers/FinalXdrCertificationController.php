<?php

namespace App\Http\Controllers;

use App\Models\XdrReadinessCertification;
use App\Models\ProductionAcceptanceGate;
use App\Models\OperationalLimitationReport;
use App\Models\ExecutiveReadinessReport;
use App\Models\TechnicalReadinessReport;
use App\Models\ProductionRiskRegister;
use App\Models\GoLiveValidationRun;
use App\Models\DeploymentAcceptanceAudit;
use App\Models\FinalXdrCertificationAudit;
use App\Services\FinalXdrCertificationService;
use Illuminate\Http\Request;

class FinalXdrCertificationController extends Controller
{
    public function __construct(private FinalXdrCertificationService $service) {}

    public function dashboard()
    {
        $stats = $this->service->dashboardStats();

        $recentCertifications = XdrReadinessCertification::latest()->limit(5)->get();
        $recentGoLive         = GoLiveValidationRun::latest()->limit(5)->get();
        $openRisks            = ProductionRiskRegister::where('risk_state', 'open')->latest()->limit(5)->get();

        return view('xdr-certification.dashboard', compact(
            'stats', 'recentCertifications', 'recentGoLive', 'openRisks'
        ));
    }

    public function certificationCenter()
    {
        $certifications = XdrReadinessCertification::latest()->paginate(20);

        $certTypes   = XdrReadinessCertification::CERTIFICATION_TYPES;
        $certStates  = XdrReadinessCertification::CERTIFICATION_STATES;

        return view('xdr-certification.certification-center', compact(
            'certifications', 'certTypes', 'certStates'
        ));
    }

    public function acceptanceGates()
    {
        $gates = ProductionAcceptanceGate::latest()->paginate(20);

        $gateNames  = ProductionAcceptanceGate::GATE_NAMES;
        $gateStates = ProductionAcceptanceGate::GATE_STATES;

        $gatePassRate = $gates->total() > 0
            ? ProductionAcceptanceGate::where('gate_passed', true)->count() / ProductionAcceptanceGate::count()
            : 0.0;

        return view('xdr-certification.acceptance-gates', compact(
            'gates', 'gateNames', 'gateStates', 'gatePassRate'
        ));
    }

    public function limitationRegister()
    {
        $limitations = OperationalLimitationReport::latest()->paginate(20);

        $categories = OperationalLimitationReport::LIMITATION_CATEGORIES;
        $severities = OperationalLimitationReport::LIMITATION_SEVERITIES;

        $cutoverBlocking = OperationalLimitationReport::where('cutover_blocked', true)->count();

        return view('xdr-certification.limitation-register', compact(
            'limitations', 'categories', 'severities', 'cutoverBlocking'
        ));
    }

    public function riskRegister()
    {
        $risks = ProductionRiskRegister::latest()->paginate(20);

        $categories = ProductionRiskRegister::RISK_CATEGORIES;
        $severities = ProductionRiskRegister::RISK_SEVERITIES;
        $states     = ProductionRiskRegister::RISK_STATES;

        $openCritical = ProductionRiskRegister::where('risk_severity', 'critical')
            ->where('risk_state', 'open')->count();

        return view('xdr-certification.risk-register', compact(
            'risks', 'categories', 'severities', 'states', 'openCritical'
        ));
    }

    public function executiveReports()
    {
        $reports = ExecutiveReadinessReport::latest()->paginate(20);

        $scopes          = ExecutiveReadinessReport::REPORT_SCOPES;
        $recommendations = ExecutiveReadinessReport::GO_LIVE_RECOMMENDATIONS;

        return view('xdr-certification.executive-reports', compact(
            'reports', 'scopes', 'recommendations'
        ));
    }

    public function technicalReports()
    {
        $reports = TechnicalReadinessReport::latest()->paginate(20);

        $domains = TechnicalReadinessReport::REPORT_DOMAINS;

        return view('xdr-certification.technical-reports', compact(
            'reports', 'domains'
        ));
    }

    public function goLiveValidations()
    {
        $runs = GoLiveValidationRun::latest()->paginate(20);

        $scopes = GoLiveValidationRun::VALIDATION_SCOPES;
        $states = GoLiveValidationRun::VALIDATION_STATES;

        return view('xdr-certification.go-live-validations', compact(
            'runs', 'scopes', 'states'
        ));
    }

    public function auditTimeline()
    {
        $certAudit       = FinalXdrCertificationAudit::latest()->limit(50)->get();
        $acceptanceAudit = DeploymentAcceptanceAudit::latest()->limit(50)->get();

        $certEventTypes = FinalXdrCertificationAudit::AUDIT_EVENT_TYPES;
        $actorTypes     = FinalXdrCertificationAudit::ACTOR_TYPES;

        return view('xdr-certification.audit-timeline', compact(
            'certAudit', 'acceptanceAudit', 'certEventTypes', 'actorTypes'
        ));
    }
}
