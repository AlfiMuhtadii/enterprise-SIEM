<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ATTR-003: Offline GeoIP/ASN lookup using bundled fixture data.
 *
 * No external API calls. Fixture data covers RFC 1918/5737/6598 private ranges,
 * loopback, documentation, and demo ASN entries.
 *
 * Used only by AlertAttributionService (advisory enrichment path).
 */
class GeoAsnLookupService
{
    public const ADVISORY_ONLY     = true;
    public const OFFLINE_ONLY      = true;
    public const NO_EXTERNAL_API   = true;

    private const FIXTURE_PATH = 'data/geo_asn_fixtures.json';
    private const CACHE_KEY    = 'geo_asn_fixture_v1';
    private const CACHE_TTL    = 3600;

    private ?array $fixture = null;

    /**
     * Lookup geo/ASN metadata for an IP address.
     *
     * @return array{country_code: string, country_name: string, asn: string, asn_org: string, ip_type: string, reputation_hint: string|null}
     */
    public function lookup(string $ip): array
    {
        $fixture  = $this->loadFixture();
        $result   = $this->matchRange($ip, $fixture['ranges'] ?? []);
        $reputationHint = $fixture['demo_reputation_hints'][$ip] ?? null;

        return [
            'country_code'    => $result['country_code'],
            'country_name'    => $result['country_name'],
            'asn'             => $result['asn'],
            'asn_org'         => $result['asn_org'],
            'ip_type'         => $result['ip_type'],
            'reputation_hint' => $reputationHint,
        ];
    }

    /**
     * Returns true if the IP is a private/internal address.
     */
    public function isPrivate(string $ip): bool
    {
        $result = $this->lookup($ip);
        return in_array($result['ip_type'], ['private', 'loopback', 'link_local'], true);
    }

    /**
     * All demo ASN entries from the fixture.
     */
    public function demoAsns(): array
    {
        return $this->loadFixture()['demo_asns'] ?? [];
    }

    private function matchRange(string $ip, array $ranges): array
    {
        foreach ($ranges as $entry) {
            if ($this->ipInCidr($ip, $entry['cidr'])) {
                return $entry;
            }
        }
        return [
            'country_code' => 'UNKNOWN',
            'country_name' => 'Unknown',
            'asn'          => 'AS0',
            'asn_org'      => 'Unknown',
            'ip_type'      => 'public',
        ];
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (str_contains($ip, ':') || str_contains($cidr, ':')) {
            return $this->ipv6InCidr($ip, $cidr);
        }
        [$range, $prefix] = explode('/', $cidr);
        $prefix = (int) $prefix;
        $ipLong    = ip2long($ip);
        $rangeLong = ip2long($range);
        if ($ipLong === false || $rangeLong === false) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ($ipLong & $mask) === ($rangeLong & $mask);
    }

    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$range, $prefix] = explode('/', $cidr);
        $prefix = (int) $prefix;
        $ipBin    = @inet_pton($ip);
        $rangeBin = @inet_pton($range);
        if ($ipBin === false || $rangeBin === false) {
            return false;
        }
        $ipHex    = bin2hex($ipBin);
        $rangeHex = bin2hex($rangeBin);
        $hexLen   = (int) ceil($prefix / 4);
        return substr($ipHex, 0, $hexLen) === substr($rangeHex, 0, $hexLen);
    }

    private function loadFixture(): array
    {
        if ($this->fixture !== null) {
            return $this->fixture;
        }

        $path = resource_path(self::FIXTURE_PATH);
        if (!file_exists($path)) {
            $this->fixture = ['ranges' => [], 'demo_asns' => [], 'demo_reputation_hints' => []];
            return $this->fixture;
        }

        $this->fixture = json_decode(file_get_contents($path), true) ?? [];
        return $this->fixture;
    }
}
