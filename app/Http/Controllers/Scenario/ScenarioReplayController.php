<?php

namespace App\Http\Controllers\Scenario;

use App\Http\Controllers\Controller;
use App\Models\ScenarioRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScenarioReplayController extends Controller
{
    public function index(): View
    {
        $runs = ScenarioRun::where('run_mode', 'replay')
            ->latest()
            ->limit(50)
            ->get();

        return view('scenario.replay', ['runs' => $runs]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'scenario_id'    => ['required', 'string', 'max:100'],
            'source_run_id'  => ['nullable', 'integer', 'exists:scenario_runs,id'],
        ]);

        $scenarios = collect(config('scenarios.scenarios', []));
        abort_if(!$scenarios->firstWhere('id', $request->scenario_id), 404);

        $run = ScenarioRun::create([
            'scenario_id' => $request->scenario_id,
            'user_id'     => $request->user()->id,
            'status'      => 'pending',
            'run_mode'    => 'replay',
            'trace_id'    => 'replay-' . now()->format('YmdHis') . '-' . substr(str_replace('-', '', (string) str()->uuid()), 0, 8),
            'config'      => $request->source_run_id ? ['source_run_id' => $request->source_run_id] : null,
            'started_at'  => now(),
        ]);

        return redirect()->route('scenario.runs.timeline', $run->id);
    }
}
