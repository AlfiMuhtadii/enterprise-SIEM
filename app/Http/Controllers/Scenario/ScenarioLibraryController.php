<?php

namespace App\Http\Controllers\Scenario;

use App\Http\Controllers\Controller;
use App\Models\ScenarioRun;
use Illuminate\View\View;

class ScenarioLibraryController extends Controller
{
    public function index(): View
    {
        $scenarios = collect(config('scenarios.scenarios', []));

        $latestRuns = ScenarioRun::whereIn('scenario_id', $scenarios->pluck('id'))
            ->latest()
            ->get()
            ->groupBy('scenario_id')
            ->map(fn ($runs) => $runs->first());

        return view('scenario.library', [
            'scenarios' => $scenarios,
            'latestRuns' => $latestRuns,
        ]);
    }

    public function show(string $scenarioId): View
    {
        $scenarios = collect(config('scenarios.scenarios', []));
        $scenario = $scenarios->firstWhere('id', $scenarioId);
        abort_if(!$scenario, 404);

        $runs = ScenarioRun::where('scenario_id', $scenarioId)
            ->latest()
            ->limit(20)
            ->get();

        $activeRun = $runs->whereIn('status', ['pending', 'running'])->first();

        return view('scenario.show', [
            'scenario'  => $scenario,
            'runs'      => $runs,
            'activeRun' => $activeRun,
        ]);
    }
}
