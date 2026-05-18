<?php

namespace App\Http\Controllers\Scenario;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteScenarioRunJob;
use App\Models\ScenarioEvidence;
use App\Models\ScenarioRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScenarioRunController extends Controller
{
    public function index(): View
    {
        $runs = ScenarioRun::with('evidence')
            ->latest()
            ->limit(50)
            ->get();

        return view('scenario.runs', ['runs' => $runs]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'scenario_id' => ['required', 'string', 'max:100'],
            'run_mode'    => ['required', 'in:live,replay'],
        ]);

        $scenarios = collect(config('scenarios.scenarios', []));
        abort_if(!$scenarios->firstWhere('id', $request->scenario_id), 404);

        $prefix  = $request->run_mode === 'replay' ? 'replay' : 'scenario';
        $traceId = $prefix . '-' . now()->format('YmdHis')
                           . '-' . substr(str_replace('-', '', (string) str()->uuid()), 0, 8);

        $run = ScenarioRun::create([
            'scenario_id' => $request->scenario_id,
            'user_id'     => $request->user()->id,
            'status'      => 'pending',
            'run_mode'    => $request->run_mode,
            'trace_id'    => $traceId,
            'started_at'  => now(),
        ]);

        ExecuteScenarioRunJob::dispatch($run->id);

        return redirect()->route('scenario.runs.timeline', $run->id);
    }

    public function stop(Request $request, int $runId): RedirectResponse
    {
        $run = ScenarioRun::findOrFail($runId);

        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update([
                'status'            => 'stopped',
                'validation_result' => 'FAIL',
                'failure_reason'    => 'Run stopped manually before pipeline completion.',
                'recommendation'    => 'Re-run the scenario to complete all pipeline stages.',
                'completed_at'      => now(),
            ]);
        }

        return redirect()->route('scenario.library.show', $run->scenario_id)
            ->with('status', 'Run #' . $run->id . ' stopped.');
    }

    public function timeline(int $runId): View
    {
        $run = ScenarioRun::with('evidence')->findOrFail($runId);
        $scenario = $run->definition();
        abort_if(!$scenario, 404);

        return view('scenario.timeline', [
            'run'             => $run,
            'scenario'        => $scenario,
            'evidenceByStage' => $run->evidence->groupBy('stage'),
        ]);
    }

    public function runEvidence(int $runId): View
    {
        $run = ScenarioRun::with('evidence')->findOrFail($runId);
        $scenario = $run->definition();
        abort_if(!$scenario, 404);

        return view('scenario.run-evidence', [
            'run'             => $run,
            'scenario'        => $scenario,
            'evidenceByStage' => $run->evidence->groupBy('stage'),
            'stageGroups'     => [
                'Telemetry Ingestion' => ['ingestion', 'telemetry.raw'],
                'Normalization'       => ['normalizer', 'telemetry.normalized'],
                'Correlation'         => ['correlation', 'xdr.alerts', 'xdr.alerts.shadow.endpoint'],
                'Alert & Incident'    => ['alerts.created', 'incidents.updated'],
            ],
        ]);
    }

    public function runReport(int $runId): View
    {
        $run = ScenarioRun::with('evidence')->findOrFail($runId);
        $scenario = $run->definition();
        abort_if(!$scenario, 404);

        $impactMap = [
            'critical' => 'Critical business impact — immediate escalation required.',
            'high'     => 'High business impact — analyst review required within 1 hour.',
            'medium'   => 'Medium business impact — review within 4 hours.',
            'low'      => 'Low business impact — review during next triage cycle.',
        ];

        return view('scenario.run-report', [
            'run'             => $run,
            'scenario'        => $scenario,
            'evidenceByStage' => $run->evidence->groupBy('stage'),
            'results'         => $run->results ?? [],
            'totalLatencyMs'  => $run->evidence->sum('latency_ms'),
            'passedStages'    => $run->evidence->where('status', 'detected')->count(),
            'failedStages'    => $run->evidence->where('status', 'failed')->count(),
            'impact'          => $impactMap[$scenario['expected_detection']['severity'] ?? 'medium']
                                 ?? $impactMap['medium'],
        ]);
    }
}
