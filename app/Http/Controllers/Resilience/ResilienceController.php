<?php

namespace App\Http\Controllers\Resilience;

use App\Http\Controllers\Controller;
use App\Models\ResilienceMetric;
use App\Models\ResilienceRun;
use App\Services\ResilienceValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ResilienceController extends Controller
{
    public function __construct(private ResilienceValidationService $resilience) {}

    public function index(): View
    {
        $scenarios  = $this->resilience->getScenarios();
        $recentRuns = ResilienceRun::with('metrics')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        $summary = [
            'total'  => ResilienceRun::count(),
            'passed' => ResilienceRun::where('status', ResilienceRun::STATUS_PASSED)->count(),
            'failed' => ResilienceRun::where('status', ResilienceRun::STATUS_FAILED)->count(),
            'error'  => ResilienceRun::where('status', ResilienceRun::STATUS_ERROR)->count(),
        ];

        $scenarioStatus = $this->latestStatusPerScenario();

        return view('resilience.dashboard', compact('scenarios', 'recentRuns', 'summary', 'scenarioStatus'));
    }

    public function history(): View
    {
        $runs = ResilienceRun::orderByDesc('started_at')->paginate(50);
        return view('resilience.history', compact('runs'));
    }

    public function show(string $runId): View
    {
        $run = ResilienceRun::with('metrics')->where('run_id', $runId)->firstOrFail();
        return view('resilience.show', compact('run'));
    }

    public function run(Request $request)
    {
        $scenarioId = $request->input('scenario_id', 'all');

        if ($scenarioId === 'all') {
            $runs = $this->resilience->runAll();
            return redirect()->route('resilience.index')
                ->with('success', 'All ' . count($runs) . ' resilience scenarios executed.');
        }

        $run = $this->resilience->runScenario($scenarioId);
        return redirect()->route('resilience.show', $run->run_id)
            ->with('success', "Scenario '{$run->scenario_name}' completed with status: {$run->status}");
    }

    private function latestStatusPerScenario(): array
    {
        $latest = ResilienceRun::select('scenario_id', 'status', 'started_at')
            ->whereIn('id', function ($q) {
                $q->select(DB::raw('MAX(id)'))
                    ->from('resilience_runs')
                    ->groupBy('scenario_id');
            })
            ->get()
            ->keyBy('scenario_id');

        $result = [];
        foreach (array_keys(ResilienceValidationService::SCENARIOS) as $id) {
            $result[$id] = $latest->get($id)?->status ?? 'never_run';
        }
        return $result;
    }
}
