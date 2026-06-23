<?php

namespace App\Http\Controllers\Dlq;

use App\Http\Controllers\Controller;
use App\Models\DlqRecord;
use App\Services\DlqReviewService;
use App\Services\TenantBoundaryService;
use Illuminate\Http\Request;

class DlqController extends Controller
{
    public function __construct(
        private readonly DlqReviewService $service,
        private readonly TenantBoundaryService $tenantBoundary,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $filters  = $request->only(['status', 'dlq_event_type', 'tenant_id', 'source_topic', 'replayable']);

        // If a tenant context is present, enforce it (overrides any explicit tenant_id filter)
        if ($tenantId !== null) {
            $filters['tenant_id'] = $tenantId;
        }

        $records = $this->service->getRecords($filters);
        $summary = $this->service->getSummary($tenantId);

        return view('dlq.records.index', compact('records', 'summary', 'filters'));
    }

    public function show(Request $request, string $recordId)
    {
        $tenantId = $request->header('X-Tenant-ID');
        $record   = DlqRecord::where('record_id', $recordId)->firstOrFail();

        $this->tenantBoundary->assertAccess($record->tenant_id, $tenantId);

        $events = $record->events()->orderByDesc('created_at')->get();

        return view('dlq.records.show', compact('record', 'events'));
    }

    public function review(Request $request, string $recordId)
    {
        $request->validate([
            'action' => ['required', 'in:reviewed,ignored,replay_requested'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $tenantId = $request->header('X-Tenant-ID');
        $record   = DlqRecord::where('record_id', $recordId)->firstOrFail();
        $analyst  = $request->user();
        $note     = $request->input('note');

        $this->tenantBoundary->assertAccess($record->tenant_id, $tenantId);

        try {
            match ($request->input('action')) {
                'reviewed'         => $this->service->review($record, $analyst, $note),
                'ignored'          => $this->service->ignore($record, $analyst, $note),
                'replay_requested' => $this->service->requestReplay($record, $analyst, $note),
            };
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('dlq.records.show', $recordId)
            ->with('success', 'Action recorded.');
    }
}
