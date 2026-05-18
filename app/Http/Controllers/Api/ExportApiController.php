<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportApiController extends Controller
{
    public function __construct(private readonly ReportExportService $exporter) {}

    public function history(Request $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('type')) $filters['export_type'] = $request->get('type');

        $history = $this->exporter->getHistory($filters, 100);
        $counts  = $this->exporter->getStatCounts();

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
            $result = $this->exporter->exportInvestigation(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null
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
            $result = $this->exporter->exportResponsePlan(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null
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
            $result = $this->exporter->exportEntityRisk(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null
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
            $result = $this->exporter->exportTrace(
                $traceId, $data['format'], auth()->id(), $data['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }
}
