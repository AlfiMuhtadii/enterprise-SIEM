<?php

namespace App\Services;

use App\Models\DnsEvent;
use App\Models\FirewallEvent;
use App\Models\NetworkBehavioralFinding;
use App\Models\NetworkDestinationProfile;
use App\Models\ProxyEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Network Analytics Service — DNS/Proxy/Firewall Phase 1.
 *
 * Network analytics are shadow-only and advisory.
 * No blocking or containment is executed.
 * No firewall rule push. No IP/domain blocking.
 * No packet sniffing. No DPI inspection.
 * All findings are append-only and advisory-only.
 */
class NetworkAnalyticsService
{
    public const ANALYTICS_WINDOW_SECONDS = 300; // 5-minute rapid detection window

    // -----------------------------------------------------------------------
    // Telemetry ingestion — idempotent, append-only
    // -----------------------------------------------------------------------

    public function ingestDnsEvent(array $raw): DnsEvent
    {
        $eventId = $raw['event_id'] ?? DnsEvent::generateEventId();
        if (DnsEvent::where('event_id', $eventId)->exists()) {
            return DnsEvent::where('event_id', $eventId)->first();
        }

        $domain  = $raw['queried_domain'] ?? '';
        $tld     = $this->extractTld($domain);
        $entropy = $domain ? DnsEvent::calculateEntropy($domain) : null;

        $event = DnsEvent::create([
            'event_id'       => $eventId,
            'agent_id'       => $raw['agent_id'] ?? null,
            'host_id'        => $raw['host_id'] ?? null,
            'source_ip'      => $raw['source_ip'] ?? null,
            'user'           => $raw['user'] ?? null,
            'queried_domain' => $domain ?: null,
            'tld'            => $tld,
            'query_type'     => strtoupper($raw['query_type'] ?? ''),
            'response_code'  => strtoupper($raw['response_code'] ?? ''),
            'is_nxdomain'    => strtoupper($raw['response_code'] ?? '') === 'NXDOMAIN',
            'resolved_ips'   => $raw['resolved_ips'] ?? null,
            'domain_entropy' => $entropy,
            'occurred_at'    => $raw['occurred_at'] ?? now(),
            'trace_id'       => $raw['trace_id'] ?? null,
        ]);

        if ($domain) {
            NetworkDestinationProfile::upsertFromDnsEvent($event);
        }

        return $event;
    }

    public function ingestProxyEvent(array $raw): ProxyEvent
    {
        $eventId = $raw['event_id'] ?? ProxyEvent::generateEventId();
        if (ProxyEvent::where('event_id', $eventId)->exists()) {
            return ProxyEvent::where('event_id', $eventId)->first();
        }

        $statusCode = (int) ($raw['status_code'] ?? 0);
        return ProxyEvent::create([
            'event_id'    => $eventId,
            'agent_id'    => $raw['agent_id'] ?? null,
            'host_id'     => $raw['host_id'] ?? null,
            'source_ip'   => $raw['source_ip'] ?? null,
            'user'        => $raw['user'] ?? null,
            'url'         => $raw['url'] ?? null,
            'domain'      => $raw['domain'] ?? $this->extractDomain($raw['url'] ?? ''),
            'http_method' => strtoupper($raw['http_method'] ?? ''),
            'status_code' => $statusCode ?: null,
            'user_agent'  => isset($raw['user_agent']) ? substr($raw['user_agent'], 0, 1024) : null,
            'bytes_out'   => $raw['bytes_out'] ?? null,
            'bytes_in'    => $raw['bytes_in'] ?? null,
            'is_denied'   => in_array($statusCode, [401, 403, 407], true),
            'occurred_at' => $raw['occurred_at'] ?? now(),
            'trace_id'    => $raw['trace_id'] ?? null,
        ]);
    }

    public function ingestFirewallEvent(array $raw): FirewallEvent
    {
        $eventId = $raw['event_id'] ?? FirewallEvent::generateEventId();
        if (FirewallEvent::where('event_id', $eventId)->exists()) {
            return FirewallEvent::where('event_id', $eventId)->first();
        }

        $action = strtolower($raw['action'] ?? '');
        return FirewallEvent::create([
            'event_id'         => $eventId,
            'agent_id'         => $raw['agent_id'] ?? null,
            'host_id'          => $raw['host_id'] ?? null,
            'source_ip'        => $raw['source_ip'] ?? null,
            'destination_ip'   => $raw['destination_ip'] ?? null,
            'destination_port' => $raw['destination_port'] ?? null,
            'protocol'         => strtolower($raw['protocol'] ?? ''),
            'action'           => $action,
            'is_deny'          => in_array($action, ['deny', 'drop', 'reject'], true),
            'bytes_out'        => $raw['bytes_out'] ?? null,
            'bytes_in'         => $raw['bytes_in'] ?? null,
            'rule_name'        => isset($raw['rule_name']) ? substr($raw['rule_name'], 0, 256) : null,
            'user'             => $raw['user'] ?? null,
            'host_id_src'      => $raw['host_id_src'] ?? null,
            'occurred_at'      => $raw['occurred_at'] ?? now(),
            'trace_id'         => $raw['trace_id'] ?? null,
        ]);
    }

    // -----------------------------------------------------------------------
    // DNS analytics — advisory-only, deterministic
    // -----------------------------------------------------------------------

    public function detectSuspiciousDnsTld(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since   = now()->subSeconds($windowSeconds);
        $events  = DnsEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('tld')
            ->get();

        $findings = [];
        foreach ($events as $ev) {
            if (in_array(strtolower($ev->tld ?? ''), DnsEvent::TLDS_SUSPICIOUS, true)) {
                $findings[] = $this->recordFinding(
                    NetworkBehavioralFinding::SOURCE_DNS,
                    'suspicious_dns_tld',
                    $hostId,
                    null, $ev->user, $ev->queried_domain, null, null, 'medium', 0.72,
                    ['tld' => $ev->tld, 'domain' => $ev->queried_domain, 'trace_id' => $ev->trace_id]
                );
            }
        }
        return $findings;
    }

    public function detectDnsTxtQueryPattern(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $txtQueries = DnsEvent::where('host_id', $hostId)
            ->where('query_type', 'TXT')
            ->where('occurred_at', '>=', $since)
            ->get();

        if ($txtQueries->count() < 2) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_DNS, 'dns_txt_query_pattern',
            $hostId, null, null, null, null, null, 'medium', 0.68,
            ['txt_count' => $txtQueries->count(), 'domains' => $txtQueries->pluck('queried_domain')->unique()->take(5)->toArray()]
        )];
    }

    public function detectHighEntropyDomain(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $events = DnsEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->where('domain_entropy', '>', DnsEvent::ENTROPY_THRESHOLD_HIGH)
            ->get();

        return $events->map(fn ($ev) => $this->recordFinding(
            NetworkBehavioralFinding::SOURCE_DNS, 'high_entropy_domain',
            $hostId, null, $ev->user, $ev->queried_domain, null, null, 'medium', 0.65,
            ['entropy' => $ev->domain_entropy, 'domain' => $ev->queried_domain]
        ))->values()->toArray();
    }

    public function detectRepeatedNxdomain(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $count = DnsEvent::where('host_id', $hostId)
            ->where('is_nxdomain', true)
            ->where('occurred_at', '>=', $since)
            ->count();

        if ($count < DnsEvent::NXDOMAIN_BURST_THRESHOLD) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_DNS, 'repeated_nxdomain',
            $hostId, null, null, null, null, null, 'medium', 0.74,
            ['nxdomain_count' => $count, 'window_seconds' => $windowSeconds]
        )];
    }

    public function detectRareDomainContact(string $hostId): array
    {
        $domains = DnsEvent::where('host_id', $hostId)
            ->whereNotNull('queried_domain')
            ->pluck('queried_domain')
            ->unique();

        $findings = [];
        foreach ($domains as $domain) {
            $profile = NetworkDestinationProfile::where('destination_key', $domain)->first();
            if ($profile && $profile->is_rare) {
                $findings[] = $this->recordFinding(
                    NetworkBehavioralFinding::SOURCE_DNS, 'rare_domain_contact',
                    $hostId, null, null, $domain, null, null, 'low', 0.60,
                    ['contact_count' => $profile->contact_count, 'host_count' => $profile->host_count]
                );
            }
        }
        return $findings;
    }

    // -----------------------------------------------------------------------
    // Proxy analytics — advisory-only, deterministic
    // -----------------------------------------------------------------------

    public function detectSuspiciousUserAgent(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since  = now()->subSeconds($windowSeconds);
        $events = ProxyEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('user_agent')
            ->get();

        $findings = [];
        foreach ($events as $ev) {
            $ua = strtolower($ev->user_agent ?? '');
            foreach (ProxyEvent::SUSPICIOUS_UA_PATTERNS as $pattern) {
                if (str_contains($ua, strtolower($pattern))) {
                    $findings[] = $this->recordFinding(
                        NetworkBehavioralFinding::SOURCE_PROXY, 'suspicious_user_agent',
                        $hostId, null, $ev->user, $ev->domain, null, null, 'high', 0.80,
                        ['user_agent' => $ev->user_agent, 'matched_pattern' => $pattern]
                    );
                    break;
                }
            }
        }
        return $findings;
    }

    public function detectUnusualHttpMethod(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since  = now()->subSeconds($windowSeconds);
        $events = ProxyEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->whereIn('http_method', ProxyEvent::UNUSUAL_HTTP_METHODS)
            ->get();

        if ($events->isEmpty()) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_PROXY, 'unusual_http_method',
            $hostId, null, null, null, null, null, 'medium', 0.65,
            ['methods' => $events->pluck('http_method')->unique()->toArray()]
        )];
    }

    public function detectRepeated403401(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $count = ProxyEvent::where('host_id', $hostId)
            ->where('is_denied', true)
            ->where('occurred_at', '>=', $since)
            ->count();

        if ($count < ProxyEvent::DENY_THRESHOLD) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_PROXY, 'repeated_403_401',
            $hostId, null, null, null, null, null, 'medium', 0.75,
            ['deny_count' => $count, 'window_seconds' => $windowSeconds]
        )];
    }

    public function detectHighBytesOut(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since    = now()->subSeconds($windowSeconds);
        $totalOut = (int) ProxyEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->sum('bytes_out');

        if ($totalOut < ProxyEvent::HIGH_BYTES_OUT_THRESHOLD) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_PROXY, 'high_bytes_out',
            $hostId, null, null, null, null, null, 'high', 0.78,
            ['bytes_out_total' => $totalOut, 'threshold' => ProxyEvent::HIGH_BYTES_OUT_THRESHOLD]
        )];
    }

    // -----------------------------------------------------------------------
    // Firewall analytics — advisory-only, deterministic
    // -----------------------------------------------------------------------

    public function detectRepeatedDeniedOutbound(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $count = FirewallEvent::where('host_id', $hostId)
            ->where('is_deny', true)
            ->where('occurred_at', '>=', $since)
            ->count();

        if ($count < FirewallEvent::DENY_THRESHOLD) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_FIREWALL, 'repeated_denied_outbound',
            $hostId, null, null, null, null, null, 'high', 0.80,
            ['deny_count' => $count, 'window_seconds' => $windowSeconds]
        )];
    }

    public function detectUnusualDestinationPort(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since  = now()->subSeconds($windowSeconds);
        $events = FirewallEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->whereIn('destination_port', FirewallEvent::UNUSUAL_PORTS)
            ->get();

        if ($events->isEmpty()) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_FIREWALL, 'unusual_destination_port',
            $hostId, null, null, null, null,
            $events->first()->destination_port, 'high', 0.82,
            ['ports' => $events->pluck('destination_port')->unique()->toArray()]
        )];
    }

    public function detectRareExternalDestination(string $hostId): array
    {
        $ips = FirewallEvent::where('host_id', $hostId)
            ->whereNotNull('destination_ip')
            ->pluck('destination_ip')
            ->unique();

        $findings = [];
        foreach ($ips as $ip) {
            $profile = NetworkDestinationProfile::where('destination_key', $ip)->first();
            if ($profile && $profile->is_rare) {
                $findings[] = $this->recordFinding(
                    NetworkBehavioralFinding::SOURCE_FIREWALL, 'rare_external_destination',
                    $hostId, null, null, null, $ip, null, 'medium', 0.65,
                    ['ip' => $ip, 'contact_count' => $profile->contact_count]
                );
            }
        }
        return $findings;
    }

    public function detectHighVolumeEgress(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since    = now()->subSeconds($windowSeconds);
        $totalOut = (int) FirewallEvent::where('host_id', $hostId)
            ->where('occurred_at', '>=', $since)
            ->sum('bytes_out');

        if ($totalOut < FirewallEvent::HIGH_EGRESS_BYTES) {
            return [];
        }
        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_FIREWALL, 'high_volume_egress',
            $hostId, null, null, null, null, null, 'high', 0.77,
            ['bytes_out_total' => $totalOut, 'threshold' => FirewallEvent::HIGH_EGRESS_BYTES]
        )];
    }

    public function detectSuspiciousAllowAfterDeny(string $hostId, int $windowSeconds = self::ANALYTICS_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);

        $deniedDests = FirewallEvent::where('host_id', $hostId)
            ->where('is_deny', true)
            ->where('occurred_at', '>=', $since)
            ->pluck('destination_ip')
            ->filter()
            ->unique()
            ->toArray();

        if (empty($deniedDests)) {
            return [];
        }

        $allowedAfterDeny = FirewallEvent::where('host_id', $hostId)
            ->where('action', FirewallEvent::ACTION_ALLOW)
            ->whereIn('destination_ip', $deniedDests)
            ->where('occurred_at', '>=', $since)
            ->pluck('destination_ip')
            ->unique()
            ->toArray();

        if (empty($allowedAfterDeny)) {
            return [];
        }

        return [$this->recordFinding(
            NetworkBehavioralFinding::SOURCE_FIREWALL, 'suspicious_allow_after_deny',
            $hostId, null, null, null, $allowedAfterDeny[0], null, 'high', 0.85,
            ['destinations' => $allowedAfterDeny, 'advisory_note' => 'Destination previously denied then allowed in same window.']
        )];
    }

    // -----------------------------------------------------------------------
    // Query methods
    // -----------------------------------------------------------------------

    public function getRecentDnsEvents(int $limit = 100, ?string $hostId = null): Collection
    {
        $q = DnsEvent::orderByDesc('id')->limit($limit);
        if ($hostId) $q->where('host_id', $hostId);
        return $q->get();
    }

    public function getRecentProxyEvents(int $limit = 100, ?string $hostId = null): Collection
    {
        $q = ProxyEvent::orderByDesc('id')->limit($limit);
        if ($hostId) $q->where('host_id', $hostId);
        return $q->get();
    }

    public function getRecentFirewallEvents(int $limit = 100, ?string $hostId = null): Collection
    {
        $q = FirewallEvent::orderByDesc('id')->limit($limit);
        if ($hostId) $q->where('host_id', $hostId);
        return $q->get();
    }

    public function getRecentFindings(int $limit = 100, ?string $sourceType = null): Collection
    {
        $q = NetworkBehavioralFinding::orderByDesc('id')->limit($limit);
        if ($sourceType) $q->where('source_domain', $sourceType);
        return $q->get();
    }

    public function getNetworkDashboardSummary(): array
    {
        return [
            'dns_events_24h'      => DnsEvent::where('created_at', '>=', now()->subDay())->count(),
            'proxy_events_24h'    => ProxyEvent::where('created_at', '>=', now()->subDay())->count(),
            'firewall_events_24h' => FirewallEvent::where('created_at', '>=', now()->subDay())->count(),
            'findings_24h'        => NetworkBehavioralFinding::where('created_at', '>=', now()->subDay())->count(),
            'nxdomain_24h'        => DnsEvent::where('created_at', '>=', now()->subDay())->where('is_nxdomain', true)->count(),
            'denied_flows_24h'    => FirewallEvent::where('created_at', '>=', now()->subDay())->where('is_deny', true)->count(),
            'denied_proxy_24h'    => ProxyEvent::where('created_at', '>=', now()->subDay())->where('is_denied', true)->count(),
            'advisory_note'       => 'Network analytics are shadow-only and advisory. No blocking or containment is executed.',
        ];
    }

    // -----------------------------------------------------------------------
    // Threat hunting pivot helpers
    // -----------------------------------------------------------------------

    public function pivotDomainToHosts(string $domain): array
    {
        $hosts = DnsEvent::where('queried_domain', $domain)->pluck('host_id')->unique()->values()->toArray();
        $proxied = ProxyEvent::where('domain', $domain)->pluck('host_id')->unique()->values()->toArray();
        return [
            'domain'           => $domain,
            'hosts_via_dns'    => $hosts,
            'hosts_via_proxy'  => $proxied,
            'advisory_only'    => true,
            'no_blocking'      => true,
        ];
    }

    public function pivotIpToHosts(string $ip): array
    {
        $via_firewall = FirewallEvent::where('destination_ip', $ip)->pluck('host_id')->unique()->values()->toArray();
        $via_dns      = DnsEvent::whereJsonContains('resolved_ips', $ip)->pluck('host_id')->unique()->values()->toArray();
        return [
            'ip'                   => $ip,
            'hosts_via_firewall'   => $via_firewall,
            'hosts_via_dns_resolve'=> $via_dns,
            'advisory_only'        => true,
        ];
    }

    public function pivotUserToDomains(string $user): array
    {
        $dns_domains   = DnsEvent::where('user', $user)->pluck('queried_domain')->unique()->values()->toArray();
        $proxy_domains = ProxyEvent::where('user', $user)->pluck('domain')->unique()->values()->toArray();
        return [
            'user'          => $user,
            'dns_domains'   => $dns_domains,
            'proxy_domains' => $proxy_domains,
            'advisory_only' => true,
        ];
    }

    public function pivotHostToOutboundDestinations(string $hostId): array
    {
        $fw_dests   = FirewallEvent::where('host_id', $hostId)->pluck('destination_ip')->unique()->values()->toArray();
        $prx_dests  = ProxyEvent::where('host_id', $hostId)->pluck('domain')->unique()->values()->toArray();
        $dns_dests  = DnsEvent::where('host_id', $hostId)->pluck('queried_domain')->unique()->values()->toArray();
        return [
            'host_id'              => $hostId,
            'firewall_destinations'=> $fw_dests,
            'proxy_domains'        => $prx_dests,
            'dns_queries'          => $dns_dests,
            'advisory_only'        => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function recordFinding(
        string  $source,
        string  $findingType,
        string  $hostId,
        ?string $agentId,
        ?string $user,
        ?string $targetDomain,
        ?string $targetIp,
        ?int    $targetPort,
        string  $severity,
        float   $confidence,
        array   $evidence
    ): NetworkBehavioralFinding {
        return NetworkBehavioralFinding::create([
            'finding_id'    => NetworkBehavioralFinding::generateFindingId(),
            'finding_type'  => $findingType,
            'source_domain' => $source,
            'host_id'       => $hostId,
            'agent_id'      => $agentId,
            'user'          => $user,
            'target_domain' => $targetDomain,
            'target_ip'     => $targetIp,
            'target_port'   => $targetPort,
            'severity'      => $severity,
            'confidence'    => $confidence,
            'advisory_only' => true,
            'evidence'      => array_merge($evidence, ['no_blocking' => true, 'advisory_only' => true]),
            'trace_id'      => 'netfnd-' . Str::uuid(),
        ]);
    }

    private function extractTld(string $domain): ?string
    {
        $parts = explode('.', strtolower(trim($domain, '.')));
        return count($parts) >= 2 ? '.' . end($parts) : null;
    }

    private function extractDomain(string $url): ?string
    {
        if (!$url) return null;
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
}
