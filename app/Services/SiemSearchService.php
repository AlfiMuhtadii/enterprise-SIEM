<?php

namespace App\Services;

use App\Support\TraceRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SIEM-SEARCH: read-only free-form search over the OpenSearch alert index
 * (`xdr-alerts`), with a bounded Postgres ILIKE fallback when OpenSearch is
 * unreachable — mirrors the platform's existing OpenSearch graceful-fallback
 * posture. Read-only: no mutation, no autonomous action of any kind.
 */
class SiemSearchService
{
    public const MAX_RESULTS = 500;
    public const DEFAULT_MAX_RESULTS = 100;
    public const MAX_QUERY_WINDOW_DAYS = 30;
    public const MIN_QUERY_LENGTH = 2;
    public const OPENSEARCH_INDEX = 'xdr-alerts';

    public function search(string $tenantId, string $query, ?int $maxResults = null, ?int $windowDays = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            throw new InvalidArgumentException('Query must be at least '.self::MIN_QUERY_LENGTH.' characters.');
        }

        $maxResults = max(1, min($maxResults ?? self::DEFAULT_MAX_RESULTS, self::MAX_RESULTS));
        $windowDays = max(1, min($windowDays ?? self::MAX_QUERY_WINDOW_DAYS, self::MAX_QUERY_WINDOW_DAYS));

        $opensearch = $this->searchOpenSearch($tenantId, $query, $maxResults, $windowDays);
        if ($opensearch !== null) {
            return $opensearch;
        }

        return $this->searchPostgres($tenantId, $query, $maxResults, $windowDays);
    }

    private function searchOpenSearch(string $tenantId, string $query, int $maxResults, int $windowDays): ?array
    {
        $url = rtrim((string) config('xdr.infrastructure.opensearch.url'), '/');
        if ($url === '') {
            return null;
        }

        $body = [
            'size' => $maxResults,
            'query' => [
                'bool' => [
                    'must' => [
                        ['query_string' => ['query' => $query, 'default_operator' => 'AND']],
                    ],
                    'filter' => [
                        ['term' => ['tenant_id' => $tenantId]],
                        ['range' => ['detected_at' => ['gte' => now()->subDays($windowDays)->toIso8601String()]]],
                    ],
                ],
            ],
            'sort' => [['detected_at' => ['order' => 'desc']]],
        ];

        try {
            $timeout = (int) config('xdr.infrastructure.opensearch.timeout_seconds', 5);
            $http = Http::timeout($timeout);
            $user = (string) config('xdr.infrastructure.opensearch.user');
            if ($user !== '') {
                $http = $http->withBasicAuth($user, (string) config('xdr.infrastructure.opensearch.password'));
            }
            $response = $http->post("{$url}/".self::OPENSEARCH_INDEX.'/_search', $body);
            if (!$response->successful()) {
                return null;
            }
            $hits = $response->json('hits.hits') ?? [];
        } catch (\Throwable $e) {
            return null;
        }

        $results = collect($hits)->map(
            fn (array $hit) => TraceRedactor::row($this->toRedactableRow($hit['_source'] ?? []))
        );

        return [
            'source' => 'opensearch',
            'query' => $query,
            'window_days' => $windowDays,
            'total' => count($hits),
            'results' => $results,
        ];
    }

    private function searchPostgres(string $tenantId, string $query, int $maxResults, int $windowDays): array
    {
        $like = '%'.addcslashes($query, '%_\\').'%';

        $rows = DB::table('security_alerts')
            ->where('tenant_id', $tenantId)
            ->where('detected_at', '>=', now()->subDays($windowDays))
            ->where(function ($q) use ($like) {
                $q->where('alert_type', 'ILIKE', $like)
                    ->orWhere('ip', 'ILIKE', $like)
                    ->orWhere('detector_name', 'ILIKE', $like)
                    ->orWhereRaw('evidence::text ILIKE ?', [$like])
                    ->orWhereRaw('raw_event::text ILIKE ?', [$like]);
            })
            ->orderByDesc('detected_at')
            ->limit($maxResults)
            ->get();

        return [
            'source' => 'postgres_fallback',
            'query' => $query,
            'window_days' => $windowDays,
            'total' => $rows->count(),
            'results' => TraceRedactor::collection($rows),
        ];
    }

    /**
     * TraceRedactor::row() only deep-redacts JSON_PAYLOAD_FIELDS when they
     * arrive as JSON strings (the Postgres column shape). OpenSearch hits
     * decode those fields to arrays already, which would otherwise bypass
     * redaction entirely — re-encode them so the same redaction path applies.
     */
    private function toRedactableRow(array $source): \stdClass
    {
        foreach (TraceRedactor::JSON_PAYLOAD_FIELDS as $field) {
            if (isset($source[$field]) && !is_string($source[$field])) {
                $source[$field] = json_encode($source[$field]);
            }
        }

        return (object) $source;
    }
}
