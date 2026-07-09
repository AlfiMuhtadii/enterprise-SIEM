<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CAP-TI-STIX-TAXII: inbound STIX 2.1 / TAXII 2.1 threat-intel import.
 *
 * Parses STIX 2.1 `indicator` objects into IOCs and upserts them into the
 * existing `threat_iocs` store (with lifecycle: source, reputation, expiry),
 * recording each run in `external_ioc_feeds`. Offline-first: a bundled STIX file
 * is the default source; a TAXII 2.1 collection poll is optional.
 *
 * Advisory / enrichment only — feeds detection scoring, never triggers response.
 * No new tables: integrates with the platform's existing IOC subsystem.
 */
class StixTaxiiImportService
{
    public const ADVISORY_ONLY = true;
    public const NO_AUTO_RESPONSE = true;

    /**
     * STIX Cyber-observable object path → internal ioc_type. Only the observable
     * types the platform's detection path understands (ip/domain/url/hash) are kept;
     * anything else is skipped rather than stored as an unusable indicator.
     */
    private const PATTERN_MAP = [
        'ipv4-addr:value'  => 'ip',
        'ipv6-addr:value'  => 'ip',
        'domain-name:value' => 'domain',
        'url:value'        => 'url',
    ];

    /**
     * Extract (ioc_type, ioc_value) pairs from a STIX 2.1 pattern string.
     * Supports the common comparison forms, including file hashes
     * (`file:hashes.'SHA-256' = '...'`). Returns [] for unsupported patterns.
     *
     * @return array<int,array{ioc_type:string,ioc_value:string}>
     */
    public function extractFromPattern(string $pattern): array
    {
        $out = [];

        // Hash comparisons: file:hashes.'SHA-256' = 'abc...'  (or MD5, SHA-1, SHA-256, SHA-512)
        if (preg_match_all(
            "/file:hashes\.'?(MD5|SHA-?1|SHA-?256|SHA-?512)'?\s*=\s*'([^']+)'/i",
            $pattern,
            $hashMatches,
            PREG_SET_ORDER
        )) {
            foreach ($hashMatches as $m) {
                $out[] = ['ioc_type' => 'hash', 'ioc_value' => strtolower(trim($m[2]))];
            }
        }

        // Object-path comparisons: ipv4-addr:value = 'x', domain-name:value = 'x', url:value = 'x'
        foreach (self::PATTERN_MAP as $path => $iocType) {
            $quoted = preg_quote($path, '/');
            if (preg_match_all("/{$quoted}\s*=\s*'([^']+)'/i", $pattern, $m)) {
                foreach ($m[1] as $value) {
                    $value = trim($value);
                    // domains/urls are case-insensitive; IPs unaffected by lowercasing
                    $out[] = ['ioc_type' => $iocType, 'ioc_value' => $iocType === 'ip' ? $value : strtolower($value)];
                }
            }
        }

        return $out;
    }

    /**
     * Map a STIX indicator SDO to zero-or-more IOC rows (one pattern can carry
     * several observables). Returns [] when the indicator has no supported observable.
     *
     * @param array<string,mixed> $indicator
     * @return array<int,array<string,mixed>>
     */
    public function parseIndicator(array $indicator): array
    {
        if (($indicator['type'] ?? null) !== 'indicator') {
            return [];
        }
        $pattern = (string) ($indicator['pattern'] ?? '');
        if ($pattern === '') {
            return [];
        }
        $pairs = $this->extractFromPattern($pattern);
        if (empty($pairs)) {
            return [];
        }

        $reputation = $this->reputationFromConfidence($indicator['confidence'] ?? null, $indicator['labels'] ?? []);
        $label = $indicator['name'] ?? (is_array($indicator['labels'] ?? null) ? implode(',', $indicator['labels']) : null);
        $expiresAt = isset($indicator['valid_until']) ? $this->parseTimestamp((string) $indicator['valid_until']) : null;

        $rows = [];
        foreach ($pairs as $pair) {
            $rows[] = [
                'ioc_type'     => $pair['ioc_type'],
                'ioc_value'    => $pair['ioc_value'],
                'reputation'   => $reputation,
                'threat_label' => $label ? Str::limit((string) $label, 159, '') : null,
                'expires_at'   => $expiresAt,
                'stix_id'      => $indicator['id'] ?? null,
                'pattern'      => $pattern,
            ];
        }

        return $rows;
    }

    /**
     * Parse a STIX 2.1 bundle (or a bare list of objects) into IOC rows.
     *
     * @param array<string,mixed> $bundle
     * @return array<int,array<string,mixed>>
     */
    public function parseBundle(array $bundle): array
    {
        $objects = $bundle['objects'] ?? (array_is_list($bundle) ? $bundle : []);
        $rows = [];
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            foreach ($this->parseIndicator($object) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Import a STIX bundle into `threat_iocs` (idempotent upsert keyed on
     * ioc_type+ioc_value) and record the run in `external_ioc_feeds`.
     *
     * @param array<string,mixed> $bundle
     * @return array{imported:int,skipped:int,feed_id:string}
     */
    public function importBundle(array $bundle, string $source = 'stix-taxii', string $feedName = 'STIX/TAXII import'): array
    {
        $objectCount = count($bundle['objects'] ?? (array_is_list($bundle) ? $bundle : []));
        $rows = $this->parseBundle($bundle);
        $imported = 0;
        $now = now();

        foreach ($rows as $row) {
            $existing = DB::table('threat_iocs')
                ->where('ioc_type', $row['ioc_type'])
                ->where('ioc_value', $row['ioc_value'])
                ->first();

            $metadata = json_encode([
                'origin'  => 'stix-taxii',
                'stix_id' => $row['stix_id'],
                'pattern' => $row['pattern'],
                'source'  => $source,
            ]);

            if ($existing) {
                // Idempotent update — preserve ioc_id + created_at, refresh lifecycle fields.
                DB::table('threat_iocs')
                    ->where('id', $existing->id)
                    ->update([
                        'source'       => $source,
                        'reputation'   => $row['reputation'],
                        'threat_label' => $row['threat_label'],
                        'expires_at'   => $row['expires_at'],
                        'enabled'      => true,
                        'metadata'     => $metadata,
                        'updated_at'   => $now,
                    ]);
            } else {
                DB::table('threat_iocs')->insert([
                    'ioc_id'       => 'ioc-'.Str::uuid(),
                    'ioc_type'     => $row['ioc_type'],
                    'ioc_value'    => $row['ioc_value'],
                    'source'       => $source,
                    'reputation'   => $row['reputation'],
                    'threat_label' => $row['threat_label'],
                    'expires_at'   => $row['expires_at'],
                    'enabled'      => true,
                    'metadata'     => $metadata,
                    'created_by'   => 'stix-taxii',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
            $imported++;
        }

        $feedId = 'feed-'.substr(hash('sha256', $source.'|'.$feedName), 0, 24);
        DB::table('external_ioc_feeds')->updateOrInsert(
            ['feed_id' => $feedId],
            [
                'feed_type'         => 'stix-taxii',
                'name'              => $feedName,
                'enabled'           => true,
                'last_imported_at'  => $now,
                'last_import_count' => $imported,
                'metadata'          => json_encode(['source' => $source, 'stix_objects' => $objectCount]),
                'created_by'        => 'stix-taxii',
                'updated_at'        => $now,
            ]
        );

        return [
            'imported' => $imported,
            'skipped'  => max(0, $objectCount - $imported),
            'feed_id'  => $feedId,
        ];
    }

    /**
     * Poll a TAXII 2.1 collection for objects. Offline-safe: never throws — returns
     * a bundle-shaped array (`{objects: [...]}`), or an empty one on any failure.
     *
     * @return array<string,mixed>
     */
    public function pollTaxii(string $collectionUrl, ?string $user = null, ?string $pass = null): array
    {
        try {
            $request = Http::acceptJson()
                ->withHeaders(['Accept' => 'application/taxii+json;version=2.1'])
                ->timeout(10);
            if ($user !== null && $user !== '') {
                $request = $request->withBasicAuth($user, (string) $pass);
            }
            $response = $request->get(rtrim($collectionUrl, '/').'/objects/');
            if (!$response->successful()) {
                return ['objects' => []];
            }
            $objects = $response->json('objects') ?? [];

            return ['objects' => is_array($objects) ? $objects : []];
        } catch (\Throwable $e) {
            return ['objects' => []];
        }
    }

    private function reputationFromConfidence(mixed $confidence, mixed $labels): string
    {
        $labels = is_array($labels) ? array_map('strtolower', $labels) : [];
        if (in_array('benign', $labels, true)) {
            return 'benign';
        }
        if (is_numeric($confidence)) {
            $c = (int) $confidence;
            if ($c >= 75) {
                return 'malicious';
            }
            if ($c >= 40) {
                return 'suspicious';
            }
            return 'unknown';
        }
        // No confidence: a malicious-activity label still implies malicious.
        if (in_array('malicious-activity', $labels, true)) {
            return 'malicious';
        }

        return 'unknown';
    }

    private function parseTimestamp(string $value): ?string
    {
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
