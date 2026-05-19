<?php

namespace App\Http\Controllers\Endpoint;

use App\Http\Controllers\Controller;
use App\Models\EndpointAgent;
use App\Models\EndpointStreamEvent;
use App\Services\EndpointStreamingService;
use Illuminate\Http\Request;

class EndpointStreamController extends Controller
{
    public function __construct(private readonly EndpointStreamingService $svc) {}

    public function activityFeed(Request $request)
    {
        $agentId   = $request->query('agent_id');
        $eventType = $request->query('event_type');
        $events    = $this->svc->getRecentEvents(100, $agentId ?: null, $eventType ?: null);
        $agents    = EndpointAgent::orderBy('hostname')->get(['agent_id', 'hostname']);
        return view('endpoint.stream.activity-feed', compact('events', 'agents', 'agentId', 'eventType'));
    }

    public function monitor()
    {
        $health      = $this->svc->getStreamHealthSummary();
        $checkpoints = $this->svc->getLatestCheckpoints(20);
        return view('endpoint.stream.monitor', compact('health', 'checkpoints'));
    }

    public function health()
    {
        $health = $this->svc->getStreamHealthSummary();
        return view('endpoint.stream.health', compact('health'));
    }

    public function eventTimeline(Request $request, string $agentId)
    {
        $agent  = EndpointAgent::where('agent_id', $agentId)->firstOrFail();
        $events = $this->svc->getAgentTimeline($agentId);
        $gaps   = $this->svc->detectSequenceGaps($agentId);
        return view('endpoint.stream.event-timeline', compact('agent', 'events', 'gaps'));
    }

    public function burstActivity()
    {
        $summary   = $this->svc->getBurstActivitySummary();
        $eventTypes = EndpointStreamEvent::EVENT_TYPES;
        return view('endpoint.stream.burst-activity', compact('summary', 'eventTypes'));
    }

    public function replayInspector(Request $request)
    {
        $agentId      = $request->query('agent_id');
        $fromSequence = (int) $request->query('from_sequence', 0);
        $events       = [];
        $gaps         = [];
        $agents       = EndpointAgent::orderBy('hostname')->get(['agent_id', 'hostname']);

        if ($agentId && $fromSequence > 0) {
            $events = $this->svc->getReplayWindow($agentId, $fromSequence);
            $gaps   = $this->svc->detectSequenceGaps($agentId);
        }

        return view('endpoint.stream.replay-inspector', compact('agents', 'agentId', 'fromSequence', 'events', 'gaps'));
    }
}
