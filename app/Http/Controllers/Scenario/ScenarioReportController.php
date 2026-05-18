<?php

namespace App\Http\Controllers\Scenario;

use App\Http\Controllers\Controller;
use App\Models\ScenarioRun;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ScenarioReportController extends Controller
{
    public function index(): View
    {
        $runs = ScenarioRun::with('evidence')
            ->whereNotNull('validation_result')
            ->latest()
            ->limit(100)
            ->get();

        $summary = [
            'total'   => $runs->count(),
            'passed'  => $runs->where('validation_result', 'PASS')->count(),
            'partial' => $runs->where('validation_result', 'PARTIAL')->count(),
            'failed'  => $runs->where('validation_result', 'FAIL')->count(),
        ];

        return view('scenario.reports', [
            'runs'    => $runs,
            'summary' => $summary,
        ]);
    }

    public function export(int $runId): Response
    {
        $run = ScenarioRun::with('evidence')->findOrFail($runId);
        $scenario = $run->definition();

        $report = [
            'run_id'            => $run->id,
            'scenario_id'       => $run->scenario_id,
            'scenario_title'    => $scenario['title'] ?? $run->scenario_id,
            'run_mode'          => $run->run_mode,
            'status'            => $run->status,
            'validation_result' => $run->validation_result,
            'alerts_detected'   => $run->alerts_detected,
            'detection_passed'  => $run->detection_passed,
            'failure_reason'    => $run->failure_reason,
            'recommendation'    => $run->recommendation,
            'trace_id'          => $run->trace_id,
            'started_at'        => $run->started_at?->toIso8601String(),
            'completed_at'      => $run->completed_at?->toIso8601String(),
            'duration_seconds'  => $run->durationSeconds(),
            'evidence'          => $run->evidence->map(fn ($e) => [
                'stage'        => $e->stage,
                'event_id'     => $e->event_id,
                'trace_id'     => $e->trace_id,
                'rule_id'      => $e->rule_id,
                'severity'     => $e->severity,
                'status'       => $e->status,
                'processed_at' => $e->processed_at?->toIso8601String(),
            ])->values()->toArray(),
        ];

        $filename = 'scenario-report-' . $run->scenario_id . '-' . $run->id . '.json';

        return response(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type'        => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
