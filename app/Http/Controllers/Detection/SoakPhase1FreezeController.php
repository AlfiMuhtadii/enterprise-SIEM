<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\Phase1SoakEvidenceFreezeService;
use Illuminate\View\View;

class SoakPhase1FreezeController extends Controller
{
    public function index(Phase1SoakEvidenceFreezeService $service): View
    {
        $latest = $service->getLatestFreeze();

        $summary  = $latest['summary']  ?? ['gates_passed' => 0, 'gates_total' => 12, 'pass_score' => 0, 'verdict' => 'NO_RUN', 'freeze_approved' => false];
        $gates    = $latest['gates']    ?? [];
        $evidence = $latest['evidence'] ?? [];

        return view('detection.phase1_soak_freeze', compact('summary', 'gates', 'evidence'));
    }
}
