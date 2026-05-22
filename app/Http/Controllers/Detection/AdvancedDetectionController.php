<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Models\AdversarialValidationRun;
use App\Models\AttackChainTimeline;
use App\Models\AttackScenarioPack;
use App\Models\ChainedDetectionGraph;
use App\Models\CrossHostCorrelationRun;
use App\Models\DetectionConfidenceReport;
use App\Models\EvasionResilienceReport;
use App\Models\ReplayAttackFixture;
use App\Models\TacticProgressionSnapshot;
use App\Services\AdvancedDetectionService;

class AdvancedDetectionController extends Controller
{
    public function __construct(private AdvancedDetectionService $svc) {}

    public function attackCoverageDashboard()
    {
        $stats = $this->svc->getDashboardStats();
        return view('advanced-detection.attack-coverage-dashboard', compact('stats'));
    }

    public function attackChainExplorer()
    {
        $graphs = ChainedDetectionGraph::latest()->limit(50)->get();
        $types  = ChainedDetectionGraph::CHAIN_TYPES;
        return view('advanced-detection.attack-chain-explorer', compact('graphs', 'types'));
    }

    public function adversarialReplayConsole()
    {
        $runs  = AdversarialValidationRun::latest()->limit(50)->get();
        $packs = AttackScenarioPack::where('is_active', true)->get();
        return view('advanced-detection.adversarial-replay-console', compact('runs', 'packs'));
    }

    public function evasionResilienceViewer()
    {
        $reports      = EvasionResilienceReport::latest()->limit(50)->get();
        $evasionTypes = AdvancedDetectionService::EVASION_TYPES;
        return view('advanced-detection.evasion-resilience-viewer', compact('reports', 'evasionTypes'));
    }

    public function crossHostCorrelationExplorer()
    {
        $runs = CrossHostCorrelationRun::latest()->limit(50)->get();
        return view('advanced-detection.cross-host-correlation-explorer', compact('runs'));
    }

    public function credentialAbuseTimeline()
    {
        $events = AttackChainTimeline::where('tactic', 'credential_access')->latest('occurred_at')->limit(100)->get();
        return view('advanced-detection.credential-abuse-timeline', compact('events'));
    }

    public function lateralMovementGraph()
    {
        $runs   = CrossHostCorrelationRun::where('correlation_type', 'lateral_movement')->latest()->limit(50)->get();
        $chains = ChainedDetectionGraph::where('chain_type', 'lateral_movement')->latest()->limit(50)->get();
        return view('advanced-detection.lateral-movement-graph', compact('runs', 'chains'));
    }

    public function detectionConfidenceDashboard()
    {
        $reports   = DetectionConfidenceReport::latest()->limit(100)->get();
        $byRule    = $reports->groupBy('rule_id');
        return view('advanced-detection.detection-confidence-dashboard', compact('reports', 'byRule'));
    }

    public function attackScenarioPackExplorer()
    {
        $packs    = AttackScenarioPack::latest()->limit(50)->get();
        $fixtures = ReplayAttackFixture::where('is_active', true)->limit(50)->get();
        return view('advanced-detection.attack-scenario-pack-explorer', compact('packs', 'fixtures'));
    }
}
