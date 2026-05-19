<?php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Models\NetworkBehavioralFinding;
use App\Models\NetworkDestinationProfile;
use App\Services\NetworkAnalyticsService;
use Illuminate\Http\Request;

class NetworkAnalyticsController extends Controller
{
    public function __construct(private readonly NetworkAnalyticsService $svc) {}

    public function dashboard()
    {
        $summary  = $this->svc->getNetworkDashboardSummary();
        $findings = $this->svc->getRecentFindings(10);
        return view('network.analytics-dashboard', compact('summary', 'findings'));
    }

    public function dnsInvestigation(Request $request)
    {
        $hostId  = $request->query('host_id');
        $events  = $this->svc->getRecentDnsEvents(100, $hostId ?: null);
        $findings= $this->svc->getRecentFindings(20, 'dns');
        return view('network.dns-investigation', compact('events', 'findings', 'hostId'));
    }

    public function proxyInvestigation(Request $request)
    {
        $hostId  = $request->query('host_id');
        $events  = $this->svc->getRecentProxyEvents(100, $hostId ?: null);
        $findings= $this->svc->getRecentFindings(20, 'proxy');
        return view('network.proxy-investigation', compact('events', 'findings', 'hostId'));
    }

    public function firewallInvestigation(Request $request)
    {
        $hostId  = $request->query('host_id');
        $events  = $this->svc->getRecentFirewallEvents(100, $hostId ?: null);
        $findings= $this->svc->getRecentFindings(20, 'firewall');
        return view('network.firewall-investigation', compact('events', 'findings', 'hostId'));
    }

    public function destinationProfile(Request $request)
    {
        $key     = $request->query('key');
        $profile = $key ? NetworkDestinationProfile::where('destination_key', $key)->first() : null;
        $rare    = NetworkDestinationProfile::where('is_rare', true)->orderByDesc('contact_count')->limit(20)->get();
        return view('network.destination-profile', compact('profile', 'rare', 'key'));
    }

    public function behavioralFindings(Request $request)
    {
        $source  = $request->query('source');
        $findings= $this->svc->getRecentFindings(100, $source ?: null);
        $sources = [NetworkBehavioralFinding::SOURCE_DNS, NetworkBehavioralFinding::SOURCE_PROXY, NetworkBehavioralFinding::SOURCE_FIREWALL];
        return view('network.behavioral-findings', compact('findings', 'sources', 'source'));
    }

    public function threatHuntingPivots(Request $request)
    {
        $pivotType = $request->query('pivot_type', 'domain');
        $pivotKey  = $request->query('pivot_key', '');
        $result    = null;

        if ($pivotKey) {
            $result = match ($pivotType) {
                'domain' => $this->svc->pivotDomainToHosts($pivotKey),
                'ip'     => $this->svc->pivotIpToHosts($pivotKey),
                'user'   => $this->svc->pivotUserToDomains($pivotKey),
                'host'   => $this->svc->pivotHostToOutboundDestinations($pivotKey),
                default  => null,
            };
        }

        return view('network.threat-hunting-pivots', compact('pivotType', 'pivotKey', 'result'));
    }
}
