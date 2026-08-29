<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ARCH-DB-SPLIT: writes agent-submitted telemetry to ClickHouse's
 * telemetry_events table (schema defined in
 * scripts/xdr_infra_clients.py's ClickHouseClient.setup_schema(), the same
 * table the Python batch/stream ingesters write to when
 * XDR_TELEMETRY_WRITE_TARGET=clickhouse) instead of Postgres's.
 *
 * Talks to ClickHouse's HTTP interface directly (no PHP ClickHouse client
 * dependency), matching the JSONEachRow insert convention
 * ClickHouseClient.insert_json_each_row() already uses on the Python side,
 * so both languages write via the identical wire format.
 */
class ClickHouseTelemetryWriter
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
        $sql = "INSERT INTO telemetry_events FORMAT JSONEachRow\n".$body;

        // date_time_input_format=best_effort: confirmed against a real
        // ClickHouse instance that its default DateTime64 text parser
        // rejects plain ISO-8601 ("2026-07-12T00:00:00Z") outright -- see
        // the identical fix + comment in scripts/xdr_infra_clients.py's
        // ClickHouseClient.query(), which both write paths' events (this
        // one included) otherwise hit the exact same way.
        $url = rtrim((string) config('xdr.infrastructure.clickhouse.http_url'), '/')
            .'/?database='.urlencode((string) config('xdr.infrastructure.clickhouse.database'))
            .'&date_time_input_format=best_effort';

        $response = ClickHouseHttpClient::request($url)
            ->withBody($sql, 'text/plain')
            ->post($url);

        if (!$response->successful()) {
            Log::warning('clickhouse telemetry insert failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Maps an agent-submitted telemetry event (already validated by
     * AgentIngestionController) into a ClickHouse telemetry_events row.
     * Mirrors exactly the column set that controller's own Postgres row
     * array populates today -- the xdr_user/domain/risk_score and other
     * extension columns are left at their ClickHouse-side defaults,
     * matching Postgres's telemetry_events, which never receives them
     * from this endpoint either.
     *
     * @param  array<string, mixed>  $event
     */
    public function mapAgentEvent(array $event, ?string $tenantId): array
    {
        return [
            'ts' => (string) ($event['ts'] ?? ''),
            'event_id' => substr((string) $event['event_id'], 0, 80),
            'tenant_id' => (string) ($tenantId ?? ''),
            'telemetry_type' => (string) $event['telemetry_type'],
            'event_type' => substr((string) $event['event_type'], 0, 80),
            'host_id' => substr((string) $event['host_id'], 0, 128),
            'src_ip' => (string) ($event['src_ip'] ?? ''),
            'dst_ip' => (string) ($event['dst_ip'] ?? ''),
            'dst_port' => (int) ($event['dst_port'] ?? 0),
            'protocol' => (string) ($event['protocol'] ?? ''),
            'process_name' => (string) ($event['process_name'] ?? ''),
            'user_name_hash' => (string) ($event['user_name_hash'] ?? ''),
            'payload' => json_encode($event),
        ];
    }
}
