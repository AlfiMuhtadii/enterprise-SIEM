<?php

namespace App\Http\Controllers\Entity;

use App\Http\Controllers\Controller;
use App\Services\EntityRiskScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EntityRiskController extends Controller
{
    public function __construct(private readonly EntityRiskScoringService $scorer) {}

    public function dashboard(): View
    {
        $topUsers   = $this->scorer->getTopRiskyEntities('user', 10);
        $topHosts   = $this->scorer->getTopRiskyEntities('host', 10);
        $topIps     = $this->scorer->getTopRiskyEntities('ip', 10);
        $topDomains = $this->scorer->getTopRiskyEntities('domain', 10);

        $distribution = $this->scorer->getRiskDistribution()->keyBy('risk_level');

        $recentSnapshots = DB::table('entity_risk_snapshots')
            ->whereIn('risk_level', ['critical', 'high'])
            ->orderByDesc('calculated_at')
            ->limit(20)
            ->get();

        $totalScored = DB::table('entities')->whereNotNull('risk_level')->count();

        return view('entity.risk-dashboard', compact(
            'topUsers', 'topHosts', 'topIps', 'topDomains',
            'distribution', 'recentSnapshots', 'totalScored'
        ));
    }

    public function breakdown(int $id): View
    {
        $entity = DB::table('entities')->where('id', $id)->first();
        abort_if(!$entity, 404);

        $factors = $entity->risk_factors ? json_decode($entity->risk_factors, true) : [];
        $history = $this->scorer->getRiskHistory($id, 20);

        return view('entity.risk-breakdown', compact('entity', 'factors', 'history'));
    }
}
