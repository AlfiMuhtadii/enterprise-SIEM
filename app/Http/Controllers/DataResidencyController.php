<?php

namespace App\Http\Controllers;

use App\Models\DataErasureRequest;
use App\Models\TenantRetentionPolicy;
use App\Services\DataResidencyErasureService;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * DataResidencyController — per-tenant retention policy + GDPR erasure
 * request lifecycle UI. Approval only changes request status; real deletion
 * happens exclusively via `php artisan data-erasure:execute` (CLI-only,
 * matching the platform's existing destructive-operation posture).
 */
class DataResidencyController extends Controller
{
    public function __construct(
        private readonly DataResidencyErasureService $service,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);

        return view('data-residency.index', [
            'tenantId' => $tenantId,
            'policy' => $this->service->getRetentionPolicy($tenantId),
            'requests' => $this->service->requestsForTenant($tenantId),
            'statuses' => DataErasureRequest::STATUSES,
        ]);
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'events_days' => ['nullable', 'integer', 'min:1'],
            'alerts_days' => ['nullable', 'integer', 'min:1'],
            'incidents_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = $this->resolveTenantId($request);

        $policy = $this->service->setRetentionPolicy(
            $tenantId,
            $data['events_days'] ?? null,
            $data['alerts_days'] ?? null,
            $data['incidents_days'] ?? null,
            $request->user()->email,
        );

        AuditLogger::log($request->user()->email, 'retention.policy.update', 'tenant_retention_policy', $tenantId, null, $data, tenantId: $tenantId);

        return back()->with('status', 'Retention policy updated.');
    }

    public function requestErasure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->resolveTenantId($request);

        $erasureRequest = $this->service->requestErasure(
            $tenantId,
            $data['reason'],
            $request->user()->email,
            $request->boolean('dry_run', true),
        );

        AuditLogger::log($request->user()->email, 'erasure.request', 'data_erasure_request', $erasureRequest->request_id, null, $data, tenantId: $tenantId);

        return back()->with('status', "Erasure request {$erasureRequest->request_id} submitted (pending approval).");
    }

    public function approveErasure(Request $request, int $requestId): RedirectResponse
    {
        try {
            $erasureRequest = $this->service->approveErasure($requestId, $request->user()->email);
        } catch (\Throwable $e) {
            return back()->withErrors(['erasure' => $e->getMessage()]);
        }

        AuditLogger::log($request->user()->email, 'erasure.approve', 'data_erasure_request', $erasureRequest->request_id, null, null);

        return back()->with('status', "Erasure request {$erasureRequest->request_id} approved. Execute via: php artisan data-erasure:execute {$erasureRequest->request_id}");
    }

    public function rejectErasure(Request $request, int $requestId): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $erasureRequest = $this->service->rejectErasure($requestId, $request->user()->email, $data['notes'] ?? null);
        } catch (\Throwable $e) {
            return back()->withErrors(['erasure' => $e->getMessage()]);
        }

        AuditLogger::log($request->user()->email, 'erasure.reject', 'data_erasure_request', $erasureRequest->request_id, null, $data);

        return back()->with('status', "Erasure request {$erasureRequest->request_id} rejected.");
    }

    private function resolveTenantId(Request $request): string
    {
        return $this->tenantAuthority->validateAndResolve($request, Auth::user()) ?? 'default';
    }
}
