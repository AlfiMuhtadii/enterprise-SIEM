<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\SecurityHardeningFreezeRun;
use App\Models\SecurityHardeningFreezeCheck;
use App\Models\SecurityHardeningFreezeCoverageReport;
use App\Models\SecurityHardeningFreezeDeltaReport;
use App\Services\SecurityHardeningEvidenceFreezeService;

class SecurityHardeningFreezeController extends Controller
{
    public function __construct(
        private readonly SecurityHardeningEvidenceFreezeService $svc
    ) {}

    public function index()
    {
        $latestRun = SecurityHardeningFreezeRun::latest()->first();
        $latestCoverage = $latestRun
            ? SecurityHardeningFreezeCoverageReport::where('run_id', $latestRun->run_id)->latest()->first()
            : null;

        return view('security-hardening-freeze.index', compact('latestRun', 'latestCoverage'));
    }

    public function runs()
    {
        $runs = SecurityHardeningFreezeRun::latest()->paginate(20);
        return view('security-hardening-freeze.runs', compact('runs'));
    }

    public function controls()
    {
        $latestRun = SecurityHardeningFreezeRun::latest()->first();
        $checks = $latestRun
            ? SecurityHardeningFreezeCheck::where('run_id', $latestRun->run_id)->get()
            : collect();

        $controlIds   = SecurityHardeningEvidenceFreezeService::CONTROL_IDS;
        $categories   = SecurityHardeningEvidenceFreezeService::CONTROL_CATEGORIES;

        return view('security-hardening-freeze.controls', compact(
            'latestRun', 'checks', 'controlIds', 'categories'
        ));
    }

    public function coverage()
    {
        $reports = SecurityHardeningFreezeCoverageReport::latest()->paginate(20);
        $minPassScore = SecurityHardeningEvidenceFreezeService::MIN_PASS_SCORE;
        return view('security-hardening-freeze.coverage', compact('reports', 'minPassScore'));
    }

    public function delta()
    {
        $deltas = SecurityHardeningFreezeDeltaReport::latest()->paginate(20);
        return view('security-hardening-freeze.delta', compact('deltas'));
    }
}
