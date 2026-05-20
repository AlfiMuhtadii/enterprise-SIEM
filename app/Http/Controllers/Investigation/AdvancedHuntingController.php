<?php

namespace App\Http\Controllers\Investigation;

use App\Http\Controllers\Controller;
use App\Models\InvestigationBookmark;
use App\Models\InvestigationEvidenceLink;
use App\Models\InvestigationGraphEdge;
use App\Models\InvestigationGraphNode;
use App\Models\InvestigationSession;
use App\Models\InvestigationTimelineEvent;
use App\Models\RetrospectiveHuntQuery;
use App\Services\InvestigationGraphService;
use App\Services\ThreatHuntingService;
use Illuminate\View\View;

/**
 * Advanced Threat Hunting & Investigation UI controller.
 * Advisory-only — no autonomous enforcement, no evidence deletion, no hidden graph mutation.
 */
class AdvancedHuntingController extends Controller
{
    public function __construct(
        private InvestigationGraphService $investigationGraph,
        private ThreatHuntingService      $hunting,
    ) {}

    public function workspace(): View
    {
        $sessions = $this->investigationGraph->getSessions();
        $stats    = $this->investigationGraph->getDashboardStats();
        return view('investigation.advanced.workspace', compact('sessions', 'stats'));
    }

    public function graphExplorer(string $sessionId): View
    {
        $session = InvestigationSession::where('session_id', $sessionId)->firstOrFail();
        $graph   = $this->investigationGraph->getSessionGraph($sessionId);
        return view('investigation.advanced.graph-explorer', compact('session', 'graph'));
    }

    public function attackTimeline(string $investigationId): View
    {
        $events    = $this->investigationGraph->getAttackTimeline($investigationId);
        $byTactic  = $events->groupBy('mitre_tactic');
        return view('investigation.advanced.attack-timeline', compact('investigationId', 'events', 'byTactic'));
    }

    public function multiHopPivot(): View
    {
        $domains  = $this->hunting->supportedDomains();
        return view('investigation.advanced.multi-hop-pivot', compact('domains'));
    }

    public function retrospectiveHunt(): View
    {
        $queries = RetrospectiveHuntQuery::orderByDesc('updated_at')->limit(100)->get();
        $domains = $this->hunting->supportedDomains();
        return view('investigation.advanced.retrospective-hunt', compact('queries', 'domains'));
    }

    public function evidenceRelationships(string $investigationId): View
    {
        $evidenceLinks = $this->investigationGraph->getEvidenceLinks($investigationId);
        $nodeCount     = InvestigationGraphNode::where('investigation_id', $investigationId)->count();
        $edgeCount     = InvestigationGraphEdge::where('investigation_id', $investigationId)->count();
        return view('investigation.advanced.evidence-relationships', compact(
            'investigationId', 'evidenceLinks', 'nodeCount', 'edgeCount'
        ));
    }

    public function attackInvestigationMap(): View
    {
        $techniques = InvestigationTimelineEvent::selectRaw('mitre_technique, mitre_tactic, count(*) as count')
            ->whereNotNull('mitre_technique')
            ->groupBy('mitre_technique', 'mitre_tactic')
            ->orderByDesc('count')
            ->limit(50)
            ->get();
        return view('investigation.advanced.attack-investigation-map', compact('techniques'));
    }

    public function crossDomainExplorer(): View
    {
        $domains     = $this->hunting->supportedDomains();
        $nodeTypes   = InvestigationGraphNode::NODE_TYPES;
        $edgeCount   = InvestigationGraphEdge::count();
        $nodeCount   = InvestigationGraphNode::count();
        return view('investigation.advanced.cross-domain-explorer', compact(
            'domains', 'nodeTypes', 'edgeCount', 'nodeCount'
        ));
    }

    public function sessionHistory(): View
    {
        $sessions   = InvestigationSession::orderByDesc('updated_at')->limit(100)->get();
        $stats      = $this->investigationGraph->getDashboardStats();
        return view('investigation.advanced.session-history', compact('sessions', 'stats'));
    }
}
