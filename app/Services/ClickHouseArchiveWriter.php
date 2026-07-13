<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DATA-TIERING (warm tier): writes retention-archived rows into ClickHouse's
 * archived_records table (schema defined in
 * scripts/xdr_infra_clients.py's ClickHouseClient.setup_schema()) so
 * archived data becomes a real, indexed, months-scale searchable tier —
 * closing the gap the phase 1/2 local gzip archive
 * (SecurityRetentionArchiveService/ArchiveSearchService) always documented
 * as separate, larger, live-infra-dependent scope.
 *
 * Talks to ClickHouse's HTTP interface directly (no PHP ClickHouse client
 * dependency), matching ClickHouseTelemetryWriter's existing convention —
 * same wire format (JSONEachRow), same config, same
 * date_time_input_format=best_effort fix for ClickHouse's DateTime64
 * parser rejecting plain ISO-8601 strings.
 */
class ClickHouseArchiveWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insert(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $body = implode("\n", array_map(
            fn (array $row) => json_encode($row, JSON_UNESCAPED_SLASHES),
            $rows,
        ));
        $sql = "INSERT INTO archived_records FORMAT JSONEachRow\n".$body;

        $url = rtrim((string) config('xdr.infrastructure.clickhouse.http_url'), '/')
            .'/?database='.urlencode((string) config('xdr.infrastructure.clickhouse.database'))
            .'&date_time_input_format=best_effort';

        try {
            $response = Http::timeout((int) config('xdr.infrastructure.clickhouse.timeout_seconds', 5))
                ->withBasicAuth(
                    (string) config('xdr.infrastructure.clickhouse.user'),
                    (string) config('xdr.infrastructure.clickhouse.password'),
                )
                ->withBody($sql, 'text/plain')
                ->post($url);
        } catch (\Throwable $e) {
            // A genuinely unreachable ClickHouse (DNS failure, connection
            // refused, timeout) throws before a Response object even
            // exists -- must be caught here too, not just a non-2xx status
            // below, or SecurityRetentionArchiveService's "warm-tier
            // failure must never block deletion" guarantee would be false
            // the moment ClickHouse is actually down rather than merely
            // erroring.
            Log::warning('clickhouse archive insert request failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (!$response->successful()) {
            Log::warning('clickhouse archive insert failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Maps one row about to be archived+deleted into an archived_records
     * row. $originalTs is the value of whatever retention column the caller
     * filtered on (ts/detected_at/created_at — different per source table),
     * kept as its own indexed column since that's the dimension both the
     * gzip archive's filename and every retention query already key on.
     *
     * @param  array<string, mixed>  $row
     */
    public function mapArchivedRow(string $sourceTable, array $row, ?string $tenantId, string $originalTs): array
    {
        return [
            'source_table' => $sourceTable,
            'tenant_id' => (string) ($tenantId ?? ''),
            'record_id' => (string) ($row['id'] ?? ''),
            'original_ts' => $originalTs,
            'payload' => json_encode($row, JSON_UNESCAPED_SLASHES),
        ];
    }
}
