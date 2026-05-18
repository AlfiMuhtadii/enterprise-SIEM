<?php

namespace App\Http\Controllers\Scenario;

use App\Http\Controllers\Controller;
use App\Models\ScenarioEvidence;
use App\Models\ScenarioRun;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScenarioEvidenceController extends Controller
{
    public function index(Request $request): View
    {
        $runId = $request->query('run_id');
        $stage = $request->query('stage');
        $status = $request->query('status');

        $query = ScenarioEvidence::with('run')->latest();

        if ($runId) {
            $query->where('scenario_run_id', $runId);
        }
        if ($stage) {
            $query->where('stage', $stage);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $evidence = $query->limit(200)->get();

        $runs = ScenarioRun::orderByDesc('created_at')->limit(50)->get();

        $stages = [
            'ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized',
            'correlation', 'xdr.alerts', 'alerts.created', 'incidents.updated',
            'xdr.alerts.shadow.endpoint',
        ];

        return view('scenario.evidence', [
            'evidence' => $evidence,
            'runs'     => $runs,
            'stages'   => $stages,
            'filters'  => compact('runId', 'stage', 'status'),
        ]);
    }
}
