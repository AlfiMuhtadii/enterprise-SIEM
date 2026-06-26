<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\PilotTenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PilotTenantOnboardingController extends Controller
{
    public function __construct(private readonly PilotTenantOnboardingService $service) {}

    public function index(Request $request): View|JsonResponse
    {
        $tenants = $this->service->getPilotTenants();

        $viewData = [
            'tenants'       => $tenants,
            'max_tenants'   => PilotTenantOnboardingService::MAX_PILOT_TENANTS,
            'advisory_only' => true,
        ];

        if ($request->expectsJson()) {
            return response()->json($viewData);
        }

        return view('tenant.pilot_onboarding', $viewData);
    }

    public function show(Request $request, string $tenantId): View|JsonResponse
    {
        $health = $this->service->validateTenantHealth($tenantId);
        $events = $this->service->getTenantEvents($tenantId);

        $viewData = [
            'tenant_id'    => $tenantId,
            'health'       => $health,
            'events'       => $events,
            'advisory_only'=> true,
        ];

        if ($request->expectsJson()) {
            return response()->json($viewData);
        }

        return view('tenant.pilot_tenant_detail', $viewData);
    }
}
