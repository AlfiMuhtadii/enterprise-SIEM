<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetworkAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkAnalyticsApiController extends Controller
{
    public function __construct(private readonly NetworkAnalyticsService $svc) {}

    public function getDashboard(): JsonResponse
    {
        return response()->json($this->svc->getNetworkDashboardSummary());
    }

    public function getDnsEvents(Request $request): JsonResponse
    {
        return response()->json([
            'events' => $this->svc->getRecentDnsEvents(50, $request->query('host_id')),
        ]);
    }

    public function getProxyEvents(Request $request): JsonResponse
    {
        return response()->json([
            'events' => $this->svc->getRecentProxyEvents(50, $request->query('host_id')),
        ]);
    }

    public function getFirewallEvents(Request $request): JsonResponse
    {
        return response()->json([
            'events' => $this->svc->getRecentFirewallEvents(50, $request->query('host_id')),
        ]);
    }

    public function getFindings(Request $request): JsonResponse
    {
        return response()->json([
            'findings'      => $this->svc->getRecentFindings(50, $request->query('source')),
            'advisory_note' => 'Network analytics are shadow-only and advisory. No blocking or containment is executed.',
        ]);
    }

    public function ingestDns(Request $request): JsonResponse
    {
        $events  = $request->json('events', []);
        $results = [];
        foreach (array_slice($events, 0, 200) as $raw) {
            $results[] = $this->svc->ingestDnsEvent($raw)->event_id;
        }
        return response()->json(['accepted' => count($results)], 202);
    }

    public function ingestProxy(Request $request): JsonResponse
    {
        $events  = $request->json('events', []);
        $results = [];
        foreach (array_slice($events, 0, 200) as $raw) {
            $results[] = $this->svc->ingestProxyEvent($raw)->event_id;
        }
        return response()->json(['accepted' => count($results)], 202);
    }

    public function ingestFirewall(Request $request): JsonResponse
    {
        $events  = $request->json('events', []);
        $results = [];
        foreach (array_slice($events, 0, 200) as $raw) {
            $results[] = $this->svc->ingestFirewallEvent($raw)->event_id;
        }
        return response()->json(['accepted' => count($results)], 202);
    }

    public function pivot(Request $request): JsonResponse
    {
        $type = $request->query('type', 'domain');
        $key  = $request->query('key', '');
        if (!$key) {
            return response()->json(['error' => 'key required'], 422);
        }

        $result = match ($type) {
            'domain' => $this->svc->pivotDomainToHosts($key),
            'ip'     => $this->svc->pivotIpToHosts($key),
            'user'   => $this->svc->pivotUserToDomains($key),
            'host'   => $this->svc->pivotHostToOutboundDestinations($key),
            default  => ['error' => 'unsupported pivot type'],
        };
        return response()->json($result);
    }
}
