<?php

namespace App\Http\Controllers\Dlq;

use App\Http\Controllers\Controller;
use App\Models\DlqRecord;
use App\Services\DlqReviewService;
use Illuminate\Http\Request;

class DlqController extends Controller
{
    public function __construct(private readonly DlqReviewService $service) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'dlq_event_type', 'tenant_id', 'source_topic', 'replayable']);
        $records = $this->service->getRecords($filters);
        $summary = $this->service->getSummary();

        return view('dlq.records.index', compact('records', 'summary', 'filters'));
    }

    public function show(string $recordId)
    {
        $record = DlqRecord::where('record_id', $recordId)->firstOrFail();
        $events = $record->events()->orderByDesc('created_at')->get();

        return view('dlq.records.show', compact('record', 'events'));
    }

    public function review(Request $request, string $recordId)
    {
        $request->validate([
            'action' => ['required', 'in:reviewed,ignored,replay_requested'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $record = DlqRecord::where('record_id', $recordId)->firstOrFail();
        $analyst = $request->user();
        $note    = $request->input('note');

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
