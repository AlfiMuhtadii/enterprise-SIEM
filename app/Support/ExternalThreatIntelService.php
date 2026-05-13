<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExternalThreatIntelService
{
    public function lookup(string $provider, string $indicatorType, string $indicatorValue, string $actor = 'system'): array
    {
        $started = microtime(true);
        $result = ['provider' => $provider, 'indicator_type' => $indicatorType, 'indicator_value' => $indicatorValue];
        $status = 'completed';

        try {
            $result = match ($provider) {
                'virustotal' => $this->virusTotalLike($indicatorType, $indicatorValue),
                'abuseipdb' => $this->abuseIpDbLike($indicatorType, $indicatorValue),
                'webhook' => $this->webhook($indicatorType, $indicatorValue),
                default => $this->localReputation($indicatorType, $indicatorValue),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
            $result = ['error' => $e->getMessage(), 'provider' => $provider];
        }

        $lookupId = 'ti-'.Str::uuid();
        $latencyMs = (int) ((microtime(true) - $started) * 1000);
        DB::table('external_threat_intel_lookups')->insert([
            'lookup_id' => $lookupId,
            'provider' => $provider,
            'indicator_type' => $indicatorType,
            'indicator_value' => $indicatorValue,
            'status' => $status,
            'reputation' => $result['reputation'] ?? 'unknown',
            'score' => $result['score'] ?? null,
            'result' => json_encode($result),
            'latency_ms' => $latencyMs,
            'requested_by' => $actor,
            'looked_up_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return array_merge($result, ['lookup_id' => $lookupId, 'status' => $status, 'latency_ms' => $latencyMs]);
    }

    public function importFeed(string $feedType, string $body, string $source, string $actor): int
    {
        $rows = $this->parseFeed($feedType, $body);
        $count = 0;
        foreach ($rows as $row) {
            $type = $row['ioc_type'] ?? $row['type'] ?? $row['indicator_type'] ?? null;
            $value = $row['ioc_value'] ?? $row['value'] ?? $row['indicator_value'] ?? null;
            if (!in_array($type, ['ip', 'domain', 'hash', 'url'], true) || !is_string($value) || trim($value) === '') {
                continue;
            }
            DB::table('threat_iocs')->updateOrInsert(
                ['ioc_type' => $type, 'ioc_value' => trim($value)],
                [
                    'ioc_id' => 'ioc-'.Str::uuid(),
                    'source' => $source,
                    'reputation' => $row['reputation'] ?? 'suspicious',
                    'threat_label' => $row['threat_label'] ?? $row['label'] ?? $row['malware_family'] ?? null,
                    'expires_at' => $row['expires_at'] ?? null,
                    'enabled' => true,
                    'metadata' => json_encode(['external_feed_type' => $feedType]),
                    'created_by' => $actor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $count++;
        }

        DB::table('external_ioc_feeds')->updateOrInsert(
            ['feed_id' => 'feed-'.hash('sha256', $source.'|'.$feedType)],
            [
                'feed_type' => $feedType,
                'name' => $source,
                'source_url' => str_starts_with($source, 'http') ? $source : null,
                'enabled' => true,
                'last_imported_at' => now(),
                'last_import_count' => $count,
                'metadata' => json_encode(['parser' => 'external-ti-service']),
                'created_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $count;
    }

    private function virusTotalLike(string $type, string $value): array
    {
        $key = (string) config('soc.ti_virustotal_api_key', '');
        if ($key === '') {
            return $this->localReputation($type, $value, 'virustotal-not-configured');
        }
        return Http::timeout(10)->withHeader('x-apikey', $key)->get('https://www.virustotal.com/api/v3/search', ['query' => $value])->throw()->json();
    }

    private function abuseIpDbLike(string $type, string $value): array
    {
        $key = (string) config('soc.ti_abuseipdb_api_key', '');
        if ($key === '' || $type !== 'ip') {
            return $this->localReputation($type, $value, 'abuseipdb-not-configured');
        }
        return Http::timeout(10)->withHeader('Key', $key)->get('https://api.abuseipdb.com/api/v2/check', ['ipAddress' => $value, 'maxAgeInDays' => 90])->throw()->json();
    }

    private function webhook(string $type, string $value): array
    {
        $url = (string) config('soc.ti_webhook_url', '');
        if ($url === '') {
            return $this->localReputation($type, $value, 'webhook-not-configured');
        }
        return Http::timeout(10)->post($url, ['indicator_type' => $type, 'indicator_value' => $value])->throw()->json();
    }

    private function localReputation(string $type, string $value, string $reason = 'local-fallback'): array
    {
        $ioc = DB::table('threat_iocs')->where('ioc_type', $type)->where('ioc_value', $value)->first();
        return [
            'provider' => 'local-fallback',
            'reputation' => $ioc->reputation ?? 'unknown',
            'score' => ($ioc && $ioc->reputation === 'malicious') ? 0.95 : (($ioc && $ioc->reputation === 'suspicious') ? 0.65 : 0.1),
            'threat_label' => $ioc->threat_label ?? null,
            'reason' => $reason,
        ];
    }

    private function parseFeed(string $feedType, string $body): array
    {
        if (in_array($feedType, ['misp-json', 'opencti-json'], true)) {
            $json = json_decode($body, true);
            $items = $json['attributes'] ?? $json['objects'] ?? $json['data'] ?? $json;
            return is_array($items) ? array_map(fn ($item) => is_array($item) ? $item : [], $items) : [];
        }
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
