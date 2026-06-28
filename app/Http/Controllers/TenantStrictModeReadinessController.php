<?php

namespace App\Http\Controllers;

use App\Services\TenantStrictModeReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantStrictModeReadinessController extends Controller
{
    public function __construct(private readonly TenantStrictModeReadinessService $service) {}

    public function index(): View
    {
        $history = $this->service->getHistory(10);
        $latest  = $history->first();
        $gates   = $latest ? $this->service->getGateResults($latest->assessment_id) : collect();

        return view('soc.tenant-strict-mode-readiness.index', [
            'history' => $history,
            'latest'  => $latest,
            'gates'   => $gates,
            'passThreshold' => TenantStrictModeReadinessService::PASS_THRESHOLD,
        ]);
    }

    public function assess(Request $request): RedirectResponse
    {
        $this->service->assess($request->user()->email);
        return redirect()->route('soc.tenant.strict-mode-readiness.index')
            ->with('status', 'Readiness assessment complete.');
    }

    public function history(): View
    {
        return view('soc.tenant-strict-mode-readiness.history', [
            'history' => $this->service->getHistory(50),
        ]);
    }
}
