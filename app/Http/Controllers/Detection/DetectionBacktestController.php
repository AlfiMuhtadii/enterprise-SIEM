<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Models\DetectionBacktestRun;
use App\Services\DetectionBacktestService;
use Illuminate\Http\Request;

/**
 * CAP-DETECT-BACKTEST: replay-safe historical backtest UI. Advisory only —
 * never writes to security_alerts/security_incidents.
 */
class DetectionBacktestController extends Controller
{
    public function __construct(private DetectionBacktestService $service) {}

    public function index()
    {
        $runs = DetectionBacktestRun::orderByDesc('created_at')->limit(50)->get();
        $supportedRuleIds = DetectionBacktestService::SUPPORTED_RULE_IDS;

        return view('detection.backtest', compact('runs', 'supportedRuleIds'));
    }

    public function show(string $runId)
    {
        $run = DetectionBacktestRun::where('run_id', $runId)->firstOrFail();
        $matches = $run->matches()->orderByDesc('event_count')->get();

        return view('detection.backtest-show', compact('run', 'matches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rule_ids' => 'required|array|min:1',
            'rule_ids.*' => 'in:'.implode(',', DetectionBacktestService::SUPPORTED_RULE_IDS),
            'days' => 'required|integer|min:1|max:90',
        ]);

        $run = $this->service->run($data['rule_ids'], (int) $data['days'], auth()->id());

        return redirect()->route('detection.backtest.show', $run->run_id)->with('success', 'Backtest complete.');
    }
}
