<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ExportController extends Controller
{
    public function __construct(private readonly ReportExportService $exporter) {}

    public function index(): View
    {
        $counts  = $this->exporter->getStatCounts();
        $recent  = $this->exporter->getHistory([], 10);

        return view('export.index', compact('counts', 'recent'));
    }

    public function history(Request $request): View
    {
        $typeFilter = (string) $request->get('type', '');
        $filters    = $typeFilter ? ['export_type' => $typeFilter] : [];
        $history    = $this->exporter->getHistory($filters, 100);

        return view('export.history', compact('history', 'typeFilter'));
    }

    public function downloadInvestigation(Request $request, int $id): Response|RedirectResponse
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
            $result = $this->exporter->exportResponsePlan(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null
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
            $result = $this->exporter->exportEntityRisk(
                $id, $data['format'], auth()->id(), $data['reason'] ?? null
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
            $result = $this->exporter->exportTrace(
                $traceId, $data['format'], auth()->id(), $data['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }
}
