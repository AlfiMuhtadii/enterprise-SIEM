<?php

namespace App\Http\Controllers;

use App\Models\AssetInventory;
use App\Services\AssetContextService;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * AssetInventoryController — asset/CMDB catalog UI.
 *
 * Advisory-only: asset criticality is displayed for analyst triage context,
 * never used to order/filter any listing or to trigger/gate a response action.
 */
class AssetInventoryController extends Controller
{
    public function __construct(
        private readonly AssetContextService $service,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);

        $assets = AssetInventory::where('tenant_id', $tenantId)
            ->with('criticality')
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('asset-inventory.index', [
            'assets' => $assets,
            'tenantId' => $tenantId,
            'stats' => $this->service->dashboardStats($tenantId),
            'environments' => AssetInventory::ENVIRONMENTS,
            'assetTypes' => AssetInventory::ASSET_TYPES,
            'tiers' => \App\Models\AssetCriticality::TIERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:64'],
            'owner' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', 'in:'.implode(',', AssetInventory::ENVIRONMENTS)],
            'asset_type' => ['required', 'in:'.implode(',', AssetInventory::ASSET_TYPES)],
        ]);

        $tenantId = $this->resolveTenantId($request);

        $asset = $this->service->registerAsset(
            tenantId: $tenantId,
            hostname: $data['hostname'],
            ipAddress: $data['ip_address'] ?? null,
            owner: $data['owner'] ?? null,
            environment: $data['environment'],
            assetType: $data['asset_type'],
            source: 'manual',
            createdBy: $request->user()->email,
        );

        AuditLogger::log($request->user()->email, 'asset.register', 'asset_inventory', $asset->external_id, null, $data);

        return back()->with('status', 'Asset registered.');
    }

    public function setCriticality(Request $request, int $assetId): RedirectResponse
    {
        $data = $request->validate([
            'criticality_tier' => ['required', 'in:'.implode(',', \App\Models\AssetCriticality::TIERS)],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenantId = $this->resolveTenantId($request);
        $asset = AssetInventory::where('tenant_id', $tenantId)->findOrFail($assetId);

        $criticality = $this->service->setCriticality(
            $asset->id,
            $data['criticality_tier'],
            $data['justification'] ?? null,
            $request->user()->email,
        );

        AuditLogger::log($request->user()->email, 'asset.criticality.set', 'asset_inventory', $asset->external_id, null, $data);

        return back()->with('status', "Criticality set to {$criticality->criticality_tier}.");
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $tenantId = $this->resolveTenantId($request);
        $result = $this->service->importCsv($tenantId, $data['csv']->getRealPath(), $request->user()->email);

        AuditLogger::log($request->user()->email, 'asset.import', 'asset_inventory', $tenantId, null, [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);

        return back()->with('status', "Imported {$result['imported']} assets, skipped {$result['skipped']}.");
    }

    private function resolveTenantId(Request $request): string
    {
        return $this->tenantAuthority->validateAndResolve($request, Auth::user()) ?? 'default';
    }
}
