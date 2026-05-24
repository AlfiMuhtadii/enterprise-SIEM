<?php

namespace App\Http\Controllers;

use App\Models\ReleaseCandidateManifest;
use App\Models\FeatureFreezeAudit;
use App\Models\DeploymentArtifactReport;
use App\Models\PilotDeploymentPreparationRun;
use App\Models\OperationalValidationBaseline;
use App\Models\ReleaseBlockerReport;
use App\Models\DeploymentReproducibilityReport;
use App\Models\StabilizationValidationRun;
use App\Models\ReleaseCandidateAudit;
use App\Services\ReleaseCandidateStabilizationService;

class ReleaseCandidateStabilizationController extends Controller
{
    public function __construct(private ReleaseCandidateStabilizationService $service) {}

    public function dashboard()
    {
        $stats = $this->service->dashboardStats();

        $recentRcs       = ReleaseCandidateManifest::latest()->limit(5)->get();
        $openBlockers    = ReleaseBlockerReport::where('blocker_state', 'open')->latest()->limit(5)->get();
        $recentPilotRuns = PilotDeploymentPreparationRun::latest()->limit(5)->get();

        return view('release-stabilization.dashboard', compact(
            'stats', 'recentRcs', 'openBlockers', 'recentPilotRuns'
        ));
    }

    public function featureFreezeGovernance()
    {
        $freezeAudits = FeatureFreezeAudit::latest()->paginate(20);
        $freezeEvents = FeatureFreezeAudit::FREEZE_EVENTS;
        $freezeScopes = FeatureFreezeAudit::FREEZE_SCOPES;

        $rcs = ReleaseCandidateManifest::latest()->limit(10)->get();

        return view('release-stabilization.feature-freeze', compact(
            'freezeAudits', 'freezeEvents', 'freezeScopes', 'rcs'
        ));
    }

    public function artifactExplorer()
    {
        $artifacts = DeploymentArtifactReport::latest()->paginate(20);

        $artifactTypes  = DeploymentArtifactReport::ARTIFACT_TYPES;
        $artifactStates = DeploymentArtifactReport::ARTIFACT_STATES;

        $verifiedCount = DeploymentArtifactReport::where('integrity_verified', true)->count();

        return view('release-stabilization.artifact-explorer', compact(
            'artifacts', 'artifactTypes', 'artifactStates', 'verifiedCount'
        ));
    }

    public function pilotReadiness()
    {
        $runs = PilotDeploymentPreparationRun::latest()->paginate(20);

        $scopes = PilotDeploymentPreparationRun::PREPARATION_SCOPES;
        $states = PilotDeploymentPreparationRun::PREPARATION_STATES;

        $maxEndpoints = ReleaseCandidateStabilizationService::MAX_PILOT_ENDPOINTS;

        return view('release-stabilization.pilot-readiness', compact(
            'runs', 'scopes', 'states', 'maxEndpoints'
        ));
    }

    public function operationalValidation()
    {
        $baselines = OperationalValidationBaseline::latest()->paginate(20);

        $domains = OperationalValidationBaseline::VALIDATION_DOMAINS;
        $states  = OperationalValidationBaseline::VALIDATION_STATES;

        $degradedCount = OperationalValidationBaseline::where('degradation_detected', true)->count();

        return view('release-stabilization.operational-validation', compact(
            'baselines', 'domains', 'states', 'degradedCount'
        ));
    }

    public function reproducibilityTimeline()
    {
        $reports = DeploymentReproducibilityReport::latest()->paginate(20);

        $deploymentScopes = DeploymentReproducibilityReport::DEPLOYMENT_SCOPES;

        return view('release-stabilization.reproducibility-timeline', compact(
            'reports', 'deploymentScopes'
        ));
    }

    public function blockerExplorer()
    {
        $blockers = ReleaseBlockerReport::latest()->paginate(20);

        $categories = ReleaseBlockerReport::BLOCKER_CATEGORIES;
        $severities = ReleaseBlockerReport::BLOCKER_SEVERITIES;
        $states     = ReleaseBlockerReport::BLOCKER_STATES;

        $criticalOpen = ReleaseBlockerReport::where('blocker_severity', 'critical')
            ->where('blocker_state', 'open')->count();

        return view('release-stabilization.blocker-explorer', compact(
            'blockers', 'categories', 'severities', 'states', 'criticalOpen'
        ));
    }

    public function stabilizationViewer()
    {
        $runs = StabilizationValidationRun::latest()->paginate(20);

        $validationTypes  = StabilizationValidationRun::VALIDATION_TYPES;
        $validationStates = StabilizationValidationRun::VALIDATION_STATES;

        return view('release-stabilization.stabilization-viewer', compact(
            'runs', 'validationTypes', 'validationStates'
        ));
    }

    public function auditTimeline()
    {
        $rcAudit    = ReleaseCandidateAudit::latest()->limit(50)->get();
        $eventTypes = ReleaseCandidateAudit::AUDIT_EVENT_TYPES;
        $actorTypes = ReleaseCandidateAudit::ACTOR_TYPES;

        return view('release-stabilization.audit-timeline', compact(
            'rcAudit', 'eventTypes', 'actorTypes'
        ));
    }
}
