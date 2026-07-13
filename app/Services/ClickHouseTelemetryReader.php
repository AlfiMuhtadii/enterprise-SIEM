<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ARCH-DB-SPLIT (read path): the two lowest-risk telemetry_events read
 * paths migrated to ClickHouse, deliberately scoped out of the 7+ total
 * read sites documented in REVIEW_BACKLOG.md — the 2 correlation detectors
 * that feed real security_alerts remain Postgres-only in this pass, since
 * a silent output change there is a correctness incident, not a dashboard
 * showing slightly stale numbers.
 *
 * Both methods return a Collection of stdClass rows shaped identically to
 * what the equivalent `DB::table('telemetry_events')->get()` call already
 * returns, so the calling controllers' Blade views need zero changes —
 * only the controller's own branch on
 * xdr.infrastructure.clickhouse.telemetry_write_target decides which
 * source actually ran.
 *
 * Same wire conventions as ClickHouseArchiveSearchService: parameterized
 * HTTP query binding (`param_x` + `{x:Type}`), never string-interpolated
 * user input, and a null return on any failure so callers can fall back to
 * Postgres rather than show a broken page.
 */
class ClickHouseTelemetryReader
{
    /**
     * Mirrors SocEndpointTimelineController's single-host point lookup:
     * telemetry_events WHERE host_id = ? AND ts >= ? [AND event_type = ?]
     * ORDER BY ts DESC LIMIT ?. This is exactly the access pattern
     * telemetry_events' ClickHouse ORDER BY (tenant_id, host_id, ts,
     * event_id) was designed around (see xdr_infra_clients.py's
     * setup_schema() comment).
     */
    public function hostTimeline(string $hostId, Carbon $since, string $eventType, int $limit): ?Collection
    {
        $conditions = ['host_id = {host_id:String}', 'ts >= {since:DateTime64}'];
        $params = [
            'host_id' => $hostId,
            'since' => $since->format('Y-m-d H:i:s.u'),
        ];
        if ($eventType !== '') {
            $conditions[] = 'event_type = {event_type:String}';
            $params['event_type'] = $eventType;
        }

        $sql = 'SELECT ts, event_type, host_id, process_name, src_ip, dst_ip, dst_port'
            .' FROM telemetry_events WHERE '.implode(' AND ', $conditions)
            .' ORDER BY ts DESC LIMIT '.max(1, $limit)
            .' FORMAT JSONEachRow';

        return $this->query($sql, $params);
    }

    /**
     * Mirrors SocDashboardController's $xdrDomainBreakdown — the exact
     * query the ARCH-DB-SPLIT write-path entry's live OLTP-contention
     * benchmark used (see REVIEW_COMPLETED.md): telemetry_events GROUP BY
     * telemetry_type WHERE ts >= ? AND telemetry_type IN (...).
     */
    public function domainBreakdown(Carbon $since, array $telemetryTypes): ?Collection
    {
        $placeholders = [];
        $params = ['since' => $since->format('Y-m-d H:i:s.u')];
        foreach (array_values($telemetryTypes) as $i => $type) {
            $key = "type_{$i}";
            $placeholders[] = "{{$key}:String}";
            $params[$key] = $type;
        }

        $sql = 'SELECT telemetry_type, count(*) as total FROM telemetry_events'
            .' WHERE ts >= {since:DateTime64} AND telemetry_type IN ('.implode(',', $placeholders).')'
            .' GROUP BY telemetry_type ORDER BY total DESC'
            .' FORMAT JSONEachRow';

        return $this->query($sql, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function query(string $sql, array $params): ?Collection
    {
        $url = rtrim((string) config('xdr.infrastructure.clickhouse.http_url'), '/')
            .'/?database='.urlencode((string) config('xdr.infrastructure.clickhouse.database'))
            .'&date_time_input_format=best_effort';
        foreach ($params as $name => $value) {
            $url .= '&param_'.urlencode($name).'='.urlencode((string) $value);
        }

        try {
            $response = Http::timeout((int) config('xdr.infrastructure.clickhouse.timeout_seconds', 5))
                ->withBasicAuth(
                    (string) config('xdr.infrastructure.clickhouse.user'),
                    (string) config('xdr.infrastructure.clickhouse.password'),
                )
                ->withBody($sql, 'text/plain')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('clickhouse telemetry read request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('clickhouse telemetry read failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $lines = array_filter(explode("\n", trim($response->body())));
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line);
            if ($decoded !== null) {
                $rows[] = $decoded;
            }
        }

        return collect($rows);
    }
}
