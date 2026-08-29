<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DATA-TIERING (warm tier): the real, indexed read path over ClickHouse's
 * archived_records table — the search ArchiveSearchService's own docblock
 * always described as "a separate, larger effort requiring live infra this
 * pass does not have." Same bounded-result contract as ArchiveSearchService
 * (MAX_RESULTS, `truncated` flag) so ArchiveSearchController can use either
 * interchangeably, but backed by ClickHouse's indexed
 * (source_table, tenant_id, original_ts) ORDER BY instead of a linear scan
 * of every gzip file.
 *
 * Uses ClickHouse's HTTP parameterized-query binding (`param_x` query args +
 * `{x:Type}` placeholders) for every value that isn't a fixed SQL keyword,
 * rather than string-interpolating tenant/table/filter values into SQL —
 * the same discipline this codebase already applies to raw SQL elsewhere.
 * Exact-match `filters` are applied in PHP against the decoded `payload`
 * JSON after a bounded candidate fetch (indexed columns narrow the fetch;
 * filters don't need their own ClickHouse-side JSONExtract expression,
 * which would need per-field type assumptions this generic table doesn't
 * have).
 */
class ClickHouseArchiveSearchService
{
    public const MAX_CANDIDATES = 5000;

    public const MAX_RESULTS = 500;

    /**
     * @param  array<string, string>  $filters
     */
    public function search(
        string $table,
        ?string $tenantId,
        ?Carbon $from,
        ?Carbon $to,
        array $filters = [],
        int $limit = 100,
    ): array {
        $limit = min(max($limit, 1), self::MAX_RESULTS);

        $conditions = ['source_table = {source_table:String}'];
        $params = ['source_table' => $table];

        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = {tenant_id:String}';
            $params['tenant_id'] = $tenantId;
        }
        if ($from !== null) {
            $conditions[] = 'original_ts >= {from_ts:DateTime64}';
            $params['from_ts'] = $from->format('Y-m-d H:i:s.u');
        }
        if ($to !== null) {
            $conditions[] = 'original_ts <= {to_ts:DateTime64}';
            $params['to_ts'] = $to->format('Y-m-d H:i:s.u');
        }

        $sql = 'SELECT payload FROM archived_records WHERE '
            .implode(' AND ', $conditions)
            .' ORDER BY original_ts DESC'
            .' LIMIT '.self::MAX_CANDIDATES
            .' FORMAT JSONEachRow';

        $rows = $this->query($sql, $params);
        if ($rows === null) {
            return [
                'results' => [],
                'candidates_scanned' => 0,
                'result_count' => 0,
                'truncated' => false,
                'is_local_archive_search' => false,
                'warm_tier_unavailable' => true,
            ];
        }

        $results = [];
        $candidatesScanned = 0;
        $truncated = false;
        foreach ($rows as $row) {
            if (count($results) >= $limit) {
                $truncated = true;
                break;
            }
            $candidatesScanned++;
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            if ($this->matchesFilters($payload, $filters)) {
                $results[] = $payload;
            }
        }
        if (count($rows) >= self::MAX_CANDIDATES) {
            $truncated = true;
        }

        return [
            'results' => $results,
            'candidates_scanned' => $candidatesScanned,
            'result_count' => count($results),
            'truncated' => $truncated,
            'is_local_archive_search' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>|null null on any HTTP/query failure
     *                                          (caller falls back to the gzip path).
     */
    private function query(string $sql, array $params): ?array
    {
        $url = rtrim((string) config('xdr.infrastructure.clickhouse.http_url'), '/')
            .'/?database='.urlencode((string) config('xdr.infrastructure.clickhouse.database'))
            .'&date_time_input_format=best_effort';
        foreach ($params as $name => $value) {
            $url .= '&param_'.urlencode($name).'='.urlencode((string) $value);
        }

        try {
            $response = ClickHouseHttpClient::request($url)
                ->withBody($sql, 'text/plain')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('clickhouse archive search request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('clickhouse archive search failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $lines = array_filter(explode("\n", trim($response->body())));
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        foreach ($filters as $field => $value) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            if ((string) $row[$field] !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
