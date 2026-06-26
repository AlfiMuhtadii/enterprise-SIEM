<?php

namespace App\Http\Controllers;

use App\Models\EasmAssetRiskScore;
use App\Models\EasmFinding;
use App\Models\EasmPostureSnapshot;
use App\Models\EasmScanRun;
use App\Models\WebsiteAsset;
use App\Services\EasmPassiveScanService;
use App\Services\EasmPostureHistoryService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * EasmController — EASM passive posture monitoring UI + API.
 *
 * All operations are tenant-scoped. Findings are advisory-only and never
 * create SecurityIncident records or modify detection rules.
 */
class EasmController extends Controller
{
    public function __construct(
        private readonly EasmPassiveScanService      $service,
        private readonly EasmPostureHistoryService   $historyService,
        private readonly TenantContextAuthority      $tenantAuthority,
    ) {}

    // =========================================================================
    // Index — list website_assets for authenticated tenant (soc:easm.view)
    // =========================================================================

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);

        $assets = WebsiteAsset::where('tenant_id', $tenantId)
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('easm.index', compact('assets', 'tenantId'));
    }

    // =========================================================================
    // Store — register new asset (soc:easm.scan)
    // =========================================================================

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'url'         => 'required|string|max:2048',
            'name'        => 'nullable|string|max:255',
            'scan_policy' => 'nullable|in:passive,safe,active_approved',
        ]);

        $tenantId = $this->resolveTenantId($request);

        try {
            $asset = $this->service->registerAsset(
                $request->only(['url', 'name', 'scan_policy']),
                $tenantId,
                Auth::id() ? (string) Auth::id() : null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['asset' => $asset], 201);
    }

    // =========================================================================
    // Show — asset detail + latest scan run + findings (soc:easm.view)
    // =========================================================================

    public function show(Request $request, int $assetId): View|JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $asset = $this->service->validateOwnership($assetId, $tenantId);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        $latestRun = EasmScanRun::where('asset_id', $assetId)
            ->orderByDesc('started_at')
            ->first();

        $findings = EasmFinding::where('asset_id', $assetId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_seen_at')
            ->get();

        return view('easm.show', compact('asset', 'latestRun', 'findings', 'tenantId'));
    }

    // =========================================================================
    // Scan — trigger passive scan (soc:easm.scan)
    // =========================================================================

    public function scan(Request $request, int $assetId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $asset = $this->service->validateOwnership($assetId, $tenantId);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        // Dispatch artisan command (non-blocking)
        \Artisan::call('easm:scan', [
            'asset_id' => $assetId,
            '--tenant' => $tenantId,
            '--profile' => 'production',
        ]);

        return response()->json([
            'status'   => 'scan_dispatched',
            'asset_id' => $assetId,
            'policy'   => 'passive',
            'advisory' => true,
        ]);
    }

    // =========================================================================
    // Findings — list findings for asset (soc:easm.view)
    // =========================================================================

    public function findings(Request $request, int $assetId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $this->service->validateOwnership($assetId, $tenantId);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $findings = EasmFinding::where('asset_id', $assetId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_seen_at')
            ->paginate(50);

        return response()->json($findings);
    }

    // =========================================================================
    // History — posture trend for asset (soc:easm.view)
    // =========================================================================

    public function history(Request $request, int $assetId): View|JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $asset = $this->service->validateOwnership($assetId, $tenantId);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        $report     = $this->historyService->generateTrendReport($asset);
        $riskScore  = EasmAssetRiskScore::where('asset_id', $assetId)
            ->where('tenant_id', $tenantId)
            ->first();

        $snapshots  = EasmPostureSnapshot::where('asset_id', $assetId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('snapshot_at')
            ->limit(10)
            ->get();

        return view('easm.history', compact('asset', 'report', 'riskScore', 'snapshots', 'tenantId'));
    }

    // =========================================================================
    // Summary — tenant-level EASM summary (soc:easm.view)
    // =========================================================================

    public function summary(Request $request): View|JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $summary  = $this->historyService->getTenantEasmSummary($tenantId);

        $riskScores = EasmAssetRiskScore::where('tenant_id', $tenantId)
            ->orderBy('risk_score')
            ->with('asset')
            ->get();

        return view('easm.summary', compact('summary', 'riskScores', 'tenantId'));
    }

    // =========================================================================
    // Report — JSON posture trend report (soc:easm.view)
    // =========================================================================

    public function report(Request $request, int $assetId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $asset = $this->service->validateOwnership($assetId, $tenantId);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $report = $this->historyService->generateTrendReport($asset);

        return response()->json($report);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resolveTenantId(Request $request): string
    {
        return $this->tenantAuthority->validateAndResolve($request, Auth::user()) ?? 'default';
    }
}
