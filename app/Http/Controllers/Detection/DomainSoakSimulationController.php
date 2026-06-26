<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Services\DomainSoakSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ENTERPRISE-057: Domain Soak Simulation view. Read-only. Advisory-only. */
class DomainSoakSimulationController extends Controller
{
    public function __construct(
        private readonly DomainSoakSimulationService $service,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $simulations = $this->service->getSimulations();

        if ($request->wantsJson()) {
            return response()->json([
                'advisory_only'        => true,
                'promotion_recommended'=> false,
                'real_soak_required'   => true,
                'supported_domains'    => DomainSoakSimulationService::SUPPORTED_DOMAINS,
                'simulations'          => $simulations->all(),
            ]);
        }

        return view('detection.domain_soak_simulations', compact('simulations'));
    }
}
