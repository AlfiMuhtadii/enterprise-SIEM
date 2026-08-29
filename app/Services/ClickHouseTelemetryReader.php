<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ARCH-DB-SPLIT (read path): telemetry_events read sites migrated to
 * ClickHouse, deliberately scoped out of the 7+ total read sites
 * documented in REVIEW_BACKLOG.md — the 2 correlation detectors
 * (scripts/xdr_correlation_detector.py's detect_identity()/
 * detect_cloud_saas()) that feed real security_alerts remain Postgres-only
 * in every pass, since a silent output change there is a correctness
 * incident, not a dashboard showing slightly stale numbers.
 * DetectionBacktestService is safe to migrate despite reading the same
 * identity/cloud/saas telemetry_type range — it only ever writes to the
 * advisory-only detection_backtest_runs/detection_backtest_matches tables,
 * never to security_alerts/security_incidents.
 *
 * Every method returns a Collection of stdClass rows shaped identically to
 * what the equivalent `DB::table('telemetry_events')->get()` call already
 * returns, so the calling controllers'/services' downstream code needs
 * zero changes — only the caller's own branch on
 * xdr.infrastructure.clickhouse.telemetry_write_target decides which
 * source actually ran.
 *
 * Same wire conventions as ClickHouseArchiveSearchService: parameterized
 * HTTP query binding (`param_x` + `{x:Type}`), never string-interpolated
 * user input, and a null return on any failure so callers can fall back to
 * Postgres rather than show a broken page.
 *
 * TENANT-CLICKHOUSE-LEAK: hostTimeline()/domainBreakdown()/huntSearch()/
 * forensicHostEvents() accept an optional `?string $tenantId` and, when
 * given, add `AND tenant_id = {tenant_id:String}` — ClickHouse's
 * telemetry_events has carried `tenant_id` since it was created
 * (ARCH-DB-SPLIT write path), so this needed no schema change on this
 * side. `identityCloudSaasWindowPage()` deliberately has NO tenant parameter:
 * DetectionBacktestService is a global/admin-only replay tool with no
 * per-tenant concept in its own Postgres query either, so adding one only
 * here would make the two backends diverge for the same caller.
 * Known asymmetry, not a regression introduced here: Postgres's own
 * telemetry_events has no tenant_id column at all (registered in
 * TenantBoundaryService::UNISOLATED_TABLES) and cannot be scoped the same
 * way — under the default telemetry_write_target=postgres, these 4 read
 * paths remain exactly as unscoped as they always were; passing a tenantId
 * here only tightens the opt-in ClickHouse path, it never loosens Postgres.
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
    public function hostTimeline(string $hostId, Carbon $since, string $eventType, int $limit, ?string $tenantId = null): ?Collection
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
        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = {tenant_id:String}';
            $params['tenant_id'] = $tenantId;
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
    public function domainBreakdown(Carbon $since, array $telemetryTypes, ?string $tenantId = null): ?Collection
    {
        $placeholders = [];
        $params = ['since' => $since->format('Y-m-d H:i:s.u')];
        foreach (array_values($telemetryTypes) as $i => $type) {
            $key = "type_{$i}";
            $placeholders[] = "{{$key}:String}";
            $params[$key] = $type;
        }

        $conditions = ['ts >= {since:DateTime64}', 'telemetry_type IN ('.implode(',', $placeholders).')'];
        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = {tenant_id:String}';
            $params['tenant_id'] = $tenantId;
        }

        $sql = 'SELECT telemetry_type, count(*) as total FROM telemetry_events'
            .' WHERE '.implode(' AND ', $conditions)
            .' GROUP BY telemetry_type ORDER BY total DESC'
            .' FORMAT JSONEachRow';

        return $this->query($sql, $params);
    }

    /**
     * Mirrors SocHuntController::queryTelemetry()'s multi-filter free-text
     * search. ClickHouse's `payload` column is a plain `String` (not
     * jsonb), so the free-text domain filter needs no `::text` cast the
     * way Postgres's `payload::text ilike ?` does.
     *
     * @param  array{minutes:int,host_id:string,process:string,event_type:string,user:string,ip:string,domain:string}  $filters
     */
    public function huntSearch(array $filters, int $limit, ?string $tenantId = null): ?Collection
    {
        $conditions = ['ts >= {since:DateTime64}'];
        $params = ['since' => now()->subMinutes($filters['minutes'])->format('Y-m-d H:i:s.u')];

        foreach (['host_id' => 'host_id', 'process' => 'process_name', 'event_type' => 'event_type'] as $filterKey => $column) {
            if (($filters[$filterKey] ?? '') !== '') {
                $conditions[] = "{$column} ILIKE {{$filterKey}:String}";
                $params[$filterKey] = '%'.$filters[$filterKey].'%';
            }
        }
        if (($filters['user'] ?? '') !== '') {
            $conditions[] = 'user_name_hash ILIKE {user:String}';
            $params['user'] = '%'.$filters['user'].'%';
        }
        if (($filters['ip'] ?? '') !== '') {
            $conditions[] = '(src_ip = {ip_src:String} OR dst_ip = {ip_dst:String})';
            $params['ip_src'] = $filters['ip'];
            $params['ip_dst'] = $filters['ip'];
        }
        if (($filters['domain'] ?? '') !== '') {
            $conditions[] = 'payload ILIKE {domain:String}';
            $params['domain'] = '%'.$filters['domain'].'%';
        }
        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = {tenant_id:String}';
            $params['tenant_id'] = $tenantId;
        }

        $sql = 'SELECT ts, event_type, telemetry_type, host_id, process_name, src_ip, dst_ip, dst_port,'
            .' user_name_hash, domain, xdr_user, xdr_action, risk_score, event_source'
            .' FROM telemetry_events WHERE '.implode(' AND ', $conditions)
            .' ORDER BY ts DESC LIMIT '.max(1, $limit)
            .' FORMAT JSONEachRow';

        return $this->query($sql, $params);
    }

    /**
     * Mirrors SocForensicController::buildArtifact()'s telemetry bundle: a
     * simple exact-host_id lookup (or the full table when no host is set on
     * the job) — no other filters, no free text.
     */
    public function forensicHostEvents(?string $hostId, int $limit, ?string $tenantId = null): ?Collection
    {
        $conditions = [];
        $params = [];
        if ($hostId !== null && $hostId !== '') {
            $conditions[] = 'host_id = {host_id:String}';
            $params['host_id'] = $hostId;
        }
        if ($tenantId !== null) {
            $conditions[] = 'tenant_id = {tenant_id:String}';
            $params['tenant_id'] = $tenantId;
        }

        $sql = 'SELECT ts, event_type, telemetry_type, host_id, process_name, src_ip, dst_ip, dst_port, protocol,'
            .' user_name_hash, xdr_user, xdr_action, xdr_result, domain, file_hash, risk_score, event_source, cloud_account'
            .' FROM telemetry_events'
            .($conditions !== [] ? ' WHERE '.implode(' AND ', $conditions) : '')
            .' ORDER BY ts DESC LIMIT '.max(1, $limit)
            .' FORMAT JSONEachRow';

        return $this->query($sql, $params);
    }

    /**
     * PERF-BACKTEST-OOM: mirrors DetectionBacktestService::run()'s replay
     * window, paginated -- the fixed identity/cloud/saas telemetry_type set
     * is a hard-coded PHP constant, not user input, so it's inlined
     * directly rather than parameterized, same convention as this class's
     * other hard-coded SQL keywords. `ORDER BY ts ASC, event_id ASC` gives
     * a stable sort (ts alone is not guaranteed unique under real
     * telemetry volume) so LIMIT/OFFSET pages never skip or repeat a row
     * across page boundaries. event_id is selected so the caller can
     * detect a short/empty page (end of window) without a separate count
     * query.
     */
    public function identityCloudSaasWindowPage(Carbon $start, Carbon $end, int $limit, int $offset): ?Collection
    {
        $sql = 'SELECT ts, event_id, telemetry_type, event_type, host_id, xdr_user, source_ip, src_ip, risk_score, xdr_action, xdr_result, event_source, cloud_account'
            .' FROM telemetry_events'
            ." WHERE telemetry_type IN ('identity','cloud','saas') AND ts BETWEEN {start:DateTime64} AND {end:DateTime64}"
            .' ORDER BY ts ASC, event_id ASC'
            .' LIMIT {limit:UInt64} OFFSET {offset:UInt64}'
            .' FORMAT JSONEachRow';

        return $this->query($sql, [
            'start' => $start->format('Y-m-d H:i:s.u'),
            'end' => $end->format('Y-m-d H:i:s.u'),
            'limit' => $limit,
            'offset' => $offset,
        ]);
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
            $response = ClickHouseHttpClient::request($url)
                ->withBody($sql, 'text/plain')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('clickhouse telemetry read request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
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
