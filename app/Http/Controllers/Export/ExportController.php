<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ExportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $exporter,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $counts  = $this->exporter->getStatCounts($tenantId);
        $recent  = $this->exporter->getHistory([], 10, $tenantId);

        return view('export.index', compact('counts', 'recent'));
    }

    public function history(Request $request): View
    {
        $tenantId  = $this->tenantId($request);
        $typeFilter = (string) $request->get('type', '');
        $filters    = $typeFilter ? ['export_type' => $typeFilter] : [];
        $history    = $this->exporter->getHistory($filters, 100, $tenantId);

        return view('export.history', compact('history', 'typeFilter'));
    }

    public function downloadInvestigation(Request $request, int $id): Response|RedirectResponse
    {
        $data = $request->validate([
            'format' => 'required|in:json,markdown,html',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tenantId = $this->tenantId($request);
            $result = $this->exporter->exportInvestigation(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null, $tenantId
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function downloadResponsePlan(Request $request, int $id): Response|RedirectResponse
    {
        $data = $request->validate([
            'format' => 'required|in:json,markdown,html',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tenantId = $this->tenantId($request);
            $result = $this->exporter->exportResponsePlan(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null, $tenantId
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function downloadEntityRisk(Request $request, int $id): Response|RedirectResponse
    {
        $data = $request->validate([
            'format' => 'required|in:json,markdown,html',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tenantId = $this->tenantId($request);
            $result = $this->exporter->exportEntityRisk(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null, $tenantId
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function downloadTrace(Request $request, string $traceId): Response|RedirectResponse
    {
        $data = $request->validate([
            'format' => 'required|in:json,markdown,html',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tenantId = $this->tenantId($request);
            $result = $this->exporter->exportTrace(
                $traceId, $data['format'], auth()->id(), $data['reason'] ?? null, $tenantId
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    private function tenantId(Request $request): ?string
    {
        return $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: true);
    }
}
