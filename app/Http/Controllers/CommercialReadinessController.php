<?php

namespace App\Http\Controllers;

use App\Models\TenantOnboardingRun;
use App\Models\DeploymentPackageProfile;
use App\Models\CommercialReleaseHistory;
use App\Models\SupportBundleExport;
use App\Models\DeploymentReadinessReport;
use App\Models\CustomerOperationsHealth;
use App\Models\OnboardingCheckpointHistory;
use App\Models\ReleaseCompatibilityReport;
use App\Models\CommercialProductAudit;
use App\Services\CommercialReadinessService;
use Illuminate\View\View;

class CommercialReadinessController extends Controller
{
    public function __construct(private CommercialReadinessService $service) {}

    public function dashboard(): View
    {
        return view('commercial-readiness.dashboard', [
            'stats' => $this->service->dashboardStats(),
        ]);
    }

    public function tenantOnboarding(): View
    {
        return view('commercial-readiness.tenant-onboarding', [
            'runs' => TenantOnboardingRun::latest()->paginate(50),
        ]);
    }

    public function deploymentPackages(): View
    {
        return view('commercial-readiness.deployment-packages', [
            'profiles' => DeploymentPackageProfile::latest()->paginate(50),
        ]);
    }

    public function releaseTimeline(): View
    {
        return view('commercial-readiness.release-timeline', [
            'releases' => CommercialReleaseHistory::latest()->paginate(50),
        ]);
    }

    public function readinessConsole(): View
    {
        return view('commercial-readiness.readiness-console', [
            'reports' => DeploymentReadinessReport::latest()->paginate(50),
        ]);
    }

    public function supportDiagnostics(): View
    {
        return view('commercial-readiness.support-diagnostics', [
            'bundles' => SupportBundleExport::latest()->paginate(50),
        ]);
    }

    public function customerHealth(): View
    {
        return view('commercial-readiness.customer-health', [
            'records' => CustomerOperationsHealth::latest()->paginate(50),
        ]);
    }

    public function upgradeCompatibility(): View
    {
        return view('commercial-readiness.upgrade-compatibility', [
            'reports' => ReleaseCompatibilityReport::latest()->paginate(50),
        ]);
    }

    public function auditTimeline(): View
    {
        return view('commercial-readiness.audit-timeline', [
            'events' => CommercialProductAudit::latest()->paginate(50),
        ]);
    }
}
