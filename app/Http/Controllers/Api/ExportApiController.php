<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportApiController extends Controller
{
    public function __construct(
        private readonly ReportExportService $exporter,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function history(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $filters = [];
        if ($request->filled('type')) $filters['export_type'] = $request->get('type');

        $history = $this->exporter->getHistory($filters, 100, $tenantId);
        $counts  = $this->exporter->getStatCounts($tenantId);

        return response()->json([
            'history' => $history,
            'total'   => $history->count(),
            'counts'  => $counts,
        ]);
    }

    public function exportInvestigation(Request $request, int $id): Response|JsonResponse
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
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function exportResponsePlan(Request $request, int $id): Response|JsonResponse
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
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function exportEntityRisk(Request $request, int $id): Response|JsonResponse
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
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }

    public function exportTrace(Request $request, string $traceId): Response|JsonResponse
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
            return response()->json(['error' => $e->getMessage()], 404);
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
